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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\monster\Creeper;

/**
 * Creepers start their fuse when their target comes within 2.5 blocks and defuse when it gets farther than 6 blocks
 * or out of sight (vanilla behavior.swell).
 */
class SwellGoal extends Goal{

	public function __construct(
		protected Creeper $creeper,
		protected float $startDistance = 2.5,
		protected float $stopDistance = 6.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$target = $this->creeper->getAttackTarget();
		return $this->creeper->getSwellDirection() > 0 ||
			($target !== null && $this->creeper->getLiveLocation()->distanceSquared($target->getLocation()) < $this->startDistance ** 2);
	}

	public function start() : void{
		$this->creeper->getNavigation()->stop();
	}

	public function stop() : void{
		$this->creeper->setSwellDirection(-1);
	}

	public function tick() : void{
		$target = $this->creeper->getAttackTarget();
		if($target === null ||
			$this->creeper->getLiveLocation()->distanceSquared($target->getLocation()) > $this->stopDistance ** 2 ||
			!$this->creeper->getSensing()->canSee($target)
		){
			$this->creeper->setSwellDirection(-1);
		}else{
			$this->creeper->setSwellDirection(1);
			$this->creeper->getLookControl()->setLookAtEntity($target, 30.0);
		}
	}
}
