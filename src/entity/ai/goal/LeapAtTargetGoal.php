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

use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use function mt_rand;
use function sqrt;

/**
 * Pounces at the target when it's 2 to 4 blocks away, now and then (vanilla behavior.leap_at_target: spiders, wolves,
 * cats; one chance in 5 per goal evaluation like Java's LeapAtTargetGoal).
 *
 * Like in Java the leap takes the movement and must be registered with a higher priority than the melee attack: it
 * interrupts the chase for the time of the leap (no path steering the mob in the air), and the melee attack starts again
 * when the mob lands, its attack cooldown having run on meanwhile. Bedrock lists the spider's leap below its melee attack
 * (melee 3, leap 4), which with the movement held by the attack would never let the leap start.
 */
class LeapAtTargetGoal extends Goal{

	/**
	 * @param float $yd             vertical motion of the leap (Bedrock yd)
	 * @param bool  $mustBeOnGround whether the mob only leaps from the ground (Bedrock must_be_on_ground; spiders leap
	 *                              off walls too)
	 * @param float $targetDist     horizontal motion of the leap towards the target (Bedrock target_dist)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $yd = 0.4,
		protected bool $mustBeOnGround = true,
		protected float $targetDist = 0.3
	){}

	public function getFlags() : int{
		return self::FLAG_JUMP | self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$target = $this->mob->getAttackTarget();
		if($target === null || ($this->mustBeOnGround && !$this->mob->isOnGround()) || $this->mob->getControllingPassenger() !== null){
			//Java: no leap while ridden (spider jockeys)
			return false;
		}
		$loc = $this->mob->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$dx = $targetLoc->x - $loc->x;
		$dy = $targetLoc->y - $loc->y;
		$dz = $targetLoc->z - $loc->z;
		$horizontalSq = $dx * $dx + $dz * $dz;
		$d = $horizontalSq + $dy * $dy;
		//the leap pushes sideways: none at a target mostly above or below (a spider on a wall over it would jump past it)
		return $d >= 4 && $d <= 16 && $horizontalSq >= $dy * $dy && mt_rand(0, 4) === 0;
	}

	public function canContinue() : bool{
		return !$this->mob->isOnGround();
	}

	public function start() : void{
		$target = $this->mob->getAttackTarget();
		if($target === null){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
		$dx = $targetLoc->x - $loc->x;
		$dz = $targetLoc->z - $loc->z;
		$len = sqrt($dx * $dx + $dz * $dz);
		if($len < 0.0001){
			return;
		}
		$motion = $this->mob->getMotion();
		$this->mob->getNavigation()->stop();
		$this->mob->setMotion(new Vector3(
			$dx / $len * $this->targetDist + $motion->x * 0.2,
			$this->yd,
			$dz / $len * $this->targetDist + $motion->z * 0.2
		));
	}
}
