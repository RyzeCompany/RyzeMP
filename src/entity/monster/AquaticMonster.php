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

namespace pocketmine\entity\monster;

use pocketmine\entity\ai\hostile\FreeMoveControl;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use function mt_getrandmax;
use function mt_rand;

/**
 * A hostile mob that lives in water (guardians, zombie nautilus): swims freely in three dimensions with a
 * {@link FreeMoveControl} while in water, falls and flops around out of water, and suffocates when out of water for
 * too long (unless {@link AquaticMonster::breathesAir()}).
 */
abstract class AquaticMonster extends Monster{
	protected FreeMoveControl $swim;

	protected function initEntity(CompoundTag $nbt) : void{
		$this->swim = new FreeMoveControl($this, 0.1);
		parent::initEntity($nbt);
	}

	public function getSwimControl() : FreeMoveControl{
		return $this->swim;
	}

	/**
	 * Returns whether the mob is in water (feet or eyes).
	 */
	public function isInWaterBody() : bool{
		return $this->isInWater() || $this->isUnderwater();
	}

	public function breathesAir() : bool{
		return false;
	}

	public function canBreathe() : bool{
		return $this->breathesAir() || $this->isInWaterBody();
	}

	public function onAirExpired() : void{
		$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 2));
	}

	protected function tryChangeMovement() : void{
		if(!$this->isAlive()){
			parent::tryChangeMovement();
			return;
		}
		if($this->isInWaterBody()){
			$this->setHasGravity(false);
			$this->motion = $this->swim->computeMotion($this->motion, 0.9);
			//mobs at rest slowly sink, like vanilla guardians
			if(!$this->swim->hasWanted() && $this->sinksAtRest()){
				$this->motion = $this->motion->withComponents(null, $this->motion->y - 0.005, null);
			}
			return;
		}
		$this->setHasGravity(true);
		$this->swim->stop();
		$this->motion = new Vector3($this->motion->x * 0.9, $this->motion->y - 0.08, $this->motion->z * 0.9);
		if($this->onGround && mt_rand(0, 9) === 0){
			//flop around
			$this->motion = new Vector3((mt_rand() / mt_getrandmax() - 0.5) * 0.4, 0.4, (mt_rand() / mt_getrandmax() - 0.5) * 0.4);
		}
	}

	/**
	 * Returns whether the mob slowly sinks while it isn't swimming anywhere (checked only then).
	 */
	protected function sinksAtRest() : bool{
		return true;
	}

	public function hasMovementUpdate() : bool{
		return $this->isAlive() || parent::hasMovementUpdate();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SWIMMER, true);
	}

	protected function destroyCycles() : void{
		unset($this->swim);
		parent::destroyCycles();
	}
}
