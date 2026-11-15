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

namespace pocketmine\block;

use pocketmine\block\utils\SteppedOnBlock;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\player\Player;
use function abs;

final class Slime extends Transparent implements SteppedOnBlock{

	public function getFrictionFactor() : float{
		return 0.8; //???
	}

	public function onEntityLand(Entity $entity) : ?float{
		if($entity instanceof Living && $entity->isSneaking()){
			return null;
		}
		$entity->resetFallDistance();
		//vanilla SlimeBlock.bounceUp(): living entities get all their speed back, items, orbs, minecarts... 80%
		$motionY = $entity->getMotion()->y;
		if($motionY >= 0){
			return null;
		}
		return -$motionY * ($entity instanceof Living ? 1.0 : 0.8);
	}

	public function onEntityStepOn(Entity $entity) : void{
		//vanilla SlimeBlock.stepOn(): walking on it slows down to about 0.4x (players are slowed down by their client)
		if($entity instanceof Player || ($entity instanceof Living && $entity->isSneaking())){
			return;
		}
		$motion = $entity->getMotion();
		$motionY = abs($motion->y);
		if($motionY < 0.1 && ($motion->x !== 0.0 || $motion->z !== 0.0)){
			$slowdown = 0.6 - $motionY * 0.2; //motion *= 0.4 + |motion y| * 0.2
			$entity->addMotion(-$motion->x * $slowdown, 0, -$motion->z * $slowdown);
		}
	}
}
