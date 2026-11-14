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

namespace pocketmine\entity\ai\navigation;

use pocketmine\block\BaseFire;
use pocketmine\block\Block;
use pocketmine\block\Cactus;
use pocketmine\block\Campfire;
use pocketmine\block\Cobweb;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\IronDoor;
use pocketmine\block\Ladder;
use pocketmine\block\Lava;
use pocketmine\block\Magma;
use pocketmine\block\PowderSnow;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\Trapdoor;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\Water;
use pocketmine\block\WitherRose;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function count;
use function max;

/**
 * Classifies block states for mob navigation and line of sight, reading block state IDs straight from chunks (no
 * Block objects on the hot path). The classification of each state ID is computed once and cached.
 */
final class PathBlockTypes{
	/** No collision: entities can be inside it. */
	public const PASSABLE = 1;
	/** Mobs can stand on top of it (collision top between 0.5 and 1). */
	public const FLOOR = 2;
	/** Low collision (slab, carpet, snow layer): mobs stand on it from inside its cell. */
	public const LOW = 4;
	/** Hurts mobs standing in or on it (fire, lava, cactus, magma, berry bush...). */
	public const DANGER = 8;
	public const WATER = 16;
	/** Collision taller than a block (fences, walls, closed fence gates): can't be stepped or jumped over. */
	public const TALL = 32;
	/** Blocks line of sight. */
	public const OPAQUE = 64;
	/** Ladders and vines. */
	public const CLIMBABLE = 128;
	/** Closed door: only passable for mobs which open doors (see {@link PathFinder::setCanOpenDoors()}). */
	public const CLOSED_DOOR = 256;
	/** Door mobs can open (not iron doors). */
	public const OPENABLE_DOOR = 512;
	/**
	 * Blocks line of sight where its collision boxes are, not as a whole block: doors, trapdoors and fence gates (see
	 * {@link \pocketmine\entity\ai\sensing\Sensing::hasLineOfSight()}).
	 */
	public const SIGHT_BY_SHAPE = 1024;

	/*
	 * Kinds of DANGER, for the path finder: which mobs avoid what was checked on BDS 1.26.52 (a mob chasing its target
	 * across a line of blocks with a gap in it).
	 */
	/** Lava: paths avoid it, except for mobs with vanilla navigation can_path_over_lava or can_walk_in_lava. */
	public const LAVA = 2048;
	/**
	 * Fire and soul fire: undead mobs without navigation avoid_damage_blocks walk through it (zombies, husks, drowned,
	 * zombie villagers, wither skeletons), mobs with avoid_damage_blocks never (wolves), other mobs only when there is no
	 * way round (vindicators, see {@link NavigationOptions::getCostlyMask()}).
	 */
	public const FIRE = 4096;
	/** Avoided by every walking mob: magma, lit campfires, sweet berry bushes with berries and cactus. */
	public const HURTS = 8192;
	/**
	 * Damage blocks of mobs with navigation avoid_damage_blocks (wolves, iron golems...): fire, lit campfires, cactus and
	 * sweet berry bushes, also the small ones without berries. These mobs neither walk into them nor next to them.
	 * Wither roses, powder snow and cobwebs are not avoided by any mob (BDS: wolves, vindicators and zombies walk into
	 * them), they are DANGER only for the other checks (wander targets, spawning, straight steps).
	 */
	public const DAMAGE_BLOCK = 16384;
	/** Powder snow, for navigation blocks_to_avoid (goats). */
	public const POWDER_SNOW = 32768;
	/** Trapdoors, for navigation blocks_to_avoid (breezes). */
	public const TRAPDOOR = 65536;

	/** The kinds of danger a mob may avoid next to a path, see {@link PathFinder::setDangerMargin()}. */
	public const NEAR_DANGER = self::LAVA | self::FIRE | self::HURTS | self::DAMAGE_BLOCK;

