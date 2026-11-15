<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\structure;

use pocketmine\block\Block;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\EntityFactory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Server;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\redstone\RedstoneEngine;
use pocketmine\world\World;
use function ceil;
use function count;
use function hrtime;
use function intdiv;
use function max;
use const PHP_INT_MAX;

/**
 * Loads a structure into a world over as many ticks as needed, so that big structures (up to 64x384x64 blocks) don't
 * stall the server: first the blocks from the bottom up (with their liquid layer and block entity data, without
 * updates); then the light, neighbour, comparator and observer updates of the blocks placed (like vanilla, the whole
 * structure is placed before it is updated, so blocks attached to supports placed later don't drop); then the block of
 * the owner (the structure block loading the structure), if the structure covers it; then the entities. Animated loads
 * (vanilla "place by layer" and "place by block") spread the blocks evenly over the animation time and update them as
 * they go. The chunks of the area and of the owner stay loaded until the task is done.
 *
 * All the loads running share one budget per server tick: WORK_PER_TICK (placing a block costs 1, updating it
 * UPDATE_COST) and MAX_NANOSECONDS_PER_TICK. An animation that doesn't fit in it takes longer, as when the server lags.
 *
 * The owner calls {@link StructurePlacementTask::run()} once per tick until it returns true.
 *
 * @internal
 */
final class StructurePlacementTask implements ChunkLoader{
	/** Work all the structure loads together may do in a server tick (placing a block costs 1) */
	public const WORK_PER_TICK = 1024;
	/** Work of updating a placed block (its neighbour updates run later in the tick and cost about as much again) */
	public const UPDATE_COST = 2;
	/** Time all the structure loads together may spend placing or updating in a server tick, in nanoseconds */
	public const MAX_NANOSECONDS_PER_TICK = 10_000_000;
	/** A budget started longer ago than this belongs to an earlier tick (a request handled after the world ticked) */
	private const BUDGET_WINDOW_NANOSECONDS = 50_000_000;

	private const PHASE_BLOCKS = 0;
	private const PHASE_UPDATES = 1;
	private const PHASE_OWNER = 2;
	private const PHASE_ENTITIES = 3;
	private const PHASE_DONE = 4;

	/** @phpstan-var \WeakReference<Server>|null */
	private static ?\WeakReference $budgetServer = null;
	private static int $budgetTick = -1;
	private static int $budgetStart = 0;
	private static int $budgetDeadline = 0;
	private static int $budgetWork = 0;

	private int $phase = self::PHASE_BLOCKS;
	/** @phpstan-var \Generator<int, array{int, int, int, int, int|null, CompoundTag|null}, void, void> */
	private \Generator $blocks;
	/**
	 * Blocks placed and not updated yet: their index in the area (see {@link StructurePlacementTask::placeBlock()})
	 * shifted left by one, with the lowest bit set when comparators may read the block.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $toUpdate = [];
	private int $updateCursor = 0;
	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $loadedChunks = [];
	private bool $running = false;
	private bool $finishRequested = false;
	/** @phpstan-var (\Closure() : void)|null */
	private ?\Closure $unloadCallback = null;

	private bool $animated;
	private bool $byLayer;
	/** Blocks placed per tick by this task (layers when animated by layer) */
	private int $perTick;

	private int $minX;
	private int $minY;
	private int $minZ;
	private int $sizeX;
	private int $sizeZ;

	private bool $hasOwner;
	private int $ownerX = 0;
	private int $ownerY = 0;
	private int $ownerZ = 0;
	/**
	 * The block of the structure that replaces the owner, placed after all the others: replacing the owner ends it.
	 * @phpstan-var array{int, int, int, int, int|null, CompoundTag|null}|null
	 */
	private ?array $ownerEntry = null;

