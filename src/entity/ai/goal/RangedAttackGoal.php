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

use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use function mt_rand;
use function sqrt;

/**
 * Keeps the target at range, strafing around it, and shoots it at a fixed interval (vanilla behavior.ranged_attack
 * with skeleton strafing). The shot itself is done by the callback.
 */
class RangedAttackGoal extends Goal{
	private int $seeTime = 0;
	private int $attackTime = -1;
	private int $strafeTime = -1;
	private bool $strafeClockwise = false;
	private bool $strafeBackwards = false;

	/**
	 * @param int $maxAttackInterval if greater than the interval, each wait is random between the interval and this
	 *                               (vanilla attack_interval {min, max})
	 * @phpstan-param \Closure(Living $target, float $distance) : void $shoot
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected int $attackInterval,
		protected int $hardAttackInterval,
		protected float $attackRange,
		protected \Closure $shoot,
		protected int $maxAttackInterval = 0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->attackRange + 16);
	}

	public function stop() : void{
		$this->seeTime = 0;
		$this->attackTime = -1;
		$this->mob->getNavigation()->stop();
	}

	private function interval() : int{
		$interval = $this->mob->getWorld()->getDifficulty() === World::DIFFICULTY_HARD ? $this->hardAttackInterval : $this->attackInterval;
		return $this->maxAttackInterval > $interval ? mt_rand($interval, $this->maxAttackInterval) : $interval;
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		if($target === null){
			return;
		}
		//every tick of every shooting mob: no copy of the target's location
		$distSq = $this->mob->getLiveLocation()->distanceSquared(Mob::getLiveLocationOf($target) ?? $target->getLocation());
		$canSee = $this->mob->getSensing()->canSee($target);
		if($canSee !== ($this->seeTime > 0)){
			$this->seeTime = 0;
		}
		$this->seeTime += $canSee ? 1 : -1;

		$rangeSq = $this->attackRange * $this->attackRange;
		if($distSq <= $rangeSq && $this->seeTime >= 20){
			$this->mob->getNavigation()->stop();
			++$this->strafeTime;
		}else{
			$this->mob->getNavigation()->moveToEntity($target, $this->speedMultiplier, 0.0);
			$this->strafeTime = -1;
		}

		if($this->strafeTime >= 20){
			if(Utils::getRandomFloat() < 0.3){
				$this->strafeClockwise = !$this->strafeClockwise;
			}
			if(Utils::getRandomFloat() < 0.3){
				$this->strafeBackwards = !$this->strafeBackwards;
			}
			$this->strafeTime = 0;
		}
		if($this->strafeTime > -1){
			if($distSq > $rangeSq * 0.75){
				$this->strafeBackwards = false;
			}elseif($distSq < $rangeSq * 0.25){
				$this->strafeBackwards = true;
			}
			$this->mob->getMoveControl()->strafe($this->strafeBackwards ? -0.5 : 0.5, $this->strafeClockwise ? 0.5 : -0.5, $this->speedMultiplier);
		}
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		//strafing moves relative to the body: keep it facing the target
		$this->mob->setBodyYaw($this->mob->getHeadYaw());

		if($this->attackTime < 0){
			$this->attackTime = $this->interval();
		}
		if(--$this->attackTime <= 0){
			if(!$canSee || $distSq > $rangeSq){
				$this->attackTime = 1; //shoot as soon as possible
				return;
			}
			($this->shoot)($target, sqrt($distSq));
			$this->attackTime = $this->interval();
		}
	}
}