	/**
	 * @var int[] state ID => flags
	 * @phpstan-var array<int, int>
	 */
	private static array $cache = [];
	/**
	 * Kinds of danger of block states (the danger part of their flags), worked out without a position.
	 * @var int[] state ID => flags
	 * @phpstan-var array<int, int>
	 */
	private static array $dangerCache = [];
	/**
	 * Whether block states are doors mobs open (see {@link PathBlockTypes::isOpenableDoorState()}).
	 * @var bool[] state ID => door
	 * @phpstan-var array<int, bool>
	 */
	private static array $doorCache = [];
	/**
	 * Flags read by {@link PathBlockTypes::at()}: the same as $cache, plus {@link PathBlockTypes::LAYER_WATERLOGGABLE}
	 * for states which can hold water in the liquid layer, so ordinary states cost a single lookup.
	 * @var int[] state ID => flags
	 * @phpstan-var array<int, int>
	 */
	private static array $atCache = [];
	/** Internal bit of $atCache: the state can hold water in the liquid layer and isn't water itself. */
	private const LAYER_WATERLOGGABLE = 1 << 30;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the flags of the block at the given position, with {@link PathBlockTypes::WATER} for water held in the
	 * liquid layer too. Unloaded chunks are solid; above the world is air.
	 */
	public static function at(World $world, int $x, int $y, int $z) : int{
		//the state read in C++ when chunkutils2 can (0.3.12): one call instead of the lookups below
		if(($native = World::$nativePhysics) !== null){
			$stateId = $native->stateAt($world, $x, $y, $z);
			if($stateId >= 0){
				$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
				if($flags < self::LAYER_WATERLOGGABLE){
					return $flags;
				}
				//the liquid layer is read below
			}elseif($stateId === -2){
				return self::PASSABLE; //above the world
			}elseif($stateId !== -4){
				return self::FLOOR | self::OPAQUE; //below the world (-1), unloaded chunk (-3)
			}
		}
		if($y < $world->getMinY()){
			return self::FLOOR | self::OPAQUE;
		}
		if($y >= $world->getMaxY()){
			return self::PASSABLE;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return self::FLOOR | self::OPAQUE;
		}
		//the subchunk once, for the state and the liquid layer (what Chunk::getBlockStateId() reads)
		$subChunk = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$stateId = $subChunk->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
		if($flags < self::LAYER_WATERLOGGABLE){
			return $flags;
		}
		//water held in the liquid layer (waterlogged slabs, stairs...) isn't part of the state
		$flags &= ~self::LAYER_WATERLOGGABLE;
		if(LiquidLayer::isWaterInSubChunk($subChunk, $x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK)){
			$flags |= self::WATER;
		}
		return $flags;
	}

	/**
	 * @internal Same as {@link PathBlockTypes::at()} for a block of the given chunk (null: not loaded), which the caller
	 * looked up once for a column of blocks, between the world's $minY and $maxY: no chunk lookup per block.
	 */
	public static function inColumn(World $world, ?Chunk $chunk, int $minY, int $maxY, int $x, int $y, int $z) : int{
		if($y < $minY){
			return self::FLOOR | self::OPAQUE;
		}
		if($y >= $maxY){
			return self::PASSABLE;
		}
		if($chunk === null){
			return self::FLOOR | self::OPAQUE;
		}
		$subChunk = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$stateId = $subChunk->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
		if($flags < self::LAYER_WATERLOGGABLE){
			return $flags;
		}
		$flags &= ~self::LAYER_WATERLOGGABLE;
		if(LiquidLayer::isWaterInSubChunk($subChunk, $x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK)){
			$flags |= self::WATER;
		}
		return $flags;
	}

