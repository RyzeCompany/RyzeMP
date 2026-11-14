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

use pocketmine\entity\animal\Animal;
use pocketmine\math\AxisAlignedBB;

/**
 * Walks to another animal of the same kind in love and makes a baby with it (vanilla behavior.breed).
 */
class BreedGoal extends Goal{
	/** BDS 1.26.60: partners in love 19.8 blocks apart still find each other, 21.2 don't (Java: 8). */
	private const SEARCH_RANGE = 20.0;
	private const BREED_DISTANCE = 3.0;
	/** Ticks the partners must stay close before the baby appears (vanilla: 60). */
	private const BREED_TICKS = 60;

	private ?Animal $partner = null;
	private int $loveTime = 0;

	public function __construct(
		protected Animal $animal,
		protected float $speedMultiplier = 1.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		if(!$this->animal->isInLove()){
			return false;
		}
		$this->partner = $this->findPartner();
		return $this->partner !== null;
	}

	public function canContinue() : bool{
		$partner = $this->partner;
		return $partner !== null && $this->loveTime < self::BREED_TICKS * 2 && $this->animal->canMate($partner);
	}

	public function stop() : void{
		$this->partner = null;
		$this->loveTime = 0;
		$this->animal->getNavigation()->stop();
	}

	public function tick() : void{
		$partner = $this->partner;
		if($partner === null){
			return;
		}
		$this->animal->getLookControl()->setLookAtEntity($partner);
		$this->animal->getNavigation()->moveToEntity($partner, $this->speedMultiplier, 1.0);
		++$this->loveTime;
		if($this->loveTime >= self::BREED_TICKS &&
			$this->animal->getLiveLocation()->distanceSquared($partner->getLocation()) < self::BREED_DISTANCE * self::BREED_DISTANCE &&
			$this->animal->canMate($partner)
		){
			$this->animal->breed($partner);
		}
	}

	private function findPartner() : ?Animal{
		$loc = $this->animal->getLiveLocation();
		$r = self::SEARCH_RANGE;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->animal->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this->animal) as $entity){
			if($entity instanceof Animal && $this->animal->canMate($entity)){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}
}
