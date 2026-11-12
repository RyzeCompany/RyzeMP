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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\block\tile\Tile;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use function array_combine;
use function array_flip;
use function array_keys;
use function count;
use function intdiv;

/**
 * The decoration region (vanilla's WorldGenRegion for the FEATURES step): the 3x3 chunks around the decorated chunk,
 * read and written as Java block states. Blocks written by features are remembered as Java states, so later reads
 * see exactly what was written (the Bedrock mapping is lossy for a few properties, e.g. leaf distance); other blocks
 * are mapped back from the PocketMine states.
 *
 * Heightmaps are computed from the current blocks on first use and kept up to date on writes, like vanilla's chunk
 * heightmaps (Heightmap.update()).
 */
final class FeatureLevel{
	public const WORLD_SURFACE_WG = 0;
	public const WORLD_SURFACE = 1;
	public const OCEAN_FLOOR_WG = 2;
	public const OCEAN_FLOOR = 3;
	public const MOTION_BLOCKING = 4;
	public const MOTION_BLOCKING_NO_LEAVES = 5;

	public const SIZE = 48;

	public int $minX = 0;
	public int $minZ = 0;
	public int $centerChunkX = 0;
	public int $centerChunkZ = 0;
	public readonly int $maxY;
	private readonly int $sections;

	/** @var SubChunk[] by (chunk index * sections + section) */
	private array $subChunks = [];
	/** @var Chunk[] by (dx + 1) * 3 + (dz + 1) */
	private array $chunks = [];
	/** @phpstan-var array<int, true> */
	private array $dirty = [];
	/** See {@link FeatureLevel::begin()}. */
	private bool $copyOnWrite = false;
	/** @phpstan-var array<int, true> subchunk indexes already copied in copy-on-write mode */
	private array $copied = [];
	/** @phpstan-var array<int, int> Java states written during this decoration */
	private array $written = [];
	/** @phpstan-var array<int, array<int, int>> heightmap type => column => first available y */
	private array $heightmaps = [];
	/**
	 * Terrain heightmaps (WORLD_SURFACE_WG, OCEAN_FLOOR_WG) per chunk index: vanilla only updates these during the
	 * terrain step, so features see the terrain's heights (first free y per column, index x | z << 4).
	 * @phpstan-var array<int, array{list<int>, list<int>}>
	 */
	private array $terrainHeights = [];
	/** @phpstan-var (\Closure(int, int, Chunk) : array{list<int>, list<int>})|null */
	public ?\Closure $terrainHeightProvider = null;
	/** @phpstan-var array<int, array<int, bool>> heightmap type => Java state => opaque */
	private array $opaque = [];
	/** @phpstan-var array<int, bool> Java state => may be waterlogged (needs a liquid layer read) */
	private array $waterloggable = [];

	private readonly int $voidAir;
	/** @phpstan-var array<int, true> */
	private array $motionBlocking;
	/** @phpstan-var array<int, true> */
	private array $motionBlockingNoLeaves;

	/**
	 * Whether writes are logged in {@link $writeLog} (the native decoration mirrors the region and needs the writes
	 * made on the PHP side).
	 */
	public bool $logWrites = false;
	/**
	 * Logged writes: key, Java state, PocketMine state triples (key bit 31: written by setPmState(), which does not
	 * update heightmaps; PocketMine state bit 31: water in the liquid layer).
	 * @phpstan-var list<int>
	 */
	public array $writeLog = [];

	/** Positional random of this region (WorldGenRegion.getRandom()), set by the decorator. */
	public ?\pocketmine\world\generator\vanilla\random\RandomSource $regionRandom = null;

