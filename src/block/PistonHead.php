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

use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PistonPushReaction;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;

/**
 * Head of an extended piston (piston_arm_collision / sticky_piston_arm_collision). Its facing is the facing of the
 * piston it belongs to.
 */
class PistonHead extends Transparent implements AnyFacing{
	use AnyFacingTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function isSticky() : bool{
		return $this->getTypeId() === BlockTypeIds::STICKY_PISTON_HEAD;
	}

	protected function recalculateCollisionBoxes() : array{
		//head plate on the facing side, and the rod going back towards the piston
		$plate = AxisAlignedBB::one()->trim(Facing::opposite($this->facing), 0.75);
		$rod = AxisAlignedBB::one()->trim($this->facing, 0.25);
		foreach([Axis::X, Axis::Y, Axis::Z] as $axis){
			if($axis !== Facing::axis($this->facing)){
				$rod->squash($axis, 0.375);
			}
		}
		return [$plate, $rod];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === $this->facing ? SupportType::FULL : SupportType::NONE;
	}

	public function getPistonPushReaction() : PistonPushReaction{
		return PistonPushReaction::BLOCK;
	}

	/**
	 * Returns the piston this head belongs to, if any.
	 */
	public function getPiston() : ?Piston{
		$piston = $this->getSide(Facing::opposite($this->facing));
		return $piston instanceof Piston && $piston->getFacing() === $this->facing && $piston->isSticky() === $this->isSticky() ? $piston : null;
	}

	public function getAffectedBlocks() : array{
		$piston = $this->getPiston();
		return $piston !== null ? [$this, $piston] : parent::getAffectedBlocks();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return ($this->isSticky() ? VanillaBlocks::STICKY_PISTON() : VanillaBlocks::PISTON())->asItem();
	}

	public function onNearbyBlockChange() : void{
		if($this->getPiston() === null){
			$this->position->getWorld()->setBlock($this->position, VanillaBlocks::AIR());
		}
	}
}