	/**
	 * @param Vector3|null $owner position of the block driving the task, if any
	 */
	public function __construct(
		private World $world,
		private StructurePlacer $placer,
		?Vector3 $owner = null
	){
		$this->blocks = $placer->blocks();
		$settings = $placer->getSettings();
		$this->animated = $settings->isAnimated();
		$this->byLayer = $this->animated && $settings->animationMode === StructurePlaceSettings::ANIMATION_BY_LAYER;
		$ticks = max(1, (int) ceil($settings->animationSeconds * 20));
		$this->perTick = match(true){
			!$this->animated => PHP_INT_MAX,
			$this->byLayer => max(1, (int) ceil($placer->getSizeY() / $ticks)),
			default => max(1, (int) ceil($placer->countBlocks() / $ticks))
		};
		$this->minX = $placer->getMinX();
		$this->minY = $placer->getMinY();
		$this->minZ = $placer->getMinZ();
		$this->sizeX = max(1, $placer->getSizeX());
		$this->sizeZ = max(1, $placer->getSizeZ());
		$this->hasOwner = $owner !== null;
		if($owner !== null){
			$this->ownerX = $owner->getFloorX();
			$this->ownerY = $owner->getFloorY();
			$this->ownerZ = $owner->getFloorZ();
		}
	}

	public function getPlacer() : StructurePlacer{ return $this->placer; }

	public function isDone() : bool{ return $this->phase === self::PHASE_DONE; }

