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

use pocketmine\block\Bed;
use pocketmine\entity\animal\Cat;
use pocketmine\math\Vector3;
use function abs;

/**
 * A tamed cat goes to sleep on the bed its owner is sleeping in (vanilla behavior.pet_sleep_with_owner, speed 1.2):
 * it walks to the foot of the bed, hops on it and sleeps there until the owner wakes up, which makes it want to give
 * its owner a morning gift (on_wake_with_owner, see {@link DropGiftForOwnerGoal}). Sitting cats stay where they are.
 */
class PetSleepWithOwnerGoal extends Goal{
	public const SPEED = 1.2;
	/** Owner search box: 16 blocks sideways, 10 up or down (search_height). */
	public const SEARCH_RANGE = 16;
	public const SEARCH_HEIGHT = 10;
	/** Ticks to reach the bed before giving up. */
	public const GIVE_UP_TICKS = 400;

	private ?Vector3 $bed = null;
	private bool $lying = false;
	private int $giveUpTick = 0;
	private int $repathTick = 0;

	public function __construct(
		protected Cat $cat
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		$this->bed = $this->findOwnerBed();
		return $this->bed !== null;
	}

	/**
	 * The block of the owner's bed the cat lies on (the foot, or the head of a broken bed), if the owner is asleep
	 * nearby.
	 */
	private function findOwnerBed() : ?Vector3{
		$cat = $this->cat;
		if(!$cat->isTamed() || $cat->isSitting()){
			return null;
		}
		$owner = $cat->getOwner();
		$pos = $owner?->getSleepingPosition();
		if($pos === null){
			return null;
		}
		$loc = $cat->getLiveLocation();
		if(abs($pos->x + 0.5 - $loc->x) > self::SEARCH_RANGE || abs($pos->z + 0.5 - $loc->z) > self::SEARCH_RANGE || abs($pos->y - $loc->y) > self::SEARCH_HEIGHT){
			return null;
		}
		$bed = $cat->getWorld()->getBlock($pos);
		if(!$bed instanceof Bed){
			return null;
		}
		$foot = $bed->getOtherHalf();
		return ($foot ?? $bed)->getPosition()->asVector3();
	}

	public function canContinue() : bool{
		$cat = $this->cat;
		if(!$cat->isTamed() || $cat->isSitting() || $this->bed === null){
			return false;
		}
		$owner = $cat->getOwner();
		if($owner === null || !$owner->isSleeping()){
			return false;
		}
		return $this->lying || $cat->getAiTick() < $this->giveUpTick;
	}

	public function start() : void{
		$this->lying = false;
		$this->giveUpTick = $this->cat->getAiTick() + self::GIVE_UP_TICKS;
		$this->repathTick = 0;
	}

	public function stop() : void{
		if($this->lying){
			$owner = $this->cat->getOwner();
			//the owner woke up (rather than left or the cat being told to sit): morning gift
			$this->cat->stopSleepingWithOwner($owner !== null && !$owner->isSleeping());
		}
		$this->lying = false;
		$this->bed = null;
		$this->cat->getNavigation()->stop();
	}

	public function isLying() : bool{
		return $this->lying;
	}

	public function tick() : void{
		$bed = $this->bed;
		if($bed === null || $this->lying){
			return;
		}
		$cat = $this->cat;
		$loc = $cat->getLiveLocation();
		$dx = $bed->x + 0.5 - $loc->x;
		$dz = $bed->z + 0.5 - $loc->z;
		if($dx * $dx + $dz * $dz <= 2.25 && abs($bed->y - $loc->y) < 1.5){
			$cat->getNavigation()->stop();
			$cat->sleepWithOwnerOn($bed);
			$this->lying = true;
			return;
		}
		$now = $cat->getAiTick();
		if($cat->getNavigation()->isDone() && $now >= $this->repathTick){
			$this->repathTick = $now + 20;
			$cat->getNavigation()->moveTo($bed->x + 0.5, $bed->y, $bed->z + 0.5, self::SPEED, 1.0);
		}
	}
}
