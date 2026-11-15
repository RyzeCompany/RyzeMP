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

use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function ceil;
use function floor;
use function max;
use function mt_rand;

/**
 * Picks random standable positions around a mob (wandering, panicking, fleeing).
 */
final class RandomPos{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns a random block (feet position) within $horizontal blocks around the mob and $vertical blocks up or down
	 * where it can stand, or null after 10 failed tries. A mob wider than a block needs room for its footprint around
	 * the middle of the block (see {@link PathFinder::footprintOf()}), like its paths.
	 *
	 * @param Vector3|null $awayFrom if set, only positions farther from this point than the mob are picked
	 */
	public static function getLandPos(Mob $mob, int $horizontal, int $vertical, ?Vector3 $awayFrom = null, bool $allowWater = false) : ?Vector3{
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$baseX = (int) floor($loc->x);
		$baseY = (int) floor($loc->y + 0.01);
		$baseZ = (int) floor($loc->z);
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$footprint = PathFinder::footprintOf($mob->getSize()->getWidth());
		//the footprint a path to the middle of the block ends on: the block for 1 and 2 blocks wide
		$corner = PathFinder::nodeCoordinate(0.5, $footprint);
		$restricted = self::isHomeRestricted($mob, $horizontal);
		for($try = 0; $try < 10; ++$try){
			[$dx, $dz] = self::offset($mob, $baseX, $baseZ, $horizontal, $awayFrom);
			$x = $baseX + $dx;
			$z = $baseZ + $dz;
			$y = $baseY + mt_rand(-$vertical, $vertical);
			//settle on the ground below the picked point: the first of the 2 * vertical + 2 blocks from there down where it
			//can stand
			$y = PathBlockTypes::findStandableDown($world, $x + $corner, $y, $z + $corner, $vertical * 2 + 2, $headBlocks, $allowWater, $footprint);
			if($y !== null && (!$restricted || $mob->isWithinHome($x + 0.5, $y, $z + 0.5))){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	/**
	 * Returns the centre of a random water block within range where the mob fits (water up to its height), or null
	 * after 10 failed tries. Used by swimming mobs.
	 *
	 * @param Vector3|null $awayFrom if set, only positions farther from this point than the mob are picked
	 */
	public static function getWaterPos(Mob $mob, int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$baseX = (int) floor($loc->x);
		$baseY = (int) floor($loc->y + 0.01);
		$baseZ = (int) floor($loc->z);
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$restricted = self::isHomeRestricted($mob, $horizontal);
		for($try = 0; $try < 10; ++$try){
			[$dx, $dz] = self::offset($mob, $baseX, $baseZ, $horizontal, $awayFrom);
			$x = $baseX + $dx;
			$z = $baseZ + $dz;
			$y = $baseY + mt_rand(-$vertical, $vertical);
			if($restricted && !$mob->isWithinHome($x + 0.5, $y, $z + 0.5)){
				continue;
			}
			$ok = true;
			for($i = 0; $i <= $headBlocks; ++$i){
				$flags = PathBlockTypes::at($world, $x, $y + $i, $z);
				if(($flags & PathBlockTypes::WATER) === 0 || ($flags & PathBlockTypes::PASSABLE) === 0){
					$ok = false;
					break;
				}
			}
			if($ok){
				return new Vector3($x + 0.5, $y + 0.1, $z + 0.5);
			}
		}
		return null;
	}

	/**
	 * Returns the centre of a random air block within range (not water, nothing dangerous) with ground at most
	 * $maxAboveGround blocks below it, or null after 10 failed tries. Used by flying mobs.
	 *
	 * @param Vector3|null $awayFrom if set, only positions farther from this point than the mob are picked
	 */
	public static function getAirPos(Mob $mob, int $horizontal, int $vertical, int $maxAboveGround = 6, ?Vector3 $awayFrom = null) : ?Vector3{
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$baseX = (int) floor($loc->x);
		$baseY = (int) floor($loc->y + 0.01);
		$baseZ = (int) floor($loc->z);
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		$restricted = self::isHomeRestricted($mob, $horizontal);
		for($try = 0; $try < 10; ++$try){
			[$dx, $dz] = self::offset($mob, $baseX, $baseZ, $horizontal, $awayFrom);
			$x = $baseX + $dx;
			$z = $baseZ + $dz;
			$y = $baseY + mt_rand(-$vertical, $vertical);
			if($restricted && !$mob->isWithinHome($x + 0.5, $y, $z + 0.5)){
				continue;
			}
			$ok = true;
			for($i = 0; $i <= $headBlocks; ++$i){
				$flags = PathBlockTypes::at($world, $x, $y + $i, $z);
				if(($flags & PathBlockTypes::PASSABLE) === 0 || ($flags & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) !== 0){
					$ok = false;
					break;
				}
			}
			if(!$ok){
				continue;
			}
			for($down = 1; $down <= $maxAboveGround; ++$down){
				if((PathBlockTypes::at($world, $x, $y - $down, $z) & PathBlockTypes::PASSABLE) === 0){
					return new Vector3($x + 0.5, $y + 0.1, $z + 0.5);
				}
			}
		}
		return null;
	}

	/**
	 * Returns whether the random targets of the mob must stay within its home restriction (see
	 * {@link \pocketmine\entity\HomeTrait}): only when it isn't already farther from home than the radius plus the
	 * search range (Java GoalUtils.mobRestricted), where no target could be found anyway. A mob that far away gets back
	 * by its offsets leaning towards home (see {@link RandomPos::offset()}) or by
	 * {@link \pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal}.
	 * @internal
	 */
	public static function isHomeRestricted(Mob $mob, int $horizontal) : bool{
		$radius = $mob->getHomeRadius();
		if($radius <= 0){
			return false;
		}
		$loc = $mob->getLiveLocation();
		$distanceSquared = $mob->getHomeDistanceSquared($loc->x, $loc->y, $loc->z);
		$limit = $radius + $horizontal + 1;
		return $distanceSquared !== null && $distanceSquared < $limit * $limit;
	}

	/**
	 * Returns a random horizontal offset within $horizontal blocks. Away from $awayFrom if set; otherwise, for a mob
	 * with a home restriction, leaning by up to half the range towards home (Java
	 * RandomPos.generateRandomPosTowardDirection).
	 * @internal
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function offset(Mob $mob, int $baseX, int $baseZ, int $horizontal, ?Vector3 $awayFrom) : array{
		$dx = mt_rand(-$horizontal, $horizontal);
		$dz = mt_rand(-$horizontal, $horizontal);
		if($awayFrom !== null){
			//flip the offset so that it points away from the danger
			if(($baseX - $awayFrom->x) * $dx < 0){
				$dx = -$dx;
			}
			if(($baseZ - $awayFrom->z) * $dz < 0){
				$dz = -$dz;
			}
		}elseif($horizontal > 1 && $mob->getHomeRadius() > 0 && ($home = $mob->getHomePos()) !== null){
			$lean = mt_rand(0, $horizontal >> 1);
			$dx += $baseX > $home->x ? -$lean : $lean;
			$lean = mt_rand(0, $horizontal >> 1);
			$dz += $baseZ > $home->z ? -$lean : $lean;
		}
		return [$dx, $dz];
	}
}
