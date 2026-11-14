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

namespace pocketmine\world\redstone;

use pocketmine\block\Observer;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneWire;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\ComparatorReadableContainer;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_fill;
use function count;
use function hrtime;
use function is_subclass_of;
use const PHP_INT_MIN;

/**
 * Per-world redstone power engine.
 *
 * Power model: every block may emit weak and strong power out of each of its faces (see
 * {@link \pocketmine\block\Block::getWeakPower()} and {@link \pocketmine\block\Block::getStrongPower()}). A redstone
 * conductor (a full solid block) which receives strong power gives that power to all of its neighbours.
 *
 * Components react to power changes from {@link \pocketmine\block\Block::onNearbyBlockChange()}; delays (repeaters,
 * torches, comparators) use the world's scheduled block updates.
 *
 * Redstone wire is solved per network: wires only mark themselves dirty when a neighbour changes, and the engine
 * recomputes each dirty network once after the world's neighbour updates have been processed, so a change never
 * recurses wire by wire. The number of wire blocks recomputed per tick is capped; extra work waits for the next tick.
 */
final class RedstoneEngine{
	/** Number of game ticks in one redstone tick. */
	public const REDSTONE_TICK = 2;

	/** Maximum number of wire flush rounds (wire solve + resulting neighbour updates) per world tick. */
	public const MAX_FLUSH_ROUNDS_PER_TICK = 16;

	/** Networks bigger than this are truncated to protect the server from absurd circuits. */
	public const MAX_WIRE_NETWORK_SIZE = 65536;

	public const TORCH_BURNOUT_TOGGLES = 8;
	public const TORCH_BURNOUT_WINDOW_TICKS = 60;
	public const TORCH_BURNOUT_COOLDOWN_TICKS = 160;

	/** Number of rails a powered or activator rail passes power on to. */
	public const RAIL_POWER_RANGE = 8;

	/**
	 * @var true[] world block hash => true
	 * @phpstan-var array<int, true>
	 */
	private array $dirtyWires = [];

	/**
	 * Remembered "powered" flags of components whose Bedrock block state has no powered property (doors, note blocks,
	 * fence gates...), used to only react on power edges.
	 *
	 * @var true[][] chunk hash => chunk block hash => true
	 * @phpstan-var array<int, array<int, true>>
	 */
	private array $poweredMemory = [];

	/**
	 * Transient power output of blocks without a block state for it (target block).
	 *
	 * @var int[][] chunk hash => chunk block hash => power
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $transientPower = [];

	/**
	 * @var int[][] world block hash => list of ticks at which the torch was turned off
	 * @phpstan-var array<int, list<int>>
	 */
	private array $torchTurnOffs = [];

	/**
	 * @var int[] world block hash => tick at which the burnout ends
	 * @phpstan-var array<int, int>
	 */
	private array $burnedOutTorches = [];

	/**
	 * @var true[] world block hash => true
	 * @phpstan-var array<int, true>
	 */
	private array $observerRetriggers = [];

	private int $currentTick = 0;
	private int $wireUpdatesThisTick = 0;

	private int $statWireBlocksSolved = 0;
	private int $statNetworksSolved = 0;
	private int $statSolveTimeNs = 0;

	private bool $solving = false;
	private ?SubChunk $lastSubChunk = null;
	private int $lastSubChunkX = PHP_INT_MIN;
	private int $lastSubChunkY = PHP_INT_MIN;
	private int $lastSubChunkZ = PHP_INT_MIN;

	private int $minY;
	private int $maxY;
	private int $airStateId;

	public function __construct(
		private World $world,
		private int $maxWireUpdatesPerTick = 20000
	){
		$this->minY = $world->getMinY();
		$this->maxY = $world->getMaxY();
		$this->airStateId = VanillaBlocks::AIR()->getStateId();
	}

	public function getWorld() : World{ return $this->world; }

	public function getCurrentTick() : int{ return $this->currentTick; }

	public function getMaxWireUpdatesPerTick() : int{ return $this->maxWireUpdatesPerTick; }

