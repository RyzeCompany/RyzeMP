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

use pocketmine\entity\Ageable;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;

/**
 * Babies follow the nearest adult of their kind (vanilla behavior.follow_parent): animals, baby dolphins.
 */
class FollowParentGoal extends Goal{
	private ?Mob $parent = null;
	private int $repathDelay = 0;

	public function __construct(
		protected Mob&Ageable $animal,
		protected float $speedMultiplier = 1.1
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if(!$this->animal->isBaby()){
			return false;
		}
		$loc = $this->animal->getLiveLocation();
		$best = null;
		$bestDist = 64.0;
		foreach($this->animal->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 8, $loc->y - 4, $loc->z - 8, $loc->x + 8, $loc->y + 4, $loc->z + 8), $this->animal) as $entity){
			if($entity instanceof Mob && $entity instanceof Ageable && $entity::class === $this->animal::class && !$entity->isBaby()){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		if($best === null || $bestDist < 9){
			return false;
		}
		$this->parent = $best;
		return true;
	}

	public function canContinue() : bool{
		$parent = $this->parent;
		if($parent === null || !$this->animal->isBaby() || !$parent->isAlive() || $parent->isClosed() || $parent->getWorld() !== $this->animal->getWorld()){
			return false;
		}
		$d = $parent->getLocation()->distanceSquared($this->animal->getLiveLocation());
		return $d >= 9 && $d <= 256;
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->parent = null;
		$this->animal->getNavigation()->stop();
	}

	public function tick() : void{
		if(--$this->repathDelay <= 0 && $this->parent !== null){
			$this->repathDelay = 10;
			$this->animal->getNavigation()->moveToEntity($this->parent, $this->speedMultiplier, 2.0);
		}
	}
}
