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

use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\block\utils\BellAttachmentType;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

/**
 * Grindstone. Repairs items by combining two of the same item and removes all non-curse enchantments, giving
 * back some experience.
 *
 * The attachment state uses the same values as the bell ("standing", "hanging", "side", "multiple"), so it reuses
 * BellAttachmentType: FLOOR = standing, CEILING = hanging, ONE_WALL = side, TWO_WALLS = multiple.
 * Like in vanilla, a grindstone does not need a supporting block.
 */
final class Grindstone extends Transparent implements HorizontalFacing{
	use HorizontalFacingTrait;

	private BellAttachmentType $attachmentType = BellAttachmentType::FLOOR;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->attachmentType);
		$w->horizontalFacing($this->facing);
	}

	public function getAttachmentType() : BellAttachmentType{ return $this->attachmentType; }

	/** @return $this */
	public function setAttachmentType(BellAttachmentType $attachmentType) : self{
		$this->attachmentType = $attachmentType;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		//the wheel spans the full height (or depth when on a wall) and is 8 pixels wide, with 2 pixel posts on each side
		$wheelAxis = Facing::axis(Facing::rotateY($this->facing, true));
		if($this->attachmentType === BellAttachmentType::FLOOR || $this->attachmentType === BellAttachmentType::CEILING){
			return [AxisAlignedBB::one()->squash($wheelAxis, 2 / 16)->squash(Facing::axis($this->facing), 2 / 16)];
		}
		return [AxisAlignedBB::one()->squash($wheelAxis, 2 / 16)->squash(Axis::Y, 2 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face === Facing::UP || $face === Facing::DOWN){
			if($player !== null){
				$this->setFacing(Facing::opposite($player->getHorizontalFacing()));
			}
			$this->setAttachmentType($face === Facing::UP ? BellAttachmentType::FLOOR : BellAttachmentType::CEILING);
		}else{
			$this->setFacing($face);
			$this->setAttachmentType(BellAttachmentType::ONE_WALL);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$player->setCurrentWindow(new GrindstoneInventory($this->position));
		}
		return true;
	}
}
