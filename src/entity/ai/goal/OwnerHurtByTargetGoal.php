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

use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

/**
 * A tamed animal attacks whoever hurt its owner (vanilla behavior.owner_hurt_by_target), or, with $ownerAttacks, what
 * its owner attacked (behavior.owner_hurt_target).
 */
class OwnerHurtByTargetGoal extends Goal{
	private ?EntityDamageEvent $handledEvent = null;
	private ?Living $target = null;
	private int $scanCounter = 0;

	public function __construct(
		protected TameableAnimal $animal,
		protected bool $ownerAttacks = false,
		protected float $range = 32.0
	){}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	public function canStart() : bool{
		if($this->animal->isSitting()){
			return false;
		}
		$owner = $this->animal->getOwner();
		if($owner === null){
			return false;
		}
		$event = null;
		$target = null;
		if($this->ownerAttacks){
			if(++$this->scanCounter % 3 !== 0){
				return false; //scanning the owner's surroundings: every ~10 ticks is enough
			}
			$hit = $this->findEntityHitByOwner();
			if($hit !== null){
				[$target, $event] = $hit;
			}
		}else{
			$event = $owner->getLastDamageCause();
			if($event instanceof EntityDamageByEntityEvent){
				$target = $event->getDamager();
			}
		}
		if($event === null || $event === $this->handledEvent || !$target instanceof Living || $target === $this->animal || $target === $owner){
			return false;
		}
		if($target instanceof TameableAnimal && $target->isOwner($owner)){
			return false;
		}
		if(!$this->animal->getSensing()->isValidTarget($target, $this->range)){
			return false;
		}
		$this->handledEvent = $event;
		$this->target = $target;
		return true;
	}

	/**
	 * Looks for a mob near the animal last hit by the owner.
	 *
	 * @phpstan-return array{Living, EntityDamageEvent}|null
	 */
	private function findEntityHitByOwner() : ?array{
		$owner = $this->animal->getOwner();
		if($owner === null){
			return null;
		}
		$loc = $this->animal->getLiveLocation();
		$r = 16;
		foreach($this->animal->getWorld()->getNearbyEntities($owner->getBoundingBox()->expandedCopy($r, 4, $r), $this->animal) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || $entity === $owner){
				continue;
			}
			$cause = $entity->getLastDamageCause();
			if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() === $owner && $cause !== $this->handledEvent && $entity->getLocation()->distanceSquared($loc) <= $this->range ** 2){
				return [$entity, $cause];
			}
		}
		return null;
	}

	public function canContinue() : bool{
		$target = $this->animal->getAttackTarget();
		return $target !== null && $target === $this->target && !$this->animal->isSitting() && $this->animal->getSensing()->isValidTarget($target, $this->range);
	}

	public function start() : void{
		$this->animal->setAttackTarget($this->target);
	}

	public function stop() : void{
		if($this->animal->getAttackTarget() === $this->target){
			$this->animal->setAttackTarget(null);
		}
		$this->target = null;
	}
}
