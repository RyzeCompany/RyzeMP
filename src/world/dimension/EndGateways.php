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

namespace pocketmine\world\dimension;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\EndGateway as TileEndGateway;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\World;
use function abs;
use function array_values;
use function ceil;
use function cos;
use function floor;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Placement of End gateways, following vanilla (Java's EndDragonFight, EndGatewayFeature and
 * TheEndGatewayBlockEntity):
 *  - each dragon kill spawns a gateway at one of 20 spots on a circle of radius 96 around the main island, at y 75, in
 *    a seed-dependent order;
 *  - the first time such a gateway is used, its exit is searched along the line from 0,0 through it: around 1024
 *    blocks out, the first generated chunk with blocks (an outer island); an exit gateway leading back is built 10
 *    blocks above the island surface (on a new small island if the chunk has no spot to stand on).
 */
final class EndGateways{
	public const COUNT = 20;
	public const RING_RADIUS = 96;
	public const RING_Y = 75;
	/** Distance from 0,0 where the exit search starts. */
	public const EXIT_SEARCH_DISTANCE = 1024;
	/** Steps of 16 blocks the exit search makes inwards (and then outwards). */
	public const EXIT_SEARCH_STEPS = 16;
	/** Radius around the found spot searched for the highest block (vanilla findTallestBlock()). */
	public const EXIT_TALLEST_RADIUS = 16;
	/** Radius around the exit gateway searched for the highest block entities arrive on. */
	public const ARRIVAL_RADIUS = 5;

	private function __construct(){
		//NOOP
	}

	/**
	 * Indices (0-19) of the gateways around the main island in vanilla's order for a world seed: the dragon fight
	 * takes them from the end of the list.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getRingOrder(int $seed) : array{
		$list = [];
		for($i = 0; $i < self::COUNT; ++$i){
			$list[] = $i;
		}
		//Util.shuffle(list, RandomSource.create(seed))
		$random = new LegacyRandom($seed);
		for($i = self::COUNT; $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			[$list[$i - 1], $list[$swapTo]] = [$list[$swapTo], $list[$i - 1]];
		}
		return array_values($list);
	}

	/**
	 * Position of the gateway with the given index (0-19) around the main island.
	 */
	public static function getRingPosition(int $index) : Vector3{
		$angle = 2.0 * (-M_PI + 0.15707963267948966 * $index);
		return new Vector3((int) floor(self::RING_RADIUS * cos($angle)), self::RING_Y, (int) floor(self::RING_RADIUS * sin($angle)));
	}

	/**
	 * Builds a gateway at the given position: the gateway block in a bedrock frame, with air around it. The chunks
	 * around the position must be loaded.
	 */
	public static function build(World $world, Vector3 $origin, ?Vector3 $exit, bool $exact) : void{
		$ox = $origin->getFloorX();
		$oy = $origin->getFloorY();
		$oz = $origin->getFloorZ();
		$bedrock = VanillaBlocks::BEDROCK();
		$air = VanillaBlocks::AIR();
		for($z = $oz - 1; $z <= $oz + 1; ++$z){
			for($y = $oy - 2; $y <= $oy + 2; ++$y){
				for($x = $ox - 1; $x <= $ox + 1; ++$x){
					if(!$world->isInWorld($x, $y, $z)){
						continue;
					}
					$sameX = $x === $ox;
					$sameY = $y === $oy;
					$sameZ = $z === $oz;
					$edgeY = abs($y - $oy) === 2;
					if($sameX && $sameY && $sameZ){
						$world->setBlockAt($x, $y, $z, VanillaBlocks::END_GATEWAY());
						$tile = $world->getTileAt($x, $y, $z);
						if($tile instanceof TileEndGateway){
							$tile->setExitPortal($exit, $exact);
						}
					}elseif($sameY){
						$world->setBlockAt($x, $y, $z, $air);
					}elseif($edgeY && $sameX && $sameZ){
						$world->setBlockAt($x, $y, $z, $bedrock);
					}elseif(($sameX || $sameZ) && !$edgeY){
						$world->setBlockAt($x, $y, $z, $bedrock);
					}else{
						$world->setBlockAt($x, $y, $z, $air);
					}
				}
			}
		}
	}

	/**
	 * Direction of the exit search of a gateway: from 0,0 through the gateway, horizontally.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	public static function getSearchDirection(Vector3 $gateway) : array{
		$x = (float) $gateway->getFloorX();
		$z = (float) $gateway->getFloorZ();
		$length = sqrt($x * $x + $z * $z);
		return $length < 1.0E-5 ? [0.0, 0.0] : [$x / $length, $z / $length];
	}

	/**
	 * Vanilla's findExitPortalXZPosTentative(): from 1024 blocks out, steps inwards while the chunk there has blocks,
	 * then outwards while it is empty. $isChunkEmpty returns null when the chunk is not available yet: then the search
	 * stops and returns the chunk it needs instead.
	 *
	 * @phpstan-param \Closure(int, int) : ?bool $isChunkEmpty chunk X, chunk Z => whether it has no blocks
	 * @return float[]|int[]
	 * @phpstan-return array{0: true, 1: float, 2: float}|array{0: false, 1: int, 2: int} [true, x, z] or [false, chunkX, chunkZ] needed
	 */
	public static function findExitXZ(Vector3 $gateway, \Closure $isChunkEmpty) : array{
		[$dx, $dz] = self::getSearchDirection($gateway);
		$x = $dx * self::EXIT_SEARCH_DISTANCE;
		$z = $dz * self::EXIT_SEARCH_DISTANCE;
		$check = function(float $x, float $z) use ($isChunkEmpty) : array{
			$cx = (int) floor($x / 16.0);
			$cz = (int) floor($z / 16.0);
			return [$cx, $cz, $isChunkEmpty($cx, $cz)];
		};
		for($i = self::EXIT_SEARCH_STEPS; ; ){
			[$cx, $cz, $empty] = $check($x, $z);
			if($empty === null){
				return [false, $cx, $cz];
			}
			if($empty || $i-- <= 0){
				break;
			}
			$x += $dx * -16.0;
			$z += $dz * -16.0;
		}
		for($i = self::EXIT_SEARCH_STEPS; ; ){
			[$cx, $cz, $empty] = $check($x, $z);
			if($empty === null){
				return [false, $cx, $cz];
			}
			if(!$empty || $i-- <= 0){
				break;
			}
			$x += $dx * 16.0;
			$z += $dz * 16.0;
		}
		return [true, $x, $z];
	}

