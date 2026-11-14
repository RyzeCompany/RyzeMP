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

use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\DoorSound;
use pocketmine\world\vibration\GameEvent;

class Door extends Transparent implements HorizontalFacing{
	use HorizontalFacingTrait;

	protected bool $top = false;
	protected bool $hingeRight = false;
	protected bool $open = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->top);
		$w->bool($this->hingeRight);
		$w->bool($this->open);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();

		$this->collisionBoxes = null;

		//copy door properties from other half
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		if($other instanceof Door && $other->hasSameTypeId($this)){
			if($this->top){
				$this->facing = $other->facing;
				$this->open = $other->open;
			}else{
				$this->hingeRight = $other->hingeRight;
			}
		}

		return $this;
	}

	public function isTop() : bool{ return $this->top; }

	/** @return $this */
	public function setTop(bool $top) : self{
		$this->top = $top;
		return $this;
	}

	public function isHingeRight() : bool{ return $this->hingeRight; }

	/** @return $this */
	public function setHingeRight(bool $hingeRight) : self{
		$this->hingeRight = $hingeRight;
		return $this;
	}

	public function isOpen() : bool{ return $this->open; }

	/** @return $this */
	public function setOpen(bool $open) : self{
		$this->open = $open;
		return $this;
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		//TODO: doors are 0.1825 blocks thick, instead of 0.1875 like JE (https://bugs.mojang.com/browse/MCPE-19214)
		return [AxisAlignedBB::one()->trim($this->open ? Facing::rotateY($this->facing, !$this->hingeRight) : $this->facing, 327 / 400)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this) && !$this->getSide(Facing::DOWN) instanceof Door){ //Replace with common break method
			$this->position->getWorld()->useBreakOn($this->position); //this will delete both halves if they exist
			return;
		}
		$this->updateRedstonePower();
	}

	/**
	 * Opens or closes the door when the power received by either half changes.
	 */
	private function updateRedstonePower() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY() - ($this->top ? 1 : 0); //state is remembered at the bottom half
		$z = $this->position->getFloorZ();
		$powered = $redstone->isPowered($x, $y, $z) || $redstone->isPowered($x, $y + 1, $z);
		if($powered === $redstone->getRememberedPowered($x, $y, $z)){
			return;
		}
		$redstone->setRememberedPowered($x, $y, $z, $powered);
		if($powered !== $this->open){
			$this->setOpenAndUpdate($powered);
			$this->position->getWorld()->addGameEvent($this->position, $powered ? GameEvent::BLOCK_OPEN : GameEvent::BLOCK_CLOSE);
		}
	}

	/**
	 * Sets the open state of both halves in the world and plays the door sound.
	 */
	protected function setOpenAndUpdate(bool $open) : void{
		$this->open = $open;
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		$world = $this->position->getWorld();
		if($other instanceof Door && $other->hasSameTypeId($this)){
			$other->open = $this->open;
			$world->setBlock($other->position, $other);
		}
		$world->setBlock($this->position, $this);
		$world->addSound($this->position, new DoorSound());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face === Facing::UP){
			$blockUp = $this->getSide(Facing::UP);
			if(!$blockUp->canBeReplaced() || !$this->canBeSupportedAt($blockReplace)){
				return false;
			}

			if($player !== null){
				$this->facing = $player->getHorizontalFacing();
			}

			$next = $this->getSide(Facing::rotateY($this->facing, false));
			$next2 = $this->getSide(Facing::rotateY($this->facing, true));

			if($next->hasSameTypeId($this) || (!$next2->isTransparent() && $next->isTransparent())){ //Door hinge
				$this->hingeRight = true;
			}

			$topHalf = clone $this;
			$topHalf->top = true;

			$tx->addBlock($blockReplace->position, $this)->addBlock($blockUp->position, $topHalf);
			return true;
		}

		return false;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->setOpenAndUpdate(!$this->open);
		$this->position->getWorld()->addGameEvent($this->position, $this->open ? GameEvent::BLOCK_OPEN : GameEvent::BLOCK_CLOSE, $player);
		return true;
	}

	public function getDrops(Item $item) : array{
		if(!$this->top){
			return parent::getDrops($item);
		}

		return [];
	}

	public function getAffectedBlocks() : array{
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		if($other->hasSameTypeId($this)){
			return [$this, $other];
		}
		return parent::getAffectedBlocks();
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN)->hasEdgeSupport();
	}
}