	public function __construct(
		public readonly JavaBlocks $blocks,
		public readonly int $minY,
		public readonly int $height,
		public readonly int $seaLevel,
		public readonly int $seed,
		public readonly FeatureBiomes $biomes
	){
		$this->maxY = $minY + $height;
		$this->sections = $height >> 4;
		$this->voidAir = $blocks->voidAir;
		$this->motionBlocking = $blocks->tag("minecraft:blocks_motion_in_heightmap");
		$this->motionBlockingNoLeaves = $blocks->tag("minecraft:blocks_motion_in_heightmap_no_leaves");
	}

	/**
	 * @param bool $copyOnWrite whether the chunks' subchunks are shared with a live world (blocks grown in a loaded
	 *                          world): each subchunk is then copied on its first write, and only the copy is changed
	 */
	public function begin(ChunkManager $world, int $chunkX, int $chunkZ, bool $copyOnWrite = false) : void{
		$this->copyOnWrite = $copyOnWrite;
		$this->copied = [];
		$this->centerChunkX = $chunkX;
		$this->centerChunkZ = $chunkZ;
		$this->minX = ($chunkX - 1) << 4;
		$this->minZ = ($chunkZ - 1) << 4;
		$this->subChunks = [];
		$this->chunks = [];
		$this->dirty = [];
		$this->written = [];
		$this->heightmaps = [];
		$this->terrainHeights = [];
		$this->writeLog = [];
		$minSection = $this->minY >> 4;
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$chunk = $world->getChunk($chunkX + $dx, $chunkZ + $dz) ?? throw new \InvalidArgumentException("Chunk " . ($chunkX + $dx) . " " . ($chunkZ + $dz) . " is not available for decoration");
				$index = ($dx + 1) * 3 + ($dz + 1);
				$this->chunks[$index] = $chunk;
				for($s = 0; $s < $this->sections; ++$s){
					$this->subChunks[$index * $this->sections + $s] = $chunk->getSubChunk($minSection + $s);
				}
			}
		}
		$this->biomes->begin($chunkX, $chunkZ, $this->chunks);
	}

	/**
	 * Marks the modified chunks as dirty, so PocketMine sends them back from the worker.
	 */
	public function finish() : void{
		foreach($this->dirty as $index => $_){
			$this->chunks[$index]->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
		}
		$this->written = [];
		$this->subChunks = [];
		$this->chunks = [];
	}

	public function isOutsideBuildHeight(int $y) : bool{
		return $y < $this->minY || $y >= $this->maxY;
	}

	public function isInsideBuildHeight(int $y) : bool{
		return $y >= $this->minY && $y < $this->maxY;
	}

	/**
	 * Whether a block can be written (inside the 3x3 chunks and the build height), WorldGenRegion.ensureCanWrite().
	 */
	public function ensureCanWrite(int $x, int $y, int $z) : bool{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		return $dx >= 0 && $dx < self::SIZE && $dz >= 0 && $dz < self::SIZE && $y >= $this->minY && $y < $this->maxY;
	}

	public function isInRegion(int $x, int $z) : bool{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		return $dx >= 0 && $dx < self::SIZE && $dz >= 0 && $dz < self::SIZE;
	}

	public function getState(int $x, int $y, int $z) : int{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		if($dx < 0 || $dx >= self::SIZE || $dz < 0 || $dz >= self::SIZE || $y < $this->minY || $y >= $this->maxY){
			return $this->voidAir;
		}
		$key = (($y - $this->minY) * self::SIZE + $dz) * self::SIZE + $dx;
		if(isset($this->written[$key])){
			return $this->written[$key];
		}
		$sub = $this->subChunks[((($dx >> 4) * 3 + ($dz >> 4)) * $this->sections) + (($y - $this->minY) >> 4)];
		$java = $this->blocks->fromPm($sub->getBlockStateId($x & 15, $y & 15, $z & 15));
		if($this->waterloggable[$java] ??= $this->blocks->hasProperty($java, "waterlogged")){
			$layers = $sub->getBlockLayers();
			if(isset($layers[1]) && $layers[1]->get($x & 15, $y & 15, $z & 15) === $this->blocks->pmWaterState()){
				$java = $this->blocks->withBool($java, "waterlogged", true);
			}
		}
		return $java;
	}

	/**
	 * Sets a block (WorldGenRegion.setBlock()). Returns false if the position cannot be written.
	 */
	public function setState(int $x, int $y, int $z, int $state) : bool{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		if($dx < 0 || $dx >= self::SIZE || $dz < 0 || $dz >= self::SIZE || $y < $this->minY || $y >= $this->maxY){
			return false;
		}
		$key = (($y - $this->minY) * self::SIZE + $dz) * self::SIZE + $dx;
		$chunkIndex = ($dx >> 4) * 3 + ($dz >> 4);
		$blocks = $this->blocks;
		$pm = $blocks->toPm($state);
		$water = $blocks->pmNeedsWater($state);
		if($this->logWrites){
			$this->writeLog[] = $key;
			$this->writeLog[] = $state;
			$this->writeLog[] = $water ? $pm | 0x80000000 : $pm;
		}
		$this->written[$key] = $state;
		$subIndex = ($chunkIndex * $this->sections) + (($y - $this->minY) >> 4);
		$sub = $this->copyOnWrite && !isset($this->copied[$subIndex]) ? $this->copySubChunk($subIndex) : $this->subChunks[$subIndex];
		$sub->setBlockStateId($x & 15, $y & 15, $z & 15, $pm);
		$this->setLiquidLayerStateId($subIndex, $x & 15, $y & 15, $z & 15, $water ? $blocks->pmWaterState() : $blocks->pmAirState());
		$this->dirty[$chunkIndex] = true;
		if(count($this->heightmaps) > 0){
			$column = $dz * self::SIZE + $dx;
			foreach($this->heightmaps as $type => $map){
				if(isset($map[$column])){
					$this->updateHeightmap($type, $column, $x, $y, $z, $state);
				}
			}
		}
		return true;
	}

	/**
	 * Sets a Bedrock-only block (a PocketMine state id with no Java block state, e.g. an item frame, which is an
	 * entity in Java). Later reads of the position see the Java state the Bedrock state maps back to. Heightmaps are
	 * not updated (only meant for blocks without collision). Returns false if the position cannot be written.
	 */
	public function setPmState(int $x, int $y, int $z, int $pmState) : bool{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		if($dx < 0 || $dx >= self::SIZE || $dz < 0 || $dz >= self::SIZE || $y < $this->minY || $y >= $this->maxY){
			return false;
		}
		$key = (($y - $this->minY) * self::SIZE + $dz) * self::SIZE + $dx;
		$chunkIndex = ($dx >> 4) * 3 + ($dz >> 4);
		unset($this->written[$key]);
		if($this->logWrites){
			$this->writeLog[] = $key | 0x80000000;
			$this->writeLog[] = $this->blocks->fromPm($pmState);
			$this->writeLog[] = $pmState;
		}
		$subIndex = ($chunkIndex * $this->sections) + (($y - $this->minY) >> 4);
		$sub = $this->copyOnWrite && !isset($this->copied[$subIndex]) ? $this->copySubChunk($subIndex) : $this->subChunks[$subIndex];
		$sub->setBlockStateId($x & 15, $y & 15, $z & 15, $pmState);
		$this->setLiquidLayerStateId($subIndex, $x & 15, $y & 15, $z & 15, $this->blocks->pmAirState());
		$this->dirty[$chunkIndex] = true;
		return true;
	}

	/**
	 * Copy-on-write mode: replaces a shared subchunk by a copy (in the region and in its chunk) before its first write.
	 */

	/**
	 * RyzeMP has no SubChunk::setLiquidLayerStateId(). Mutate an existing
	 * second palette when present; otherwise install a new SubChunk with a
	 * second (water) layer, preserving biomes and lighting. Does NOT modify
	 * central PM5 APIs or rewrite existing chunk providers.
	 */
	private function setLiquidLayerStateId(int $subIndex, int $x, int $y, int $z, int $state) : void{
		$sub = $this->subChunks[$subIndex];
		$layers = $sub->getBlockLayers();
		if(!isset($layers[1])){
			if($state === $this->blocks->pmAirState()){
				return;
			}
			$layers[] = new PalettedBlockArray($this->blocks->pmAirState());
			$sub = new SubChunk(
				$sub->getEmptyBlockId(),
				$layers,
				clone $sub->getBiomeArray(),
				clone $sub->getBlockSkyLightArray(),
				clone $sub->getBlockLightArray()
			);
			$this->subChunks[$subIndex] = $sub;
			$this->chunks[intdiv($subIndex, $this->sections)]->setSubChunk(
				($this->minY >> 4) + $subIndex % $this->sections,
				$sub
			);
		}
		$layers[1]->set($x, $y, $z, $state);
	}

	private function copySubChunk(int $subIndex) : SubChunk{
		$copy = clone $this->subChunks[$subIndex];
		$this->subChunks[$subIndex] = $copy;
		$this->copied[$subIndex] = true;
		$this->chunks[intdiv($subIndex, $this->sections)]->setSubChunk(($this->minY >> 4) + $subIndex % $this->sections, $copy);
		return $copy;
	}


	/**
	 * Gives the block at a position its block entity data (a tile's saved data, created when the chunk reaches the
	 * world, see Chunk::addPendingTileNBT()). Returns false if the position cannot be written.
	 */
	public function setBlockEntity(int $x, int $y, int $z, CompoundTag $nbt) : bool{
		// RyzeMP's worker chunk API has no pending-tile NBT persistence.
		// Fail closed instead of silently losing loot, inventories or NBT.
		throw new \RuntimeException("Worldgen block entities require a RyzeMP chunk/LevelDB persistence adapter");
	}

	public function addEntity(int $x, int $z, CompoundTag $nbt) : bool{
		throw new \RuntimeException("Worldgen entity NBT requires a RyzeMP chunk/LevelDB persistence adapter");
	}

	/**
	 * Index of the chunk at the given offset from the centre chunk (dx, dz in -1..1).
	 */
	public function getRegionChunk(int $dx, int $dz) : Chunk{
		return $this->chunks[($dx + 1) * 3 + ($dz + 1)];
	}

	public function isEmptyBlock(int $x, int $y, int $z) : bool{
		return $this->blocks->isAir($this->getState($x, $y, $z));
	}

	public function isWaterAt(int $x, int $y, int $z) : bool{
		return $this->blocks->isWater($this->getState($x, $y, $z));
	}

	public function getBiome(int $x, int $y, int $z) : int{
		return $this->biomes->getBiome($x, $y, $z);
	}

	private function isOpaque(int $type, int $state) : bool{
		if(isset($this->opaque[$type][$state])){
			return $this->opaque[$type][$state];
		}
		$blocks = $this->blocks;
		$block = $blocks->blockOf[$state];
		$opaque = match($type){
			self::WORLD_SURFACE_WG, self::WORLD_SURFACE => !$blocks->isAir($state),
			self::OCEAN_FLOOR_WG, self::OCEAN_FLOOR => isset($this->motionBlocking[$block]),
			self::MOTION_BLOCKING => isset($this->motionBlocking[$block]) || $blocks->fluid($state) !== JavaBlocks::FLUID_NONE,
			default => isset($this->motionBlockingNoLeaves[$block]) || $blocks->fluid($state) !== JavaBlocks::FLUID_NONE,
		};
		return $this->opaque[$type][$state] = $opaque;
	}

	/**
	 * First free y above the highest block matching the heightmap type (vanilla's getHeight()).
	 */
	public function getHeight(int $type, int $x, int $z) : int{
		$dx = $x - $this->minX;
		$dz = $z - $this->minZ;
		if($dx < 0 || $dx >= self::SIZE || $dz < 0 || $dz >= self::SIZE){
			return $this->minY; //vanilla would crash here
		}
		if(($type === self::WORLD_SURFACE_WG || $type === self::OCEAN_FLOOR_WG) && $this->terrainHeightProvider !== null){
			return $this->terrainHeightsOf(($dx >> 4) * 3 + ($dz >> 4))[$type === self::WORLD_SURFACE_WG ? 0 : 1][($dx & 15) | (($dz & 15) << 4)];
		}
		$column = $dz * self::SIZE + $dx;
		if(isset($this->heightmaps[$type][$column])){
			return $this->heightmaps[$type][$column];
		}
		$chunkIndex = ($dx >> 4) * 3 + ($dz >> 4);
		//start at the top of the highest non-empty section, like Heightmap.primeHeightmaps()
		$top = $this->minY;
		for($s = $this->sections - 1; $s >= 0; --$s){
			if(!$this->subChunks[$chunkIndex * $this->sections + $s]->isEmptyFast()){
				$top = $this->minY + ($s << 4) + 16;
				break;
			}
		}
		$height = $this->minY;
		for($y = $top - 1; $y >= $this->minY; --$y){
			$state = $this->getState($x, $y, $z);
			if($this->isOpaque($type, $state)){
				$height = $y + 1;
				break;
			}
		}
		return $this->heightmaps[$type][$column] = $height;
	}

	/**
	 * Terrain heightmaps (WORLD_SURFACE_WG, OCEAN_FLOOR_WG) of a chunk of the region (index (dx + 1) * 3 + dz + 1),
	 * computed on first use and then kept for the rest of the decoration.
	 * @phpstan-return array{list<int>, list<int>}
	 */
	public function terrainHeightsOf(int $chunkIndex) : array{
		if(!isset($this->terrainHeights[$chunkIndex])){
			$provider = $this->terrainHeightProvider ?? throw new \LogicException("No terrain height provider");
			$this->terrainHeights[$chunkIndex] = $provider($this->centerChunkX - 1 + intdiv($chunkIndex, 3), $this->centerChunkZ - 1 + $chunkIndex % 3, $this->chunks[$chunkIndex]);
		}
		return $this->terrainHeights[$chunkIndex];
	}

	/**
	 * Terrain heightmaps computed so far in this decoration, by region chunk index.
	 * @phpstan-return array<int, array{list<int>, list<int>}>
	 */
	public function getKnownTerrainHeights() : array{
		return $this->terrainHeights;
	}

	/**
	 * Sets the terrain heightmaps of a chunk of the region (computed by the native decoration).
	 * @phpstan-param array{list<int>, list<int>} $heights
	 */
	public function setTerrainHeights(int $chunkIndex, array $heights) : void{
		$this->terrainHeights[$chunkIndex] = $heights;
	}


	/**
	 * Section of a chunk of the region (chunk index (dx + 1) * 3 + dz + 1, section 0 = minY).
	 */
	public function getRegionSubChunk(int $chunkIndex, int $section) : SubChunk{
		return $this->subChunks[$chunkIndex * $this->sections + $section];
	}

	private function updateHeightmap(int $type, int $column, int $x, int $y, int $z, int $state) : void{
		$first = $this->heightmaps[$type][$column];
		if($y <= $first - 2){
			return;
		}
		if($this->isOpaque($type, $state)){
			if($y >= $first){
				$this->heightmaps[$type][$column] = $y + 1;
			}
		}elseif($first - 1 === $y){
			for($yy = $y - 1; $yy >= $this->minY; --$yy){
				if($this->isOpaque($type, $this->getState($x, $yy, $z))){
					$this->heightmaps[$type][$column] = $yy + 1;
					return;
				}
			}
			$this->heightmaps[$type][$column] = $this->minY;
		}
	}
}
