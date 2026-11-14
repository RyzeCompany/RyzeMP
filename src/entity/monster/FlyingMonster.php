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
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;

/**
 * A hostile mob that flies (ghast, vex, phantom): no gravity, no fall damage, moved by a {@link FreeMoveControl}
 * instead of walking pathfinding. When nothing steers it, it drifts to a stop and hovers.
 *
 * Note: phase 2 mobs may add their own flying base for passive flyers; this one is kept separate on purpose.
 */
abstract class FlyingMonster extends Monster{
	protected FreeMoveControl $flight;

	protected function initEntity(CompoundTag $nbt) : void{
		$this->flight = new FreeMoveControl($this, $this->getFlightAcceleration());
		parent::initEntity($nbt);
		$this->setHasGravity(false);
	}

	/**
	 * How fast the mob's motion turns towards where it wants to go (0-1 per tick).
	 */
	protected function getFlightAcceleration() : float{
		return 0.1;
	}

	public function getFlightControl() : FreeMoveControl{
		return $this->flight;
	}

	protected function tryChangeMovement() : void{
		if(!$this->isAlive()){
			//dead flyers fall
			$this->motion = $this->motion->withComponents($this->motion->x * 0.9, $this->motion->y - 0.08, $this->motion->z * 0.9);
			return;
		}
		$this->motion = $this->flight->computeMotion($this->motion, $this->getIdleDrag());
	}

	/**
	 * Motion multiplier per tick when not steered.
	 */
	protected function getIdleDrag() : float{
		return 0.91;
	}

	public function hasMovementUpdate() : bool{
		return $this->isAlive() || parent::hasMovementUpdate();
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_FLY, true);
	}

	protected function destroyCycles() : void{
		unset($this->flight);
		parent::destroyCycles();
	}
}