	public function setMaxWireUpdatesPerTick(int $max) : void{
		$this->maxWireUpdatesPerTick = $max;
	}

	/**
	 * @internal Called by the world at the start of each tick.
	 */
	public function beginTick(int $currentTick) : void{
		$this->currentTick = $currentTick;
		$this->wireUpdatesThisTick = 0;
		if(($currentTick % 1200) === 0){
			$this->pruneTorchHistory();
		}
	}

	/**
	 * @internal
	 */
	public function onChunkUnloaded(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		unset($this->poweredMemory[$chunkHash], $this->transientPower[$chunkHash]);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Power queries
	// ---------------------------------------------------------------------------------------------------------------

	private const FLAG_WIRE = 1;
	private const FLAG_CONDUCTOR = 2;
	private const FLAG_SOURCE = 4;
	private const FLAG_OBSERVER = 8;
	/** Blocks a comparator reads (ComparatorReadable blocks and blocks with a ComparatorReadableContainer tile). */
	private const FLAG_COMPARATOR_READABLE = 16;
	private const FLAG_COMPARATOR = 32;

	/** x and z offsets of the four horizontal sides. */
	private const HORIZONTAL_OFFSETS = [[0, -1], [0, 1], [-1, 0], [1, 0]];

	/**
	 * @var int[] observer state ID => face the observer watches
	 * @phpstan-var array<int, int>
	 */
	private static array $observerFacing = [];

	/**
	 * Redstone properties of each block state, so that the hot paths don't need Block objects.
	 *
	 * @var int[] state ID => FLAG_* bitmask
	 * @phpstan-var array<int, int>
	 */
	private static array $stateFlags = [];

	/**
	 * @var int[][] wire state ID => power level => wire state ID with that power level
	 * @phpstan-var array<int, array<int, int>>
	 */
	private static array $wireStateByLevel = [];

	private static function flagsOf(int $stateId) : int{
		if(isset(self::$stateFlags[$stateId])){
			return self::$stateFlags[$stateId];
		}
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		$flags = 0;
		if($block instanceof RedstoneWire){
			$flags |= self::FLAG_WIRE | self::FLAG_SOURCE;
			$levels = [];
			for($level = 0; $level <= 15; ++$level){
				$levels[$level] = (clone $block)->setOutputSignalStrength($level)->getStateId();
			}
			self::$wireStateByLevel[$stateId] = $levels;
		}elseif($block->isRedstoneSource()){
			$flags |= self::FLAG_SOURCE;
		}
		if($block->isRedstoneConductor()){
			$flags |= self::FLAG_CONDUCTOR;
		}
		if($block instanceof Observer){
			$flags |= self::FLAG_OBSERVER;
			self::$observerFacing[$stateId] = $block->getFacing();
		}
		if($block instanceof RedstoneComparator){
			$flags |= self::FLAG_COMPARATOR;
		}
		if($block instanceof ComparatorReadable){
			$flags |= self::FLAG_COMPARATOR_READABLE;
		}else{
			$tileClass = $block->getIdInfo()->getTileClass();
			if($tileClass !== null && is_subclass_of($tileClass, ComparatorReadableContainer::class)){
				$flags |= self::FLAG_COMPARATOR_READABLE;
			}
		}
		return self::$stateFlags[$stateId] = $flags;
	}

	/**
	 * Returns the block state ID at the given position, or air if it is outside the world or in an unloaded chunk.
	 */
	private function stateIdAt(int $x, int $y, int $z) : int{
		if($y < $this->minY || $y >= $this->maxY){
			return $this->airStateId;
		}
		if($this->solving){
			//while solving a network, nothing replaces chunks or subchunks: remember the last subchunk used
			$cx = $x >> SubChunk::COORD_BIT_SIZE;
			$cy = $y >> SubChunk::COORD_BIT_SIZE;
			$cz = $z >> SubChunk::COORD_BIT_SIZE;
			if($cx !== $this->lastSubChunkX || $cy !== $this->lastSubChunkY || $cz !== $this->lastSubChunkZ){
				$chunk = $this->world->getChunk($cx, $cz);
				$this->lastSubChunk = $chunk?->getSubChunk($cy);
				$this->lastSubChunkX = $cx;
				$this->lastSubChunkY = $cy;
				$this->lastSubChunkZ = $cz;
			}
			return $this->lastSubChunk === null ? $this->airStateId : $this->lastSubChunk->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		}
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		return $chunk === null ? $this->airStateId : $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
	}

	/**
	 * Returns whether the block at the given position is a redstone conductor.
	 */
	public function isConductorAt(int $x, int $y, int $z) : bool{
		return (self::flagsOf($this->stateIdAt($x, $y, $z)) & self::FLAG_CONDUCTOR) !== 0;
	}

	/**
	 * Returns whether the block at the given position is redstone wire.
	 */
	public function isWireAt(int $x, int $y, int $z) : bool{
		return (self::flagsOf($this->stateIdAt($x, $y, $z)) & self::FLAG_WIRE) !== 0;
	}

	/**
	 * Returns the power that reaches the block at x/y/z from its neighbour on the given face: the neighbour's weak
	 * power towards this block, or, if the neighbour is a conductor, the strong power the neighbour receives.
	 *
	 * Only blocks which declare themselves as power sources ({@link \pocketmine\block\Block::isRedstoneSource()}) are
	 * asked for their power.
	 *
	 * @param bool $ignoreWires whether to ignore power coming from redstone wire (directly or through a conductor)
	 */
	public function getPowerFrom(int $x, int $y, int $z, int $face, bool $ignoreWires = false) : int{
		[$dx, $dy, $dz] = Facing::OFFSET[$face];
		$nx = $x + $dx;
		$ny = $y + $dy;
		$nz = $z + $dz;
		$flags = self::flagsOf($this->stateIdAt($nx, $ny, $nz));
		$power = 0;
		if(($flags & self::FLAG_SOURCE) !== 0){
			if($ignoreWires && ($flags & self::FLAG_WIRE) !== 0){
				return 0;
			}
			$power = $this->world->getBlockAt($nx, $ny, $nz)->getWeakPower(Facing::opposite($face));
		}
		if($power < 15 && ($flags & self::FLAG_CONDUCTOR) !== 0){
			$strong = $this->getStrongPowerInto($nx, $ny, $nz, $ignoreWires);
			if($strong > $power){
				$power = $strong;
			}
		}
		return $power;
	}

	/**
	 * Returns the highest strong power delivered into the block at x/y/z by any of its neighbours.
	 */
	public function getStrongPowerInto(int $x, int $y, int $z, bool $ignoreWires = false) : int{
		$max = 0;
		foreach(Facing::OFFSET as $face => [$dx, $dy, $dz]){
			$nx = $x + $dx;
			$ny = $y + $dy;
			$nz = $z + $dz;
			$flags = self::flagsOf($this->stateIdAt($nx, $ny, $nz));
			if(($flags & self::FLAG_SOURCE) === 0 || ($ignoreWires && ($flags & self::FLAG_WIRE) !== 0)){
				continue;
			}
			$power = $this->world->getBlockAt($nx, $ny, $nz)->getStrongPower(Facing::opposite($face));
			if($power > $max){
				if($power >= 15){
					return 15;
				}
				$max = $power;
			}
		}
		return $max;
	}

	/**
	 * Returns the highest power received by the block at x/y/z from any side.
	 */
	public function getPower(int $x, int $y, int $z) : int{
		$max = 0;
		foreach(Facing::ALL as $face){
			$power = $this->getPowerFrom($x, $y, $z, $face);
			if($power > $max){
				if($power >= 15){
					return 15;
				}
				$max = $power;
			}
		}
		return $max;
	}

	/**
	 * Returns whether the block at x/y/z receives any power from any side.
	 */
	public function isPowered(int $x, int $y, int $z) : bool{
		foreach(Facing::ALL as $face){
			if($this->getPowerFrom($x, $y, $z, $face) > 0){
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Update helpers
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Queues neighbour updates for the block at x/y/z and its six neighbours. Used by components whose output strongly
	 * powers a block, so that the components around that block notice the change.
	 */
	public function updateAround(int $x, int $y, int $z) : void{
		$this->world->notifyNeighbourBlockUpdateAt($x, $y, $z);
	}

	/**
	 * Same as {@link RedstoneEngine::updateAround()}, for the block on the given side of the given position.
	 */
	public function updateAroundSide(Vector3 $pos, int $face) : void{
		[$dx, $dy, $dz] = Facing::OFFSET[$face];
		$this->world->notifyNeighbourBlockUpdateAt($pos->getFloorX() + $dx, $pos->getFloorY() + $dy, $pos->getFloorZ() + $dz);
	}

	/**
	 * Returns whether a comparator can read the given block state (see {@link RedstoneComparator::readComparatorOutput()}).
	 */
	public static function isComparatorReadableState(int $stateId) : bool{
		return (self::flagsOf($stateId) & self::FLAG_COMPARATOR_READABLE) !== 0;
	}

	/**
	 * @internal Called by {@link World::setBlockAt()} for every block set with updates. When the old or the new block
	 * can be read by a comparator, the comparators reading it (also through a conductor, which normal neighbour
	 * updates don't reach) are updated, like vanilla's updateNeighbourForOutputSignal(). The block may be set with the
	 * same state ID when only its tile data changed (item frame rotation, lectern page...).
	 */
	public function onBlockSet(int $x, int $y, int $z, int $oldStateId, int $newStateId) : void{
		if(((self::flagsOf($oldStateId) | self::flagsOf($newStateId)) & self::FLAG_COMPARATOR_READABLE) !== 0){
			$this->updateComparatorsReading($x, $y, $z);
		}
	}

	/**
	 * Queues neighbour updates for the comparators that read the block at x/y/z, directly or through a conductor.
	 */
	public function updateComparatorsReading(int $x, int $y, int $z) : void{
		if($y < $this->minY || $y >= $this->maxY){
			return;
		}
		$world = $this->world;
		//all the blocks looked at are within 2 blocks: read them from this block's subchunk while they are in it
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$subChunk = $world->getChunk($chunkX, $chunkZ)?->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$ly = $y & SubChunk::COORD_MASK;
		foreach(self::HORIZONTAL_OFFSETS as [$dx, $dz]){
			for($distance = 1; $distance <= 2; ++$distance){
				$nx = $x + $dx * $distance;
				$nz = $z + $dz * $distance;
				$stateId = $subChunk !== null && ($nx >> Chunk::COORD_BIT_SIZE) === $chunkX && ($nz >> Chunk::COORD_BIT_SIZE) === $chunkZ ?
					$subChunk->getBlockStateId($nx & SubChunk::COORD_MASK, $ly, $nz & SubChunk::COORD_MASK) :
					$this->stateIdAt($nx, $y, $nz);
				$flags = self::flagsOf($stateId);
				if(($flags & self::FLAG_COMPARATOR) !== 0){
					$world->scheduleNeighbourBlockUpdateAt($nx, $y, $nz);
					break;
				}
				if(($flags & self::FLAG_CONDUCTOR) === 0){
					break; //comparators only read through a conductor
				}
			}
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Transient state
	// ---------------------------------------------------------------------------------------------------------------

	public function getRememberedPowered(int $x, int $y, int $z) : bool{
		return isset($this->poweredMemory[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)]);
	}

	public function setRememberedPowered(int $x, int $y, int $z, bool $powered) : void{
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$blockHash = World::chunkBlockHash($x, $y, $z);
		if($powered){
			$this->poweredMemory[$chunkHash][$blockHash] = true;
		}else{
			unset($this->poweredMemory[$chunkHash][$blockHash]);
			if(isset($this->poweredMemory[$chunkHash]) && count($this->poweredMemory[$chunkHash]) === 0){
				unset($this->poweredMemory[$chunkHash]);
			}
		}
	}

	public function getTransientPower(int $x, int $y, int $z) : int{
		return $this->transientPower[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)] ?? 0;
	}

	public function setTransientPower(int $x, int $y, int $z, int $power) : void{
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$blockHash = World::chunkBlockHash($x, $y, $z);
		if($power > 0){
			$this->transientPower[$chunkHash][$blockHash] = $power;
		}else{
			unset($this->transientPower[$chunkHash][$blockHash]);
			if(isset($this->transientPower[$chunkHash]) && count($this->transientPower[$chunkHash]) === 0){
				unset($this->transientPower[$chunkHash]);
			}
		}
	}

	/**
	 * Records that the torch at x/y/z was turned off by its input. Returns true if the torch toggled too often and
	 * must burn out.
	 */
	public function recordTorchTurnOff(int $x, int $y, int $z) : bool{
		$hash = World::blockHash($x, $y, $z);
		$history = [];
		$minTick = $this->currentTick - self::TORCH_BURNOUT_WINDOW_TICKS;
		foreach($this->torchTurnOffs[$hash] ?? [] as $tick){
			if($tick > $minTick){
				$history[] = $tick;
			}
		}
		$history[] = $this->currentTick;
		$this->torchTurnOffs[$hash] = $history;
		if(count($history) >= self::TORCH_BURNOUT_TOGGLES){
			$this->burnedOutTorches[$hash] = $this->currentTick + self::TORCH_BURNOUT_COOLDOWN_TICKS;
			unset($this->torchTurnOffs[$hash]);
			return true;
		}
		return false;
	}

	/**
	 * Returns whether the torch at x/y/z is burned out. Once the cooldown is over the burnout is forgotten, and the
	 * torch relights on its next update.
	 */
	public function isTorchBurnedOut(int $x, int $y, int $z) : bool{
		$hash = World::blockHash($x, $y, $z);
		if(!isset($this->burnedOutTorches[$hash])){
			return false;
		}
		if($this->burnedOutTorches[$hash] <= $this->currentTick){
			unset($this->burnedOutTorches[$hash]);
			return false;
		}
		return true;
	}

	public function clearTorchState(int $x, int $y, int $z) : void{
		$hash = World::blockHash($x, $y, $z);
		unset($this->torchTurnOffs[$hash], $this->burnedOutTorches[$hash]);
	}

	private function pruneTorchHistory() : void{
		$minTick = $this->currentTick - self::TORCH_BURNOUT_WINDOW_TICKS;
		foreach($this->torchTurnOffs as $hash => $history){
			if($history[count($history) - 1] <= $minTick){
				unset($this->torchTurnOffs[$hash]);
			}
		}
		foreach($this->burnedOutTorches as $hash => $until){
			if($until <= $this->currentTick){
				unset($this->burnedOutTorches[$hash]);
			}
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Redstone wire
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Marks the wire at x/y/z as needing its network to be recalculated.
	 */
	public function markWireDirty(int $x, int $y, int $z) : void{
		$this->dirtyWires[World::blockHash($x, $y, $z)] = true;
	}

	public function hasDirtyWires() : bool{
		return count($this->dirtyWires) > 0;
	}

	/**
	 * Recalculates every dirty wire network once. Networks that don't fit in this tick's budget stay dirty.
	 *
	 * @return bool whether any network was recalculated
	 *
	 * @internal Called by the world after neighbour block updates have been processed.
	 */
	public function flushWires() : bool{
		if(count($this->dirtyWires) === 0){
			return false;
		}
		$start = hrtime(true);
		$dirty = $this->dirtyWires;
		$this->dirtyWires = [];
		$visited = [];
		$didWork = false;
		foreach($dirty as $hash => $_){
			if(isset($visited[$hash])){
				continue;
			}
			if($this->wireUpdatesThisTick >= $this->maxWireUpdatesPerTick){
				$this->dirtyWires[$hash] = true; //defer to the next tick
				continue;
			}
			World::getBlockXYZ($hash, $x, $y, $z);
			if(!$this->world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
				continue;
			}
			$stateId = $this->stateIdAt($x, $y, $z);
			if((self::flagsOf($stateId) & self::FLAG_WIRE) === 0){
				continue;
			}
			$this->solving = true;
			$this->lastSubChunkX = PHP_INT_MIN;
			try{
				$this->solveWireNetwork($x, $y, $z, $stateId, $visited);
			}finally{
				$this->solving = false;
				$this->lastSubChunk = null;
			}
			$didWork = true;
		}
		$this->statSolveTimeNs += hrtime(true) - $start;
		return $didWork;
	}

	/**
	 * Returns whether wire at x/y/z connects through the given horizontal face (to another wire, stepping up or down,
	 * or to a power source which accepts wire connections).
	 */
	public function wireConnects(int $x, int $y, int $z, int $face, ?bool $aboveIsConductor = null) : bool{
		[$dx, , $dz] = Facing::OFFSET[$face];
		$nx = $x + $dx;
		$nz = $z + $dz;
		$sideFlags = self::flagsOf($this->stateIdAt($nx, $y, $nz));
		if(($sideFlags & self::FLAG_WIRE) !== 0){
			return true;
		}
		if(($sideFlags & self::FLAG_SOURCE) !== 0 && $this->world->getBlockAt($nx, $y, $nz)->connectsToRedstoneWire(Facing::opposite($face))){
			return true;
		}
		$aboveIsConductor ??= $this->isConductorAt($x, $y + 1, $z);
		if(!$aboveIsConductor && $this->isWireAt($nx, $y + 1, $nz)){
			return true;
		}
		return ($sideFlags & self::FLAG_CONDUCTOR) === 0 && $this->isWireAt($nx, $y - 1, $nz);
	}

	/**
	 * Returns a bitmask of the horizontal faces (1 << face) that the wire at x/y/z powers. A wire with no connections
	 * (a dot) powers all four sides; a wire with a single connection powers both ends of its line.
	 */
	public function getWirePoweredFaces(int $x, int $y, int $z) : int{
		$aboveIsConductor = $this->isConductorAt($x, $y + 1, $z);
		$mask = 0;
		$count = 0;
		foreach(Facing::HORIZONTAL as $face){
			if($this->wireConnects($x, $y, $z, $face, $aboveIsConductor)){
				$mask |= 1 << $face;
				++$count;
			}
		}
		if($count === 0){
			foreach(Facing::HORIZONTAL as $face){
				$mask |= 1 << $face;
			}
		}elseif($count === 1){
			foreach(Facing::HORIZONTAL as $face){
				if(($mask & (1 << $face)) !== 0){
					$mask |= 1 << Facing::opposite($face);
					break;
				}
			}
		}
		return $mask;
	}

	/**
	 * Finds the wire network containing the wire at x/y/z, computes the power level of each wire and applies the
	 * changes, then notifies the blocks around the changed wires.
	 *
	 * @param true[] $visited
	 * @phpstan-param array<int, true> $visited
	 */
	private function solveWireNetwork(int $startX, int $startY, int $startZ, int $startStateId, array &$visited) : void{
		$world = $this->world;

		/** @phpstan-var list<array{int, int, int}> $nodes */
		$nodes = [[$startX, $startY, $startZ]];
		/** @phpstan-var list<int> $states */
		$states = [$startStateId];
		$index = [World::blockHash($startX, $startY, $startZ) => 0];
		/** @phpstan-var array<int, list<int>> $edges node => list of nodes it can power */
		$edges = [];
		/** @phpstan-var array<int, int> $skipFaces node => bitmask of faces which can't power it */
		$skipFaces = [];

		for($i = 0; $i < count($nodes); ++$i){
			[$x, $y, $z] = $nodes[$i];
			$out = [];
			$aboveFlags = self::flagsOf($this->stateIdAt($x, $y + 1, $z));
			$supportFlags = self::flagsOf($this->stateIdAt($x, $y - 1, $z));
			$aboveIsConductor = ($aboveFlags & self::FLAG_CONDUCTOR) !== 0;
			$supportIsConductor = ($supportFlags & self::FLAG_CONDUCTOR) !== 0;
			//faces which can't give power to this wire (wire, or neither a power source nor a conductor)
			$skip = 0;
			if(($aboveFlags & (self::FLAG_SOURCE | self::FLAG_CONDUCTOR)) === 0 || ($aboveFlags & self::FLAG_WIRE) !== 0){
				$skip |= 1 << Facing::UP;
			}
			if(($supportFlags & (self::FLAG_SOURCE | self::FLAG_CONDUCTOR)) === 0 || ($supportFlags & self::FLAG_WIRE) !== 0){
				$skip |= 1 << Facing::DOWN;
			}
			foreach(Facing::HORIZONTAL as $face){
				[$dx, , $dz] = Facing::OFFSET[$face];
				$nx = $x + $dx;
				$nz = $z + $dz;
				$sideId = $this->stateIdAt($nx, $y, $nz);
				$sideFlags = self::flagsOf($sideId);
				if(($sideFlags & (self::FLAG_SOURCE | self::FLAG_CONDUCTOR)) === 0 || ($sideFlags & self::FLAG_WIRE) !== 0){
					$skip |= 1 << $face;
				}
				/** @phpstan-var list<array{int, int, bool}> $connected y, state ID, whether this wire powers it */
				$connected = [];
				if(($sideFlags & self::FLAG_WIRE) !== 0){
					$connected[] = [$y, $sideId, true];
				}else{
					//wire one block higher, on top of the side block: power can always climb up
					if(!$aboveIsConductor){
						$upId = $this->stateIdAt($nx, $y + 1, $nz);
						if((self::flagsOf($upId) & self::FLAG_WIRE) !== 0){
							$connected[] = [$y + 1, $upId, true];
						}
					}
					//wire one block lower: power only goes down if this wire sits on a conductor (not on glass...)
					if(($sideFlags & self::FLAG_CONDUCTOR) === 0){
						$downId = $this->stateIdAt($nx, $y - 1, $nz);
						if((self::flagsOf($downId) & self::FLAG_WIRE) !== 0){
							$connected[] = [$y - 1, $downId, $supportIsConductor];
						}
					}
				}

				foreach($connected as [$ny, $neighbourId, $powers]){
					$hash = World::blockHash($nx, $ny, $nz);
					if(isset($index[$hash])){
						$j = $index[$hash];
					}else{
						$j = count($nodes);
						if($j >= self::MAX_WIRE_NETWORK_SIZE){
							continue;
						}
						$index[$hash] = $j;
						$nodes[] = [$nx, $ny, $nz];
						$states[] = $neighbourId;
					}
					if($powers){
						$out[] = $j;
					}
				}
			}
			$edges[$i] = $out;
			$skipFaces[$i] = $skip;
		}

		$nodeCount = count($nodes);
		$this->wireUpdatesThisTick += $nodeCount;
		$this->statWireBlocksSolved += $nodeCount;
		$this->statNetworksSolved++;

		//power from everything which is not a wire
		$level = array_fill(0, $nodeCount, 0);
		/** @phpstan-var array<int, list<int>> $buckets */
		$buckets = array_fill(0, 16, []);
		foreach($nodes as $i => [$x, $y, $z]){
			$input = 0;
			$skip = $skipFaces[$i];
			foreach(Facing::ALL as $face){
				if(($skip & (1 << $face)) !== 0){
					continue;
				}
				$power = $this->getPowerFrom($x, $y, $z, $face, true);
				if($power > $input){
					$input = $power;
					if($input >= 15){
						break;
					}
				}
			}
			if($input > 0){
				$level[$i] = $input;
				$buckets[$input][] = $i;
			}
		}

		//spread power through the network, losing 1 per wire (bucket queue, highest power first)
		for($power = 15; $power >= 2; --$power){
			$bucket = $buckets[$power];
			for($k = 0; $k < count($bucket); ++$k){
				$i = $bucket[$k];
				if($level[$i] !== $power){
					continue; //already raised by a stronger path
				}
				$next = $power - 1;
				foreach($edges[$i] as $j){
					if($level[$j] < $next){
						$level[$j] = $next;
						$buckets[$next][] = $j;
					}
				}
			}
		}

		$toNotify = [];
		foreach($nodes as $i => [$x, $y, $z]){
			$visited[World::blockHash($x, $y, $z)] = true;
			$newStateId = self::$wireStateByLevel[$states[$i]][$level[$i]] ?? null;
			if($newStateId === null || $newStateId === $states[$i]){
				continue;
			}
			$world->setWireStateIdAt($x, $y, $z, $newStateId);

			foreach(Facing::OFFSET as $face => [$dx, $dy, $dz]){
				$nx = $x + $dx;
				$ny = $y + $dy;
				$nz = $z + $dz;
				$toNotify[World::blockHash($nx, $ny, $nz)] = true;
				if($face !== Facing::UP && (self::flagsOf($this->stateIdAt($nx, $ny, $nz)) & self::FLAG_CONDUCTOR) !== 0){
					//the wire may strongly power this block: its neighbours must notice too
					foreach(Facing::OFFSET as [$dx2, $dy2, $dz2]){
						$toNotify[World::blockHash($nx + $dx2, $ny + $dy2, $nz + $dz2)] = true;
					}
				}
			}
		}

		foreach($toNotify as $hash => $_){
			if(isset($index[$hash])){
				continue; //wires of this network are already up to date
			}
			World::getBlockXYZ($hash, $x, $y, $z);
			$world->scheduleNeighbourBlockUpdateAt($x, $y, $z);
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Observers
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Tells the observers watching the block at x/y/z that its state changed. Only the six neighbours are looked at,
	 * using the cached flags of their state IDs, so this is cheap enough to run on every block change.
	 *
	 * @internal Called by the world when a block state changes.
	 */
	public function notifyObservers(int $x, int $y, int $z) : void{
		foreach(Facing::OFFSET as $face => [$dx, $dy, $dz]){
			$stateId = $this->stateIdAt($x + $dx, $y + $dy, $z + $dz);
			if((self::flagsOf($stateId) & self::FLAG_OBSERVER) === 0 || self::$observerFacing[$stateId] !== Facing::opposite($face)){
				continue;
			}
			$observer = $this->world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
			if($observer instanceof Observer){
				$observer->onObservedBlockChange();
			}
		}
	}

	/**
	 * Remembers that the observer at x/y/z saw a change while it was already pulsing, so that it pulses again.
	 */
	public function setObserverRetrigger(int $x, int $y, int $z) : void{
		$this->observerRetriggers[World::blockHash($x, $y, $z)] = true;
	}

	/**
	 * Returns whether the observer at x/y/z must pulse again, and forgets it.
	 */
	public function consumeObserverRetrigger(int $x, int $y, int $z) : bool{
		$hash = World::blockHash($x, $y, $z);
		if(isset($this->observerRetriggers[$hash])){
			unset($this->observerRetriggers[$hash]);
			return true;
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Statistics
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @return int[]
	 * @phpstan-return array{wireBlocksSolved: int, networksSolved: int, solveTimeNs: int, pendingWires: int}
	 */
	public function getStatistics() : array{
		return [
			"wireBlocksSolved" => $this->statWireBlocksSolved,
			"networksSolved" => $this->statNetworksSolved,
			"solveTimeNs" => $this->statSolveTimeNs,
			"pendingWires" => count($this->dirtyWires),
		];
	}

	public function resetStatistics() : void{
		$this->statWireBlocksSolved = 0;
		$this->statNetworksSolved = 0;
		$this->statSolveTimeNs = 0;
	}
}