	/**
	 * @internal Same as {@link PathBlockTypes::inColumn()} for a position inside the world in a loaded chunk, given the
	 * subchunk holding it and its block layer (layer 0, null when it has none): for callers reading many blocks of the
	 * same subchunk, which look both up once. The state is read as SubChunk::getBlockStateId() reads it.
	 */
	public static function inLayer(World $world, SubChunk $subChunk, ?PalettedBlockArray $layer, int $x, int $y, int $z) : int{
		$localX = $x & SubChunk::COORD_MASK;
		$localY = $y & SubChunk::COORD_MASK;
		$localZ = $z & SubChunk::COORD_MASK;
		$stateId = $layer !== null ? $layer->get($localX, $localY, $localZ) : $subChunk->getEmptyBlockId();
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
		if($flags < self::LAYER_WATERLOGGABLE){
			return $flags;
		}
		$flags &= ~self::LAYER_WATERLOGGABLE;
		if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY, $localZ)){
			$flags |= self::WATER;
		}
		return $flags;
	}

	/**
	 * @internal Same as {@link PathBlockTypes::at()} for a position inside the world, given the subchunk holding it:
	 * for callers reading many blocks of the same subchunk. An empty subchunk is all air.
	 */
	public static function inSubChunk(World $world, SubChunk $subChunk, int $x, int $y, int $z) : int{
		if($subChunk->isEmptyFast()){
			return self::PASSABLE;
		}
		$localX = $x & SubChunk::COORD_MASK;
		$localY = $y & SubChunk::COORD_MASK;
		$localZ = $z & SubChunk::COORD_MASK;
		$stateId = $subChunk->getBlockStateId($localX, $localY, $localZ);
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
		if($flags < self::LAYER_WATERLOGGABLE){
			return $flags;
		}
		$flags &= ~self::LAYER_WATERLOGGABLE;
		if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY, $localZ)){
			$flags |= self::WATER;
		}
		return $flags;
	}

	/**
	 * @internal Same as {@link PathBlockTypes::at()} for the blocks at $y - 1, $y and $y + 1 of a column, which the
	 * caller made sure are all three inside the world and in the subchunk of the given (loaded) chunk holding $y: for
	 * callers reading those three blocks of many columns, with one call, one subchunk lookup and the block layer read
	 * directly (what SubChunk::getBlockStateId() does for each block).
	 */
	public static function threeInSubChunk(World $world, Chunk $chunk, int $x, int $y, int $z, int &$below, int &$middle, int &$above) : void{
		$subChunk = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
		$localX = $x & SubChunk::COORD_MASK;
		$localY = $y & SubChunk::COORD_MASK;
		$localZ = $z & SubChunk::COORD_MASK;
		$layers = $subChunk->getBlockLayers();
		$layer = $layers[0] ?? null;
		$stateId = $layer !== null ? $layer->get($localX, $localY - 1, $localZ) : $subChunk->getEmptyBlockId();
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y - 1, $z);
		if($flags >= self::LAYER_WATERLOGGABLE){
			$flags &= ~self::LAYER_WATERLOGGABLE;
			if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY - 1, $localZ)){
				$flags |= self::WATER;
			}
		}
		$below = $flags;
		$stateId = $layer !== null ? $layer->get($localX, $localY, $localZ) : $subChunk->getEmptyBlockId();
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
		if($flags >= self::LAYER_WATERLOGGABLE){
			$flags &= ~self::LAYER_WATERLOGGABLE;
			if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY, $localZ)){
				$flags |= self::WATER;
			}
		}
		$middle = $flags;
		$stateId = $layer !== null ? $layer->get($localX, $localY + 1, $localZ) : $subChunk->getEmptyBlockId();
		$flags = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y + 1, $z);
		if($flags >= self::LAYER_WATERLOGGABLE){
			$flags &= ~self::LAYER_WATERLOGGABLE;
			if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY + 1, $localZ)){
				$flags |= self::WATER;
			}
		}
		$above = $flags;
	}

	/**
	 * @internal For {@link PathNavigator}'s straight walks: whether a walking mob with $headBlocks (0 or 1) head blocks has
	 * room with its feet on row $y in every column from ($minX, $minZ) to ($maxX, $maxZ), all in the given (loaded) chunk,
	 * $y - 1 and $y + 1 in the subchunk of $y: the feet block passable or low and none of $danger, water, a door,
	 * trapdoor or fence gate; the head block passable and none of $danger; a floor below without $danger or water,
	 * unless the feet block is low. The blocks are read like {@link PathBlockTypes::threeInSubChunk()}, with the subchunk
	 * looked up once for all the columns.
	 *
	 * The columns are checked X by X and Z by Z within each X, the first one without room ends the check (false).
	 * Columns in $columns already are not read again; each column read is added to it.
	 *
	 * @phpstan-param array<int, array<int, bool>> $columns
	 */
	public static function hasWalkRoom(World $world, Chunk $chunk, int $y, int $minX, int $maxX, int $minZ, int $maxZ, int $danger, int $headBlocks, array &$columns) : bool{
		$subChunk = null;
		$layer = null;
		$localY = $y & SubChunk::COORD_MASK;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$open = $columns[$x][$z] ?? null;
				if($open === null){
					if($subChunk === null){
						//only when a column isn't known yet (consecutive steps of a sweep mostly cover known columns)
						$subChunk = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
						$layer = $subChunk->getBlockLayers()[0] ?? null;
					}
					$localX = $x & SubChunk::COORD_MASK;
					$localZ = $z & SubChunk::COORD_MASK;
					$stateId = $layer !== null ? $layer->get($localX, $localY - 1, $localZ) : $subChunk->getEmptyBlockId();
					$below = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y - 1, $z);
					if($below >= self::LAYER_WATERLOGGABLE){
						$below &= ~self::LAYER_WATERLOGGABLE;
						if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY - 1, $localZ)){
							$below |= self::WATER;
						}
					}
					$stateId = $layer !== null ? $layer->get($localX, $localY, $localZ) : $subChunk->getEmptyBlockId();
					$feet = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y, $z);
					if($feet >= self::LAYER_WATERLOGGABLE){
						$feet &= ~self::LAYER_WATERLOGGABLE;
						if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY, $localZ)){
							$feet |= self::WATER;
						}
					}
					$stateId = $layer !== null ? $layer->get($localX, $localY + 1, $localZ) : $subChunk->getEmptyBlockId();
					$head = self::$atCache[$stateId] ??= self::atFlags($world, $stateId, $x, $y + 1, $z);
					if($head >= self::LAYER_WATERLOGGABLE){
						$head &= ~self::LAYER_WATERLOGGABLE;
						if(LiquidLayer::isWaterInSubChunk($subChunk, $localX, $localY + 1, $localZ)){
							$head |= self::WATER;
						}
					}
					$open = $columns[$x][$z] = ($feet & (self::PASSABLE | self::LOW)) !== 0 &&
						($feet & ($danger | self::WATER | self::SIGHT_BY_SHAPE | self::CLOSED_DOOR)) === 0 &&
						($headBlocks === 0 || (($head & self::PASSABLE) !== 0 && ($head & $danger) === 0)) &&
						(($feet & self::LOW) !== 0 || (($below & self::FLOOR) !== 0 && ($below & ($danger | self::WATER)) === 0));
				}
				if(!$open){
					return false;
				}
			}
		}
		return true;
	}

	private static function atFlags(World $world, int $stateId, int $x, int $y, int $z) : int{
		$flags = self::$cache[$stateId] ??= self::classify($world->getBlockAt($x, $y, $z));
		if(($flags & self::WATER) === 0 && LiquidLayer::canContainWater($stateId)){
			$flags |= self::LAYER_WATERLOGGABLE;
		}
		return $flags;
	}

	/**
	 * Flags of the given block state at the given position (the position is only used to build the block the first
	 * time this state is seen). For callers which already read the state ID.
	 */
	public static function ofState(World $world, int $stateId, int $x, int $y, int $z) : int{
		return self::$cache[$stateId] ??= self::classify($world->getBlockAt($x, $y, $z));
	}

	/**
	 * @internal Returns the flags of a block state if they were already worked out, null otherwise.
	 */
	public static function cachedFlagsOfState(int $stateId) : ?int{
		return self::$cache[$stateId] ?? null;
	}

	/**
	 * Same as {@link PathBlockTypes::at()} with a chunk the caller already looked up (null = unloaded).
	 */
	public static function inChunk(World $world, ?Chunk $chunk, int $x, int $y, int $z) : int{
		if($chunk === null || $y < $world->getMinY()){
			return self::FLOOR | self::OPAQUE;
		}
		if($y >= $world->getMaxY()){
			return self::PASSABLE;
		}
		$stateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
		return self::$cache[$stateId] ??= self::classify($world->getBlockAt($x, $y, $z));
	}

	/**
	 * Returns whether a mob needing $headBlocks free blocks above its feet can stand with its feet in this block
	 * (same rules as the path finder, without its per-search cache).
	 *
	 * @param int $footprint blocks the mob takes along X and Z (see {@link PathFinder::footprintOf()}): the block is the
	 *                       corner of that square with the lowest X and Z, every block of the square must be free and
	 *                       at least one of them must have something to stand on, like the path finder's nodes
	 */
	public static function isStandable(World $world, int $x, int $y, int $z, int $headBlocks, bool $allowWater = true, int $footprint = 1) : bool{
		//each column's blocks are read from its chunk, looked up once
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		if($footprint > 1){
			$supported = false;
			for($i = 0; $i < $footprint; ++$i){
				for($j = 0; $j < $footprint; ++$j){
					$cx = $x + $i;
					$cz = $z + $j;
					$cell = self::standing($world, $world->getChunk($cx >> Chunk::COORD_BIT_SIZE, $cz >> Chunk::COORD_BIT_SIZE), $minY, $maxY, $cx, $y, $cz, $headBlocks, $allowWater);
					if($cell < 0){
						return false;
					}
					$supported = $supported || $cell === 1;
				}
			}
			return $supported;
		}
		return self::standing($world, $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE), $minY, $maxY, $x, $y, $z, $headBlocks, $allowWater) === 1;
	}

	/**
	 * One column of {@link PathBlockTypes::isStandable()}: -1 if the mob doesn't fit there or would stand on something
	 * dangerous, 1 if it has something to stand on, 0 if it fits but would fall. $chunk is the chunk of the column
	 * (null: not loaded).
	 */
	private static function standing(World $world, ?Chunk $chunk, int $minY, int $maxY, int $x, int $y, int $z, int $headBlocks, bool $allowWater) : int{
		$feet = self::inColumn($world, $chunk, $minY, $maxY, $x, $y, $z);
		if(($feet & (self::PASSABLE | self::LOW)) === 0 || ($feet & self::DANGER) !== 0 || (!$allowWater && ($feet & self::WATER) !== 0)){
			return -1;
		}
		for($i = 1; $i <= $headBlocks; ++$i){
			$head = self::inColumn($world, $chunk, $minY, $maxY, $x, $y + $i, $z);
			if(($head & self::PASSABLE) === 0 || ($head & self::DANGER) !== 0){
				return -1;
			}
		}
		if(($feet & (self::LOW | self::WATER)) !== 0){
			return 1;
		}
		$below = self::inColumn($world, $chunk, $minY, $maxY, $x, $y - 1, $z);
		if(($below & self::DANGER) !== 0){
			return -1;
		}
		return ($below & self::FLOOR) !== 0 ? 1 : 0;
	}

	/**
	 * @internal Returns the highest of the $count blocks from ($x, $y, $z) downwards where the mob can stand, or null:
	 * the same as asking {@link PathBlockTypes::isStandable()} for each of them from the top down (a random target
	 * settling on the ground), but each block is read once, not once per position whose feet, head or floor it is.
	 */
	public static function findStandableDown(World $world, int $x, int $y, int $z, int $count, int $headBlocks, bool $allowWater = true, int $footprint = 1) : ?int{
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$end = $y - $count;
		if($footprint > 1){
			/** @phpstan-var list<array{?Chunk, int, int}> $columns */
			$columns = [];
			/** @phpstan-var list<array<int, int>> $flags */
			$flags = [];
			for($i = 0; $i < $footprint; ++$i){
				for($j = 0; $j < $footprint; ++$j){
					$columns[] = [$world->getChunk(($x + $i) >> Chunk::COORD_BIT_SIZE, ($z + $j) >> Chunk::COORD_BIT_SIZE), $x + $i, $z + $j];
					$flags[] = [];
				}
			}
			for(; $y > $end; --$y){
				$supported = false;
				foreach($columns as $k => [$chunk, $cx, $cz]){
					$cell = self::standingCached($world, $chunk, $minY, $maxY, $cx, $y, $cz, $headBlocks, $allowWater, $flags[$k]);
					if($cell < 0){
						continue 2;
					}
					$supported = $supported || $cell === 1;
				}
				if($supported){
					return $y;
				}
			}
			return null;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$column = [];
		for(; $y > $end; --$y){
			if(self::standingCached($world, $chunk, $minY, $maxY, $x, $y, $z, $headBlocks, $allowWater, $column) === 1){
				return $y;
			}
		}
		return null;
	}

	/**
	 * {@link PathBlockTypes::standing()} with the flags of the column's blocks read so far, by Y.
	 *
	 * @phpstan-param array<int, int> $column
	 */
	private static function standingCached(World $world, ?Chunk $chunk, int $minY, int $maxY, int $x, int $y, int $z, int $headBlocks, bool $allowWater, array &$column) : int{
		$feet = $column[$y] ??= self::inColumn($world, $chunk, $minY, $maxY, $x, $y, $z);
		if(($feet & (self::PASSABLE | self::LOW)) === 0 || ($feet & self::DANGER) !== 0 || (!$allowWater && ($feet & self::WATER) !== 0)){
			return -1;
		}
		for($i = 1; $i <= $headBlocks; ++$i){
			$head = $column[$y + $i] ??= self::inColumn($world, $chunk, $minY, $maxY, $x, $y + $i, $z);
			if(($head & self::PASSABLE) === 0 || ($head & self::DANGER) !== 0){
				return -1;
			}
		}
		if(($feet & (self::LOW | self::WATER)) !== 0){
			return 1;
		}
		$below = $column[$y - 1] ??= self::inColumn($world, $chunk, $minY, $maxY, $x, $y - 1, $z);
		if(($below & self::DANGER) !== 0){
			return -1;
		}
		return ($below & self::FLOOR) !== 0 ? 1 : 0;
	}

	/**
	 * Returns the kinds of danger of a block state (DANGER and the flags LAVA to TRAPDOOR, see {@link PathBlockTypes::LAVA}),
	 * the same as in its flags, without needing a position (cached). The path finder reads them from the palettes of
	 * chunk sections to skip the checks next to its nodes in sections without anything dangerous.
	 */
	public static function dangerOfState(int $stateId) : int{
		return self::$dangerCache[$stateId] ??= self::dangerFlags(RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId));
	}

	private static function dangerFlags(Block $block) : int{
		if($block instanceof Lava){
			return self::DANGER | self::LAVA;
		}
		if($block instanceof BaseFire){
			return self::DANGER | self::FIRE | self::DAMAGE_BLOCK;
		}
		if($block instanceof Cactus || ($block instanceof Campfire && $block->isLit())){
			return self::DANGER | self::HURTS | self::DAMAGE_BLOCK;
		}
		if($block instanceof SweetBerryBush){
			//the small bush without berries doesn't hurt (BDS: zombies walk through it), mobs which avoid damage blocks avoid
			//it all the same
			return self::DANGER | self::DAMAGE_BLOCK | ($block->getAge() >= SweetBerryBush::STAGE_BUSH_NO_BERRIES ? self::HURTS : 0);
		}
		if($block instanceof Magma){
			return self::DANGER | self::HURTS;
		}
		if($block instanceof PowderSnow){
			return self::DANGER | self::POWDER_SNOW;
		}
		if($block instanceof WitherRose || $block instanceof Cobweb){
			return self::DANGER;
		}
		if($block instanceof Trapdoor){
			return self::TRAPDOOR;
		}
		return 0;
	}

	public static function classify(Block $block) : int{
		$flags = self::dangerFlags($block);
		if($block instanceof Water || ($block instanceof Waterlogged && $block->isWaterlogged())){
			$flags |= self::WATER;
		}
		if($block->canClimb()){
			$flags |= self::CLIMBABLE;
			if($block instanceof Ladder){
				//its thin collision box against the wall leaves room for a mob: paths go into it (Java: open), and a mob
				//walking to its middle presses against the ladder and climbs (see Mob::tryChangeMovement())
				return $flags | self::PASSABLE;
			}
		}

		if($block instanceof Door){
			//open doors only have a thin collision box along one side
			if(!$block instanceof IronDoor){
				$flags |= self::OPENABLE_DOOR;
			}
			return $flags | self::SIGHT_BY_SHAPE | ($block->isOpen() ? self::PASSABLE : self::CLOSED_DOOR | self::TALL);
		}
		if($block instanceof Trapdoor || $block instanceof FenceGate){
			$flags |= self::SIGHT_BY_SHAPE;
		}

		$boxes = $block->getCollisionBoxes();
		if(count($boxes) === 0){
			return $flags | self::PASSABLE;
		}
		$baseY = $block->getPosition()->y;
		$maxY = 0.0;
		foreach($boxes as $bb){
			$maxY = max($maxY, $bb->maxY - $baseY);
		}
		if($maxY > 1.0001){
			$flags |= self::TALL;
		}elseif($maxY <= 0.5){
			$flags |= self::LOW;
		}else{
			$flags |= self::FLOOR;
		}
		if(!$block->isTransparent() && $block->isFullCube()){
			$flags |= self::OPAQUE;
		}
		return $flags;
	}

	/**
	 * Returns whether the block state is a door mobs open or break (any door but iron doors), without needing a
	 * position (cached). The path finder reads it from the palettes of chunk sections.
	 */
	public static function isOpenableDoorState(int $stateId) : bool{
		return self::$doorCache[$stateId] ??= (($block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId)) instanceof Door && !$block instanceof IronDoor);
	}

	/**
	 * @internal for tests
	 */
	public static function clearCache() : void{
		self::$cache = [];
		self::$dangerCache = [];
		self::$atCache = [];
		self::$doorCache = [];
	}
}