	/**
	 * Loads the chunks of the area and keeps them (and the owner's) loaded. Returns false (keeping nothing loaded) if
	 * some of them are not generated.
	 */
	public function prepare() : bool{
		$minChunkX = $this->minX >> Chunk::COORD_BIT_SIZE;
		$maxChunkX = ($this->minX + $this->sizeX - 1) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = $this->minZ >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ($this->minZ + $this->sizeZ - 1) >> Chunk::COORD_BIT_SIZE;
		$chunks = [];
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunks[World::chunkHash($chunkX, $chunkZ)] = [$chunkX, $chunkZ];
			}
		}
		if($this->hasOwner){
			//the owner's chunk unloading would close the owner, which finishes the task at once
			$chunks[World::chunkHash($this->ownerX >> Chunk::COORD_BIT_SIZE, $this->ownerZ >> Chunk::COORD_BIT_SIZE)] = [$this->ownerX >> Chunk::COORD_BIT_SIZE, $this->ownerZ >> Chunk::COORD_BIT_SIZE];
		}
		foreach($chunks as [$chunkX, $chunkZ]){
			if($this->world->loadChunk($chunkX, $chunkZ) === null){
				return false;
			}
		}
		foreach($chunks as $hash => [$chunkX, $chunkZ]){
			$this->world->registerChunkLoader($this, $chunkX, $chunkZ);
			$this->loadedChunks[$hash] = $hash;
		}
		//a world being unloaded stops the task where it is
		$this->unloadCallback = function() : void{
			$this->cancel();
		};
		$this->world->addOnUnloadCallback($this->unloadCallback);
		return true;
	}

	/**
	 * Does the work of one tick. Returns true when the structure is completely loaded (or the world was unloaded).
	 */
	public function run() : bool{
		if($this->phase === self::PHASE_DONE){
			return true;
		}
		if($this->running){
			return false;
		}
		if(!$this->world->isLoaded()){
			$this->cancel();
			return true;
		}
		$this->running = true;
		try{
			if(!$this->finishRequested){
				$this->joinBudget();
			}
			do{
				$limited = !$this->finishRequested;
				if($this->phase === self::PHASE_BLOCKS){
					if($this->animated){
						//the blocks placed in the previous ticks are updated before more are placed
						$this->updateBlocks($limited);
					}
					if(!$this->placeBlocks($limited)){
						continue;
					}
					$this->phase = self::PHASE_UPDATES;
				}
				if($this->phase === self::PHASE_UPDATES){
					if(!$this->updateBlocks($limited)){
						continue;
					}
					$this->phase = self::PHASE_OWNER;
				}
				if($this->phase === self::PHASE_OWNER){
					//replacing the owner closes it, which asks to finish: only the entities are left by then
					$this->phase = self::PHASE_ENTITIES;
					$this->placeOwnerBlock();
				}
				if($this->phase === self::PHASE_ENTITIES){
					$this->spawnEntities();
					$this->phase = self::PHASE_DONE;
				}
			}while($this->finishRequested && $this->phase !== self::PHASE_DONE);
		}finally{
			$this->running = false;
			//World::setBlockAt() counts every placement as a block leaving the block cache, cached or not: after
			//thousands of placements the cache stops trimming itself and the updates fill it with every block of the
			//structure (hundreds of MB). Counting it again keeps it within its cap.
			$this->world->clearCache();
		}
		if($this->phase === self::PHASE_DONE){
			$this->releaseChunks();
			return true;
		}
		return false;
	}

	/**
	 * Does all the remaining work now, ignoring the budget (when the owner of the task goes away before the end). If
	 * the task is running, it finishes before returning from run().
	 */
	public function finish() : void{
		$this->finishRequested = true;
		if(!$this->running){
			$this->run();
		}
	}

	/**
	 * Stops the task where it is.
	 */
	public function cancel() : void{
		$this->phase = self::PHASE_DONE;
		$this->releaseChunks();
	}

	private function releaseChunks() : void{
		if($this->world->isLoaded()){
			foreach($this->loadedChunks as $hash){
				World::getXZ($hash, $chunkX, $chunkZ);
				$this->world->unregisterChunkLoader($this, $chunkX, $chunkZ);
			}
			if($this->unloadCallback !== null){
				$this->world->removeOnUnloadCallback($this->unloadCallback);
			}
		}
		$this->loadedChunks = [];
		$this->unloadCallback = null;
	}

	/**
	 * Starts the budget of this server tick, or joins it if another load already started it.
	 */
	private function joinBudget() : void{
		$server = $this->world->getServer();
		$tick = $server->getTick();
		$now = hrtime(true);
		if($tick !== self::$budgetTick || $now - self::$budgetStart > self::BUDGET_WINDOW_NANOSECONDS || self::$budgetServer?->get() !== $server){
			self::$budgetServer = \WeakReference::create($server);
			self::$budgetTick = $tick;
			self::$budgetStart = $now;
			self::$budgetDeadline = $now + self::MAX_NANOSECONDS_PER_TICK;
			self::$budgetWork = 0;
		}
	}

	/**
	 * Places blocks until all are placed (returns true), or (when limited) until the budget of the tick or the
	 * animation's share of this tick is used up.
	 */
	private function placeBlocks(bool $limited) : bool{
		$generator = $this->blocks;
		$placed = 0;
		$checks = 0;
		$currentLayer = null;
		$layers = 0;
		while($generator->valid()){
			if($limited){
				if(self::$budgetWork >= self::WORK_PER_TICK || (($checks++ & 63) === 0 && hrtime(true) > self::$budgetDeadline)){
					return false;
				}
				if($this->byLayer){
					$layer = $generator->key();
					if($layer !== $currentLayer){
						if($currentLayer !== null && ++$layers >= $this->perTick){
							return false;
						}
						$currentLayer = $layer;
					}
				}elseif($placed >= $this->perTick){
					return false;
				}
			}
			$entry = $generator->current();
			$generator->next();
			if($this->hasOwner && $entry[0] === $this->ownerX && $entry[1] === $this->ownerY && $entry[2] === $this->ownerZ){
				$this->ownerEntry = $entry;
				continue;
			}
			if($this->placeBlock(...$entry)){
				++$placed;
				++self::$budgetWork;
			}
		}
		return true;
	}

	/**
	 * Places a block of the structure without updates, and remembers it for updating. Returns false if it is outside
	 * the world.
	 */
	private function placeBlock(int $x, int $y, int $z, int $paletteIndex, ?int $liquidIndex, ?CompoundTag $blockEntity) : bool{
		$world = $this->world;
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$block = $this->placer->getBlock($paletteIndex);
		//vanilla replaces the block entity there, so that the new block doesn't get the old one's contents
		$world->getTileAt($x, $y, $z)?->close();
		$world->setBlockAt($x, $y, $z, $block, false);

		//the liquid layer is the template's (the water of waterlogged blocks), whatever was there before
		$subChunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)?->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		if($subChunk !== null){
			$liquidStateId = $liquidIndex !== null ? $this->placer->getBlock($liquidIndex)->getStateId() : Block::EMPTY_STATE_ID;
			$lx = $x & SubChunk::COORD_MASK;
			$ly = $y & SubChunk::COORD_MASK;
			$lz = $z & SubChunk::COORD_MASK;
			if($subChunk->getLiquidLayerStateId($lx, $ly, $lz) !== $liquidStateId){
				$subChunk->setLiquidLayerStateId($lx, $ly, $lz, $liquidStateId);
			}
		}

		if($blockEntity !== null){
			$this->loadBlockEntity($x, $y, $z, $block, $blockEntity);
		}
		//one int per block: a list of arrays would take about 10 times the memory for the biggest structures
		$index = (($y - $this->minY) * $this->sizeX + ($x - $this->minX)) * $this->sizeZ + ($z - $this->minZ);
		$this->toUpdate[] = ($index << 1) | ($block instanceof ComparatorReadable ? 1 : 0);
		return true;
	}

	/**
	 * Replaces the new block entity of a placed block by one made from the template's data (if it is of the class the
	 * block expects; otherwise the default one stays).
	 */
	private function loadBlockEntity(int $x, int $y, int $z, Block $block, CompoundTag $nbt) : void{
		$expected = $block->getIdInfo()->getTileClass();
		if($expected === null){
			return;
		}
		try{
			$tile = TileFactory::getInstance()->createFromData($this->world, $nbt);
		}catch(SavedDataLoadingException){
			return;
		}
		if($tile === null || !$tile instanceof $expected){
			return;
		}
		$this->world->getTileAt($x, $y, $z)?->close();
		$this->world->addTile($tile);
	}

	/**
	 * Runs the updates that World::setBlockAt() skipped for the placed blocks, until all are updated (returns true),
	 * or (when limited) until the budget of the tick is used up.
	 */
	private function updateBlocks(bool $limited) : bool{
		$world = $this->world;
		$redstone = $world->getRedstone();
		$count = count($this->toUpdate);
		$checks = 0;
		$i = $this->updateCursor;
		for(; $i < $count; ++$i){
			if($limited){
				if(self::$budgetWork + self::UPDATE_COST > self::WORK_PER_TICK || (($checks++ & 63) === 0 && hrtime(true) > self::$budgetDeadline)){
					break;
				}
				self::$budgetWork += self::UPDATE_COST;
			}
			$packed = $this->toUpdate[$i];
			$index = $packed >> 1;
			$z = $this->minZ + $index % $this->sizeZ;
			$index = intdiv($index, $this->sizeZ);
			$this->updateBlock($world, $redstone, $this->minX + $index % $this->sizeX, $this->minY + intdiv($index, $this->sizeX), $z, ($packed & 1) !== 0);
		}
		if($i >= $count){
			$this->toUpdate = [];
			$this->updateCursor = 0;
			return true;
		}
		$this->updateCursor = $i;
		return false;
	}

	private function updateBlock(World $world, ?RedstoneEngine $redstone, int $x, int $y, int $z, bool $comparatorReadable) : void{
		$world->updateAllLight($x, $y, $z);
		$world->notifyNeighbourBlockUpdateAt($x, $y, $z);
		if($redstone !== null){
			$redstone->notifyObservers($x, $y, $z);
			if($comparatorReadable){
				$redstone->updateComparatorsReading($x, $y, $z);
			}
		}
	}

	/**
	 * Places and updates the block of the structure that replaces the owner.
	 */
	private function placeOwnerBlock() : void{
		$entry = $this->ownerEntry;
		$this->ownerEntry = null;
		if($entry !== null && $this->placeBlock(...$entry)){
			$this->updateBlocks(false);
		}
	}

	private function spawnEntities() : void{
		$entities = $this->placer->entities();
		if(count($entities) === 0){
			return;
		}
		$factory = EntityFactory::getInstance();
		foreach($entities as $nbt){
			try{
				$entity = $factory->createFromData($this->world, $nbt);
			}catch(SavedDataLoadingException){
				continue;
			}
			$entity?->spawnToAll();
		}
	}
}
