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
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function abs;
use function max;
use function min;

/**
 * Builds the 2x3 portal an entity arrives through when no linked portal exists, like vanilla (simplified):
 * - the closest spot within 16 blocks (searching rings outwards) with a solid floor under the portal and room to step
 *   out on one side;
 * - otherwise a portal standing on a small obsidian platform at the arrival position, with the space around cleared.
 */
final class NetherPortalBuilder{
	public const SEARCH_RADIUS = 16;

	private function __construct(){
		//NOOP
	}

	/**
	 * Highest Y the bottom of a new portal may be at, keeping the frame under the build limit (and in the Nether, under
	 * the bedrock roof).
	 */
	private static function getMaxBaseY(World $world) : int{
		return $world->getDimension() === Dimension::NETHER ? 118 : $world->getMaxY() - 6;
	}

	private static function getMinBaseY(World $world) : int{
		return $world->getMinY() + 2;
	}

	/**
	 * Clamps an arrival position to where a portal may be built in the given world.
	 */
	public static function clampY(World $world, int $y) : int{
		return max(self::getMinBaseY($world), min(self::getMaxBaseY($world), $y));
	}

	private static function isAir(World $world, int $x, int $y, int $z) : bool{
		return $world->getBlockAt($x, $y, $z, addToCache: false)->getTypeId() === BlockTypeIds::AIR;
	}

	private static function isSolid(World $world, int $x, int $y, int $z) : bool{
		$block = $world->getBlockAt($x, $y, $z, addToCache: false);
		return $block->isSolid() && $block->isFullCube();
	}

	/**
	 * Whether a 2-wide portal can stand with its bottom-left interior block at x,y,z on the given axis, with a block of
	 * free space in front of it (on the +perpendicular side) to step out.
	 */
	private static function fits(World $world, int $x, int $y, int $z, int $axis) : bool{
		[$ax, $az] = $axis === Axis::X ? [1, 0] : [0, 1];
		[$px, $pz] = $axis === Axis::X ? [0, 1] : [1, 0];
		for($w = 0; $w < 2; ++$w){
			$bx = $x + $ax * $w;
			$bz = $z + $az * $w;
			for($side = 0; $side <= 1; ++$side){
				$cx = $bx + $px * $side;
				$cz = $bz + $pz * $side;
				if(!self::isSolid($world, $cx, $y - 1, $cz)){
					return false;
				}
				for($h = 0; $h < 3; ++$h){
					if(!self::isAir($world, $cx, $y + $h, $cz)){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Finds a spot for a new portal near the target position, or returns null if there is none within the search radius.
	 *
	 * @return int[]|null x, y, z, axis
	 * @phpstan-return array{int, int, int, int}|null
	 */
	public static function findSpot(World $world, Vector3 $target) : ?array{
		$tx = $target->getFloorX();
		$ty = self::clampY($world, $target->getFloorY());
		$tz = $target->getFloorZ();
		$minY = self::getMinBaseY($world);
		$maxY = self::getMaxBaseY($world);

		for($radius = 0; $radius <= self::SEARCH_RADIUS; ++$radius){
			$best = null;
			$bestDistance = 0;
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if(max(abs($dx), abs($dz)) !== $radius){
						continue; //only the ring at this radius
					}
					$x = $tx + $dx;
					$z = $tz + $dz;
					$highest = $world->getHighestBlockAt($x, $z);
					if($highest === null){
						continue; //empty column or chunk not loaded
					}
					for($y = min($maxY, $highest + 1); $y >= $minY; --$y){
						//cheap pre-check before testing the whole footprint: standing space on a solid block
						if(!self::isAir($world, $x, $y, $z) || !self::isSolid($world, $x, $y - 1, $z)){
							continue;
						}
						foreach([Axis::X, Axis::Z] as $axis){
							if(self::fits($world, $x, $y, $z, $axis)){
								$distance = $dx * $dx + $dz * $dz + ($y - $ty) * ($y - $ty);
								if($best === null || $distance < $bestDistance){
									$best = [$x, $y, $z, $axis];
									$bestDistance = $distance;
								}
								break;
							}
						}
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
	 * Builds a lit 2x3 portal (4x5 obsidian frame) with its bottom-left interior block at the given position.
	 */
	public static function build(World $world, int $x, int $y, int $z, int $axis, bool $platform) : NetherPortalShape{
		[$ax, $az] = $axis === Axis::X ? [1, 0] : [0, 1];
		[$px, $pz] = $axis === Axis::X ? [0, 1] : [1, 0];
		$obsidian = VanillaBlocks::OBSIDIAN();
		$air = VanillaBlocks::AIR();

		if($platform){
			//floating platform: obsidian floor on both sides of the portal and clear air above it
			for($w = -1; $w <= 2; ++$w){
				for($side = -1; $side <= 1; ++$side){
					$cx = $x + $ax * $w + $px * $side;
					$cz = $z + $az * $w + $pz * $side;
					$world->setBlockAt($cx, $y - 1, $cz, $obsidian);
					for($h = 0; $h < 3; ++$h){
						$world->setBlockAt($cx, $y + $h, $cz, $air);
					}
				}
			}
		}

		for($w = -1; $w <= 2; ++$w){
			for($h = -1; $h <= 3; ++$h){
				$cx = $x + $ax * $w;
				$cz = $z + $az * $w;
				if($w === -1 || $w === 2 || $h === -1 || $h === 3){
					$world->setBlockAt($cx, $y + $h, $cz, $obsidian);
				}
			}
		}
		$shape = new NetherPortalShape(new Vector3($x, $y, $z), $axis, 2, 3);
		$shape->light($world);
		return $shape;
	}

	/**
	 * Builds a new portal as close as possible to the target and records it.
	 * The chunks within SEARCH_RADIUS blocks of the target must be loaded.
	 */
	public static function create(World $world, Vector3 $target) : NetherPortalShape{
		$spot = self::findSpot($world, $target);
		if($spot !== null){
			[$x, $y, $z, $axis] = $spot;
			$shape = self::build($world, $x, $y, $z, $axis, false);
		}else{
			$y = self::clampY($world, max(70, $target->getFloorY()));
			$shape = self::build($world, $target->getFloorX(), $y, $target->getFloorZ(), Axis::X, true);
		}
		NetherPortalRegistry::getInstance()->add($world, $shape);
		return $shape;
	}
}
