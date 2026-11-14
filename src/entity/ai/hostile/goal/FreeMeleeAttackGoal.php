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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\hostile\FreeMoveControl;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;

/**
 * Flies or swims straight at the attack target and hits it when their bounding boxes (grown by $reach) touch
 * (vanilla charge_attack of vexes, aquatic charges, drowned swimming at their target).
 */
class FreeMeleeAttackGoal extends Goal{
	private int $cooldown = 0;

	/**
	 * @phpstan-param (\Closure() : bool)|null $condition
	 * @phpstan-param (\Closure(Living) : void)|null $onHit
	 */
	public function __construct(
		protected Mob $mob,
		protected FreeMoveControl $control,
		protected float $speed,
		protected float $damage,
		protected float $reach = 0.2,
		protected int $attackInterval = 20,
		protected float $followRange = 32.0,
		protected ?\Closure $condition = null,
		protected ?\Closure $onHit = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	/**
	 * @param float $speed blocks per tick (e.g. a baby drowned swims faster)
	 */
	public function setSpeed(float $speed) : void{
		$this->speed = $speed;
	}

	public function canStart() : bool{
		return ($this->condition === null || ($this->condition)()) && $this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->followRange);
	}

	public function stop() : void{
		$this->control->stop();
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		if($target === null){
			return;
		}
		$loc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$this->control->setWantedPosition($loc->x, $loc->y + $target->getSize()->getHeight() * 0.5, $loc->z, $this->speed);
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		if($this->cooldown > 0){
			--$this->cooldown;
			return;
		}
		if($this->mob->getBoundingBox()->expandedCopy($this->reach, $this->reach, $this->reach)->intersectsWith($target->getBoundingBox())){
			$this->cooldown = $this->attackInterval;
			if($this->damage > 0 && $this->mob->attackMelee($target, $this->damage) && $this->onHit !== null){
				($this->onHit)($target);
			}
		}
	}
}
