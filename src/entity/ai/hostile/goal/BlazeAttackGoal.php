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
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Blaze;

/**
 * The blaze's attack (Java BlazeAttackGoal; Bedrock blaze.json: melee 6 within 2 blocks, otherwise bursts of 3 small
 * fireballs 0.3 seconds apart every 3-5 seconds within 48 blocks): it lights up for 3 seconds, shoots three fireballs
 * and cools down for 5 seconds.
 */
class BlazeAttackGoal extends Goal{
	public const CHARGE_TICKS = 60;
	public const BURST_INTERVAL = 6;
	public const COOLDOWN_TICKS = 100;

	private int $attackStep = 0;
	private int $attackTime = 0;
	private int $lastSeen = 0;

	public function __construct(
		protected Blaze $blaze
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->blaze->getSensing()->isValidTarget($this->blaze->getAttackTarget(), Blaze::FOLLOW_RANGE);
	}

	public function start() : void{
		$this->attackStep = 0;
	}

	public function stop() : void{
		$this->blaze->setCharged(false);
		$this->lastSeen = 0;
		$this->blaze->getNavigation()->stop();
	}

	public function tick() : void{
		--$this->attackTime;
		$target = $this->blaze->getAttackTarget();
		if($target === null){
			return;
		}
		$canSee = $this->blaze->getSensing()->canSee($target);
		$this->lastSeen = $canSee ? 0 : $this->lastSeen + 1;
		//no copies of the target's location: its coordinates are read before the hit (which may move it, e.g. an enderman
		//teleporting away)
		$tLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$tx = $tLoc->x;
		$ty = $tLoc->y;
		$tz = $tLoc->z;
		$distSq = $this->blaze->getLiveLocation()->distanceSquared($tLoc);

		if($distSq < 4){
			if(!$canSee){
				return;
			}
			if($this->attackTime <= 0){
				$this->attackTime = 20;
				$this->blaze->attackMelee($target, Blaze::MELEE_DAMAGE);
			}
			$this->blaze->getMoveControl()->setWantedPosition($tx, $ty, $tz, 1.0);
		}elseif($distSq < Blaze::FOLLOW_RANGE ** 2 && $canSee){
			if($this->attackTime <= 0){
				++$this->attackStep;
				if($this->attackStep === 1){
					$this->attackTime = self::CHARGE_TICKS;
					$this->blaze->setCharged(true);
				}elseif($this->attackStep <= 4){
					$this->attackTime = self::BURST_INTERVAL;
				}else{
					$this->attackTime = self::COOLDOWN_TICKS;
					$this->attackStep = 0;
					$this->blaze->setCharged(false);
				}
				if($this->attackStep > 1){
					$this->blaze->shootFireball($target, $distSq);
				}
			}
			$this->blaze->getNavigation()->stop();
			$this->blaze->getLookControl()->setLookAtEntity($target, 10.0, 10.0);
		}elseif($this->lastSeen < 5){
			$this->blaze->getNavigation()->moveToEntity($target, 1.0, 0.0);
		}
	}
}
