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
use pocketmine\entity\monster\Ghast;

/**
 * The ghast's fireballs (Java GhastShootFireballGoal, Bedrock behavior.ranged_attack with charge triggers): while it
 * sees its target within 64 blocks it charges for 20 ticks (opening its mouth after 10, with a warning sound) and
 * shoots, then waits 40 ticks. Without line of sight the charge winds down.
 */
class GhastShootGoal extends Goal{
	public const RANGE = 64.0;
	public const WARN_TICK = 10;
	public const SHOOT_TICK = 20;
	public const COOLDOWN = 40;

	private int $chargeTime = 0;

	public function __construct(
		protected Ghast $ghast
	){}

	public function getFlags() : int{
		return self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->ghast->getSensing()->isValidTarget($this->ghast->getAttackTarget(), self::RANGE);
	}

	public function start() : void{
		$this->chargeTime = 0;
	}

	public function stop() : void{
		$this->ghast->setCharging(false);
	}

	public function getChargeTime() : int{
		return $this->chargeTime;
	}

	public function tick() : void{
		$target = $this->ghast->getAttackTarget();
		if($target === null){
			return;
		}
		$this->ghast->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		//ghasts face their target with their whole body
		$this->ghast->setBodyYaw($this->ghast->getHeadYaw());
		if($this->ghast->getSensing()->canSee($target)){
			++$this->chargeTime;
			if($this->chargeTime === self::WARN_TICK){
				$this->ghast->warn();
			}
			if($this->chargeTime >= self::SHOOT_TICK){
				$this->ghast->shootFireball($target);
				$this->chargeTime = -self::COOLDOWN;
			}
		}elseif($this->chargeTime > 0){
			--$this->chargeTime;
		}
		$this->ghast->setCharging($this->chargeTime > self::WARN_TICK);
	}
}
