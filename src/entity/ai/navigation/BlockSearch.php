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

use pocketmine\block\Lava;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function count;
use function max;
use function min;
use const PHP_INT_MAX;

/**
 * @internal
 * Searches for the nearest liquid or dry land around a mob (vanilla behavior.move_to_water, move_to_lava/move_to_liquid
 * and move_to_land), reading block state IDs from the chunks. The search goes outwards ring by ring (square rings
 * around the mob's column) and stops as soon as no further ring can hold a better match, so a mob next to what it looks
 * for only reads a few blocks. Liquid searches first skip every subchunk whose palette has no such liquid, so a search where there is
 * none costs a few palette checks; land searches read each column from the top of the searched box down to the first
 * water block, and stop after a fixed number of reads.
 */
final class BlockSearch{
	public const WATER = 1;
	public const LAVA = 2;

	/** Blocks a land search reads at most. */
	public const MAX_LAND_READS = 6144;

	/**
	 * @var int[] state ID => LAVA or 0
	 * @phpstan-var array<int, int>
	 */
	private static array $lavaStates = [];

	private function __construct(){
		//NOOP
	}

	private static function isLavaState(int $stateId) : bool{
		return (self::$lavaStates[$stateId] ??= (RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId) instanceof Lava ? self::LAVA : 0)) !== 0;
	}

	private static function isLiquidState(int $stateId, int $kind) : bool{
		return $kind === self::WATER ? LiquidLayer::isWaterState($stateId) : self::isLavaState($stateId);
	}

	/**
	 * Returns the nearest block of the given liquid (WATER: water blocks, waterlogged blocks and blocks holding water in
	 * the liquid layer; LAVA: lava blocks) within $range blocks horizontally and $height blocks vertically, or null.
	 * Blocks at most one block above or below the mob's feet are ranked by their ring, others by their ring or their
	 * height difference minus one, whichever is larger; among equals the nearest wins. Water only counts where a mob can
	 * get in (see {@link BlockSearch::isEnterableWater()}).
	 *
	 * @param bool $surface only blocks with something passable that isn't the same liquid above (the surface)
	 */
	public static function findNearestLiquid(World $world, int $x, int $y, int $z, int $range, int $height, int $kind, bool $surface = false) : ?Vector3{
		$minY = max($y - $height, $world->getMinY());
		$maxY = min($y + $height, $world->getMaxY() - 1);
		if($minY > $maxY){
			return null;
		}
		$minSubY = $minY >> SubChunk::COORD_BIT_SIZE;
		$maxSubY = $maxY >> SubChunk::COORD_BIT_SIZE;

		/**
		 * Subchunks which may hold the liquid (null: they don't) of the chunks with at least one of them
		 * @var (SubChunk|null)[][] $chunks
		 */
		$chunks = [];
		for($chunkX = ($x - $range) >> Chunk::COORD_BIT_SIZE, $maxChunkX = ($x + $range) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = ($z - $range) >> Chunk::COORD_BIT_SIZE, $maxChunkZ = ($z + $range) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				$subChunks = [];
				$found = false;
				for($subY = $minSubY; $subY <= $maxSubY; ++$subY){
					$subChunk = $chunk->getSubChunk($subY);
					$layers = $subChunk->getBlockLayers();
					$candidate = $kind === self::WATER && isset($layers[1]);
					if(!$candidate && isset($layers[0])){
						foreach($layers[0]->getPalette() as $stateId){
							if(self::isLiquidState($stateId, $kind)){
								$candidate = true;
								break;
							}
						}
					}
					$subChunks[$subY] = $candidate ? $subChunk : null;
					$found = $found || $candidate;
				}
				if($found){
					$chunks[World::chunkHash($chunkX, $chunkZ)] = $subChunks;
				}
			}
		}
		if(count($chunks) === 0){
			return null;
		}

		//rank: max(ring, height difference beyond one block), then distance; a block far above or below (cave water under
		//the floor) only wins over blocks of the rings at least that far out, so open water next to the mob comes first
		$best = null;
		$bestCost = PHP_INT_MAX;
		$bestDistance = PHP_INT_MAX;
		for($r = 0; $r <= $range && $r <= $bestCost; ++$r){
			for($dx = -$r; $dx <= $r; ++$dx){
				//the edge of the ring only: whole rows at dx = -r and r, the two ends of the columns in between
				$step = $dx === -$r || $dx === $r || $r === 0 ? 1 : 2 * $r;
				$bx = $x + $dx;
				for($dz = -$r; $dz <= $r; $dz += $step){
					$bz = $z + $dz;
					$subChunks = $chunks[World::chunkHash($bx >> Chunk::COORD_BIT_SIZE, $bz >> Chunk::COORD_BIT_SIZE)] ?? null;
					if($subChunks === null){
						continue;
					}
					$localX = $bx & SubChunk::COORD_MASK;
					$localZ = $bz & SubChunk::COORD_MASK;
					//outwards from the mob's level: the first match is the best of the column
					$horizontal = $dx * $dx + $dz * $dz;
					for($dy = 0; $dy <= $height; ++$dy){
						$cost = max($r, $dy - 1);
						$distance = $horizontal + $dy * $dy;
						if($cost > $bestCost || ($cost === $bestCost && $distance >= $bestDistance)){
							break;
						}
						for($side = $dy === 0 ? 1 : 0; $side < 2; ++$side){
							$by = $side === 0 ? $y - $dy : $y + $dy;
							if($by < $minY || $by > $maxY){
								continue;
							}
							$subChunk = $subChunks[$by >> SubChunk::COORD_BIT_SIZE];
							if($subChunk === null){
								continue;
							}
							$localY = $by & SubChunk::COORD_MASK;
							$match = $kind === self::WATER ?
								LiquidLayer::hasWaterInSubChunk($subChunk, $localX, $localY, $localZ) && self::isEnterableWater($world, $bx, $by, $bz) :
								self::isLavaState($subChunk->getBlockStateId($localX, $localY, $localZ)) && (!$surface || self::isSurface($world, $bx, $by + 1, $bz, $kind));
							if($match){
								$best = new Vector3($bx, $by, $bz);
								$bestCost = $cost;
								$bestDistance = $distance;
								break 2;
							}
						}
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Whether a mob can get into the water at the given block: water a mob can be in (not held by a solid block like
	 * waterlogged stairs or mangrove roots) with room above it.
	 */
	private static function isEnterableWater(World $world, int $x, int $y, int $z) : bool{
		return (PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::PASSABLE) !== 0 && (PathBlockTypes::at($world, $x, $y + 1, $z) & PathBlockTypes::PASSABLE) !== 0;
	}

	private static function isSurface(World $world, int $x, int $y, int $z, int $kind) : bool{
		$flags = PathBlockTypes::at($world, $x, $y, $z);
		return ($flags & PathBlockTypes::PASSABLE) !== 0 && ($flags & ($kind === self::WATER ? PathBlockTypes::WATER : PathBlockTypes::LAVA)) === 0;
	}

	/**
	 * Returns the nearest dry spot to stand on (the position of the feet: a free block without water or danger, a free
	 * block above it, and solid ground below it or a low block like a slab in it) within $range blocks horizontally and
	 * $height blocks vertically, or null. Within a ring, the nearest spot wins.
	 */
	public static function findNearestLand(World $world, int $x, int $y, int $z, int $range, int $height, int $maxReads = self::MAX_LAND_READS) : ?Vector3{
		$minY = max($y - $height, $world->getMinY() + 1);
		$maxY = min($y + $height, $world->getMaxY() - 2);
		if($minY > $maxY){
			return null;
		}
		$reads = 0;
		for($r = 0; $r <= $range; ++$r){
			$best = null;
			$bestDistance = PHP_INT_MAX;
			for($dx = -$r; $dx <= $r; ++$dx){
				$step = $dx === -$r || $dx === $r || $r === 0 ? 1 : 2 * $r;
				$bx = $x + $dx;
				for($dz = -$r; $dz <= $r; $dz += $step){
					$bz = $z + $dz;
					$chunk = $world->getChunk($bx >> Chunk::COORD_BIT_SIZE, $bz >> Chunk::COORD_BIT_SIZE);
					if($chunk === null){
						continue; //unloaded: solid
					}
					//downwards, carrying the two blocks above along: below the first water block everything is under water
					//(the searched box is inside the world: $minY - 1 and $maxY + 1 too)
					$above = PathBlockTypes::inSubChunk($world, $chunk->getSubChunk(($maxY + 1) >> SubChunk::COORD_BIT_SIZE), $bx, $maxY + 1, $bz);
					$subY = $maxY >> SubChunk::COORD_BIT_SIZE;
					$subChunk = $chunk->getSubChunk($subY);
					$feet = PathBlockTypes::inSubChunk($world, $subChunk, $bx, $maxY, $bz);
					$reads += 2;
					for($by = $maxY; $by >= $minY; --$by){
						if(($feet & PathBlockTypes::WATER) !== 0){
							break;
						}
						if((($by - 1) >> SubChunk::COORD_BIT_SIZE) !== $subY){
							$subY = ($by - 1) >> SubChunk::COORD_BIT_SIZE;
							$subChunk = $chunk->getSubChunk($subY);
						}
						$below = PathBlockTypes::inSubChunk($world, $subChunk, $bx, $by - 1, $bz);
						++$reads;
						$distance = $dx * $dx + $dz * $dz + ($by - $y) ** 2;
						if($distance < $bestDistance && self::isDrySpot($above, $feet, $below)){
							$best = new Vector3($bx, $by, $bz);
							$bestDistance = $distance;
						}
						$above = $feet;
						$feet = $below;
					}
					if($reads >= $maxReads){
						return $best;
					}
				}
			}
			if($best !== null){
				return $best;
			}
		}
		return null;
	}

	/**
	 * Whether a mob can stand dry with its feet in a block of flags $feet, given the flags of the blocks above and below.
	 */
	public static function isDrySpot(int $above, int $feet, int $below) : bool{
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0 || ($feet & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) !== 0){
			return false;
		}
		if(($above & PathBlockTypes::PASSABLE) === 0 || ($above & (PathBlockTypes::DANGER | PathBlockTypes::WATER)) !== 0){
			return false;
		}
		return ($feet & PathBlockTypes::LOW) !== 0 || (($below & PathBlockTypes::FLOOR) !== 0 && ($below & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) === 0);
	}
}