	/**
	 * Whether a chunk has no blocks at all (vanilla: no filled section).
	 */
	public static function isChunkEmpty(Chunk $chunk) : bool{
		foreach($chunk->getSubChunks() as $subChunk){
			if(!$subChunk->isEmptyAuthoritative()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Vanilla's findValidSpawnInChunk(): the End stone block of the chunk (from y 30 up) with two non-full blocks above
	 * it that is closest to 0,0,0, or null if there is none. The chunk must be loaded.
	 */
	public static function findValidSpawnInChunk(World $world, int $chunkX, int $chunkZ) : ?Vector3{
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			return null;
		}
		$top = null;
		foreach($chunk->getSubChunks() as $y => $subChunk){
			if(!$subChunk->isEmptyAuthoritative()){
				$top = $y;
			}
		}
		if($top === null){
			return null;
		}
		$maxY = ($top << Chunk::COORD_BIT_SIZE) + 15;
		$minX = $chunkX << Chunk::COORD_BIT_SIZE;
		$minZ = $chunkZ << Chunk::COORD_BIT_SIZE;
		$closest = null;
		$closestDist = 0.0;
		for($z = $minZ; $z < $minZ + 16; ++$z){
			for($y = 30; $y <= $maxY; ++$y){
				for($x = $minX; $x < $minX + 16; ++$x){
					if(
						$world->getBlockAt($x, $y, $z)->getTypeId() !== BlockTypeIds::END_STONE ||
						$world->getBlockAt($x, $y + 1, $z)->isFullCube() ||
						$world->getBlockAt($x, $y + 2, $z)->isFullCube()
					){
						continue;
					}
					$dist = ($x + 0.5) ** 2 + ($y + 0.5) ** 2 + ($z + 0.5) ** 2;
					if($closest === null || $dist < $closestDist){
						$closest = new Vector3($x, $y, $z);
						$closestDist = $dist;
					}
				}
			}
		}
		return $closest;
	}

	/**
	 * Vanilla's findTallestBlock(): the highest full block within $radius blocks (horizontally) of $around, skipping
	 * the centre column unless $allowBedrock, and bedrock unless $allowBedrock; $around itself if there is none.
	 * The chunks must be loaded.
	 */
	public static function findTallestBlock(World $world, Vector3 $around, int $radius, bool $allowBedrock) : Vector3{
		$tallest = null;
		$ax = $around->getFloorX();
		$az = $around->getFloorZ();
		for($dx = -$radius; $dx <= $radius; ++$dx){
			for($dz = -$radius; $dz <= $radius; ++$dz){
				if($dx === 0 && $dz === 0 && !$allowBedrock){
					continue;
				}
				for($y = $world->getMaxY() - 1; $y > ($tallest === null ? $world->getMinY() : $tallest->getFloorY()); --$y){
					$block = $world->getBlockAt($ax + $dx, $y, $az + $dz);
					if($block->isFullCube() && ($allowBedrock || $block->getTypeId() !== BlockTypeIds::BEDROCK)){
						$tallest = new Vector3($ax + $dx, $y, $az + $dz);
						break;
					}
				}
			}
		}
		return $tallest ?? $around->floor();
	}

	/**
	 * Vanilla's end_island feature: a small floating island of End stone, top layer at $origin, seeded by the
	 * position like vanilla does for exit islands.
	 */
	public static function placeIsland(World $world, Vector3 $origin) : void{
		$ox = $origin->getFloorX();
		$oy = $origin->getFloorY();
		$oz = $origin->getFloorZ();
		//RandomSource.create(BlockPos.asLong())
		$random = new LegacyRandom((($ox & 0x3ffffff) << 38) | (($oz & 0x3ffffff) << 12) | ($oy & 0xfff));
		$endStone = VanillaBlocks::END_STONE();
		$size = $random->nextBoundedInt(3) + 4.0;
		for($y = 0; $size > 0.5; --$y){
			for($x = (int) floor(-$size); $x <= (int) ceil($size); ++$x){
				for($z = (int) floor(-$size); $z <= (int) ceil($size); ++$z){
					if($x * $x + $z * $z <= ($size + 1.0) * ($size + 1.0) && $world->isInWorld($ox + $x, $oy + $y, $oz + $z)){
						$world->setBlockAt($ox + $x, $oy + $y, $oz + $z, $endStone);
					}
				}
			}
			$size -= $random->nextBoundedInt(2) + 0.5;
		}
	}

	/**
	 * Vanilla's findExitPosition(): where entities arrive through a gateway with a non-exact exit: on top of the
	 * highest block (bedrock excluded) around it. The chunks around the exit must be loaded.
	 */
	public static function getArrivalPosition(World $world, Vector3 $exit) : Vector3{
		$top = self::findTallestBlock($world, $exit->add(0, 2, 0), self::ARRIVAL_RADIUS, false);
		return new Vector3($top->getFloorX() + 0.5, $top->getFloorY() + 1, $top->getFloorZ() + 0.5);
	}
}
