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

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Illager;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\MobSound;

/**
 * Evoker fangs (minecraft:evocation_fang): rise from the ground, bite once (6 magic damage to every living entity
 * touching them except their evoker and illagers) and vanish after 1.1 seconds (vanilla entity_lifespan).
 */
class EvokerFangs extends Entity implements NeverSavedWithChunkEntity{
	public const DAMAGE = 6.0;
	public const BITE_TICK = 4;
	public const LIFETIME_TICKS = 22;

	private bool $bitten = false;

	public static function getNetworkTypeId() : string{ return EntityIds::EVOCATION_FANG; }

	public function __construct(Location $location, ?Entity $owner){
		parent::__construct($location);
		if($owner !== null){
			$this->setOwningEntity($owner);
		}
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.8, 0.5); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	public function hasMovementUpdate() : bool{
		return false;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::BITE_TICK && !$this->bitten){
			$this->bitten = true;
			$this->bite();
		}
		if($this->ticksLived >= self::LIFETIME_TICKS){
			$this->flagForDespawn();
		}
		return true;
	}

	private function bite() : void{
		$this->broadcastSound(new MobSound(LevelSoundEvent::FANG, self::getNetworkTypeId()));
		$owner = $this->getOwningEntity();
		foreach($this->getWorld()->getCollidingEntities($this->boundingBox->expandedCopy(0.2, 0, 0.2), $this) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || $entity === $owner || $entity instanceof Illager){
				continue;
			}
			$entity->attack($owner !== null ?
				new EntityDamageByEntityEvent($owner, $entity, EntityDamageEvent::CAUSE_MAGIC, self::DAMAGE) :
				new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGIC, self::DAMAGE));
		}
	}

	public function hasBitten() : bool{
		return $this->bitten;
	}
}
