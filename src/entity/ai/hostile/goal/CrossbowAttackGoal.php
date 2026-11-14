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
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use function mt_rand;
use function sqrt;

/**
 * Crossbow users (pillagers, piglins): load the crossbow (25 ticks, vanilla charge_held_item), wait 1-2 seconds, then
 * shoot; walk to the target while it's out of range or out of sight and back off when it's too close.
 */
class CrossbowAttackGoal extends Goal{
	public const CHARGE_TICKS = 25;

	private const STATE_UNCHARGED = 0;
	private const STATE_CHARGING = 1;
	private const STATE_CHARGED = 2;

	private int $state = self::STATE_UNCHARGED;
	private int $timer = 0;
	private int $seeTime = 0;

	/**
	 * @phpstan-param \Closure(bool $charging) : void $onChargingChange
	 * @phpstan-param \Closure(Living $target, float $distance) : void $shoot
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected float $attackRange,
		protected float $minRange,
		protected \Closure $onChargingChange,
		protected \Closure $shoot
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->attackRange + 16);
	}

	public function stop() : void{
		if($this->state === self::STATE_CHARGING){
			($this->onChargingChange)(false);
		}
		$this->state = self::STATE_UNCHARGED;
		$this->seeTime = 0;
		$this->mob->getNavigation()->stop();
	}

	public function isCharged() : bool{
		return $this->state === self::STATE_CHARGED;
	}

	public function tick() : void{
		$target = $this->mob->getAttackTarget();
		if($target === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
		$distSq = $this->mob->getLiveLocation()->distanceSquared(Mob::getLiveLocationOf($target) ?? $target->getLocation());
		$canSee = $this->mob->getSensing()->canSee($target);
		$this->seeTime = $canSee ? $this->seeTime + 1 : 0;
		$inRange = $distSq <= $this->attackRange ** 2;

		if(!$inRange || $this->seeTime < 5){
			$this->mob->getNavigation()->moveToEntity($target, $this->speedMultiplier, 0.0);
		}elseif($distSq < $this->minRange ** 2){
			$this->mob->getNavigation()->stop();
			$this->mob->getMoveControl()->strafe(-0.5, 0, $this->speedMultiplier);
			$this->mob->setBodyYaw($this->mob->getHeadYaw());
		}else{
			$this->mob->getNavigation()->stop();
		}

		switch($this->state){
			case self::STATE_UNCHARGED:
				if($canSee && $distSq <= ($this->attackRange * 1.5) ** 2){
					$this->state = self::STATE_CHARGING;
					$this->timer = 0;
					($this->onChargingChange)(true);
				}
				break;
			case self::STATE_CHARGING:
				if(++$this->timer >= self::CHARGE_TICKS){
					$this->state = self::STATE_CHARGED;
					$this->timer = 20 + mt_rand(0, 19);
					($this->onChargingChange)(false);
				}
				break;
			case self::STATE_CHARGED:
				if(--$this->timer <= 0 && $canSee && $inRange){
					($this->shoot)($target, sqrt($distSq));
					$this->state = self::STATE_UNCHARGED;
				}
				break;
		}
	}
}
