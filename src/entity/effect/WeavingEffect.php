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

namespace pocketmine\entity\effect;

use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Living;
use pocketmine\math\Facing;
use pocketmine\world\World;
use function count;
use function mt_rand;

/**
 * Weaving (https://minecraft.wiki/w/Weaving): the entity leaves 2-3 cobwebs around where it dies.
 *
 * Like Java's WeavingMobEffect, up to 15 random spots in the 3x3x3 cube around the entity are tried, and a cobweb goes
 * into each empty one that stands on a full top face. The lower slowdown inside cobwebs is client-side movement.
 */
class WeavingEffect extends Effect{
	private const ATTEMPTS = 15;

	public function onEntityDeath(Living $entity, EffectInstance $instance) : void{
		self::spreadCobwebs($entity->getWorld(), $entity->getPosition()->getFloorX(), $entity->getPosition()->getFloorY(), $entity->getPosition()->getFloorZ(), 2 + mt_rand(0, 1));
	}

	/**
	 * Places up to $max cobwebs around the given block, returning how many were placed.
	 */
	public static function spreadCobwebs(World $world, int $x, int $y, int $z, int $max) : int{
		$placed = [];
		for($i = 0; $i < self::ATTEMPTS && count($placed) < $max; ++$i){
			$cx = $x + mt_rand(-1, 1);
			$cy = $y + mt_rand(-1, 1);
			$cz = $z + mt_rand(-1, 1);
			//the world check comes first: a mob dying far below the world has no block hash there
			if(!$world->isInWorld($cx, $cy, $cz) || !$world->isInWorld($cx, $cy - 1, $cz)){
				continue;
			}
			$hash = World::blockHash($cx, $cy, $cz);
			if(isset($placed[$hash])){
				continue;
			}
			if($world->getBlockAt($cx, $cy, $cz)->canBeReplaced() && $world->getBlockAt($cx, $cy - 1, $cz)->getSupportType(Facing::UP) === SupportType::FULL){
				$placed[$hash] = true;
				$world->setBlockAt($cx, $cy, $cz, VanillaBlocks::COBWEB());
			}
		}
		return count($placed);
	}
}
