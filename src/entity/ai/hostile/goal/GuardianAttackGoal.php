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
use pocketmine\entity\monster\Guardian;

/**
 * The guardian's laser (vanilla behavior.guardian_attack, Java GuardianAttackGoal): the guardian stops and turns to the
 * target; after a 10 tick warm-up it locks its beam on it (TARGET_EID metadata, which makes the client draw the beam,
 * and the beam sound event) and, if it keeps seeing it, hits it when the beam is fully charged:
 * {@link Guardian::getAttackDuration()} ticks later (80 ticks for guardians, 60 for elder guardians). A normal guardian
 * gives up when the target comes within 3 blocks (see {@link Guardian::isTooClose()}).
 */
class GuardianAttackGoal extends Goal{
	public const WARMUP_TICKS = 10;

	private int $attackTime = 0;

	public function __construct(
		protected Guardian $guardian
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$target = $this->guardian->getAttackTarget();
		if($target === null || !$this->guardian->getSensing()->isValidTarget($target, $this->guardian->getFollowRange())){
			return false;
		}
		return !$this->isTooClose();
	}

	public function canContinue() : bool{
		$target = $this->guardian->getAttackTarget();
		if($target === null || !$this->guardian->getSensing()->isValidTarget($target, $this->guardian->getFollowRange()) || $this->isTooClose()){
			return false;
		}
		//the beam, once locked, stays on the target it started on
		$beam = $this->guardian->getBeamTarget();
		return $beam === null || $beam === $target;
	}

	/**
	 * Normal guardians keep their distance: target_nearby_sensor (within 3 blocks: guardian_passive, without
	 * guardian_attack) and Java GuardianAttackGoal.canContinueToUse().
	 */
	private function isTooClose() : bool{
		if($this->guardian->isElder()){
			return false;
		}
		if($this->guardian->isTooClose()){
			return true;
		}
		$target = $this->guardian->getAttackTarget();
		return $target !== null && $target->getLocation()->distanceSquared($this->guardian->getLiveLocation()) <= Guardian::TOO_CLOSE_DISTANCE ** 2;
	}

	public function start() : void{
		$this->attackTime = -self::WARMUP_TICKS;
		$this->guardian->getSwimControl()->stop();
		$target = $this->guardian->getAttackTarget();
		if($target !== null){
			$this->guardian->getLookControl()->setLookAtEntity($target, 90.0, 90.0);
		}
	}

	public function stop() : void{
		$this->guardian->setBeamTarget(null);
		$this->attackTime = 0;
	}

	/**
	 * Ticks since the beam locked on, minus the warm-up (negative while warming up).
	 */
	public function getAttackTime() : int{
		return $this->attackTime;
	}

	public function tick() : void{
		$target = $this->guardian->getAttackTarget();
		if($target === null){
			return;
		}
		$this->guardian->getSwimControl()->stop();
		$this->guardian->getLookControl()->setLookAtEntity($target, 90.0, 90.0);
		if(!$this->guardian->getSensing()->canSee($target)){
			$this->guardian->setAttackTarget(null);
			return;
		}
		//Java: the beam (setActiveAttackTarget) and entity event 21 only once the 10 tick warm-up is over
		if(++$this->attackTime === 0){
			$this->guardian->setBeamTarget($target);
			$this->guardian->onBeamCharging();
		}elseif($this->attackTime >= $this->guardian->getAttackDuration()){
			$this->guardian->fireBeam($target);
			$this->guardian->setBeamTarget(null);
			$this->guardian->setAttackTarget(null);
		}
	}
}
