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

namespace pocketmine\entity\ai\villager;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function abs;
use function floor;

/**
 * Base of the villager schedule goals: walking to a block (bed, job site, bell, crop).
 */
abstract class VillagerGoal extends Goal{

	public function __construct(
		protected VillagerV2 $villager
	){}

	/**
	 * Whether the sky is open above the position of feet (false in unloaded chunks). Like Java's MOTION_BLOCKING height
	 * map, only blocks with a collision box and liquids above the block of the feet make a roof: tall grass, flowers,
	 * torches and vines don't, nor do the snow layer, carpet or slab the feet are in. One height map lookup, plus one
	 * cached state lookup for each block above the feet below the first roof block (usually none).
	 */
	public static function isUnderSky(World $world, Vector3 $pos) : bool{
		$x = (int) floor($pos->x);
		$z = (int) floor($pos->z);
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return false;
		}
		$highest = $world->getHighestBlockAt($x, $z);
		if($highest === null){
			return true;
		}
		for($y = $highest, $above = (int) floor($pos->y) + 1; $y >= $above; --$y){
			$flags = PathBlockTypes::at($world, $x, $y, $z);
			if(($flags & PathBlockTypes::PASSABLE) === 0 || ($flags & (PathBlockTypes::WATER | PathBlockTypes::LAVA)) !== 0){
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the villager stands within $reach blocks (horizontally, and 2 blocks vertically) of the centre of the
	 * block.
	 */
	protected function isNear(Vector3 $block, float $reach) : bool{
		$loc = $this->villager->getLiveLocation();
		$dx = $loc->x - $block->x - 0.5;
		$dz = $loc->z - $block->z - 0.5;
		return $dx * $dx + $dz * $dz <= $reach * $reach && abs($loc->y - $block->y) <= 2.0;
	}

	/**
	 * Keeps walking towards the block (a new path is requested once the last one ended). Returns true once within
	 * $reach of it.
	 */
	protected function walkTo(Vector3 $block, float $speed, float $reach) : bool{
		if($this->isNear($block, $reach)){
			return true;
		}
		$navigation = $this->villager->getNavigation();
		if($navigation->isDone()){
			//the path may end half a block short of $reach: villagers stop up to ~0.45 blocks off the last waypoint's
			//centre, and a path ending on a diagonal neighbour (1.41 away) would otherwise leave them 1.5+ blocks away
			//from a bed forever, asking for the same finished path again
			$navigation->moveTo($block->x + 0.5, $block->y, $block->z + 0.5, $speed, $reach - 0.5);
		}
		return false;
	}
}
