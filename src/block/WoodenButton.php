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

namespace pocketmine\block;

use pocketmine\block\utils\WoodMaterial;
use pocketmine\block\utils\WoodTypeTrait;
use pocketmine\entity\Entity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Trident;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

/**
 * Also pressed by arrows and thrown tridents touching it; it stays pressed while they are there.
 */
class WoodenButton extends Button implements WoodMaterial{
	use WoodTypeTrait;

	protected function getActivationTime() : int{
		return 30;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	/**
	 * Returns the button's shape, which arrows must touch to press it.
	 */
	public function getShape() : AxisAlignedBB{
		//2 pixels thick (1 when pressed), 6 pixels wide and 4 pixels high; on the floor and ceiling 6 along X, 4 along Z
		$box = AxisAlignedBB::one()->trim($this->facing, ($this->pressed ? 15 : 14) / 16);
		[$wideAxis, $shortAxis] = match(Facing::axis($this->facing)){
			Axis::Y => [Axis::X, Axis::Z],
			Axis::X => [Axis::Z, Axis::Y],
			default => [Axis::X, Axis::Y],
		};
		return $box
			->squash($wideAxis, 5 / 16)
			->squash($shortAxis, 6 / 16)
			->offset($this->position->x, $this->position->y, $this->position->z);
	}

	private static function isActivatingProjectile(Entity $entity) : bool{
		return ($entity instanceof Arrow || $entity instanceof Trident) && !$entity->isFlaggedForDespawn() && !$entity->isClosed();
	}

	private function hasProjectilesInside() : bool{
		foreach($this->position->getWorld()->getNearbyEntities($this->getShape()) as $entity){
			if(self::isActivatingProjectile($entity)){
				return true;
			}
		}
		return false;
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->pressed && self::isActivatingProjectile($entity) && $entity->getBoundingBox()->intersectsWith($this->getShape())){
			$this->onInteract(VanillaItems::AIR(), $this->facing, new Vector3(0.5, 0.5, 0.5));
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->pressed && $this->hasProjectilesInside()){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, $this->getActivationTime());
			return;
		}
		parent::onScheduledUpdate();
	}

	public function getFuelTime() : int{
		return $this->woodType->isFlammable() ? 100 : 0;
	}
}
