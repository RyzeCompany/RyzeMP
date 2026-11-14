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

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

/**
 * Pale hanging moss (Bedrock 1.21.50): hangs from the bottom of a full block, leaves or another pale hanging moss.
 * The lowest block of a strand is the tip. Bone meal extends the strand downwards by one block.
 * Only drops with shears or silk touch.
 */
class PaleHangingMoss extends Flowable{
	protected bool $tip = true;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->tip);
	}

	public function isTip() : bool{ return $this->tip; }

	/** @return $this */
	public function setTip(bool $tip) : self{
		$this->tip = $tip;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$above = $block->getSide(Facing::UP);
		return $above instanceof PaleHangingMoss || $above instanceof Leaves || $above->getSupportType(Facing::DOWN) === SupportType::FULL;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return $this->canBeSupportedAt($blockReplace) && parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->tip = !($blockReplace->getSide(Facing::DOWN) instanceof PaleHangingMoss);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if(!$this->canBeSupportedAt($this)){
			$world->useBreakOn($this->position);
			return;
		}
		$tip = !($this->getSide(Facing::DOWN) instanceof PaleHangingMoss);
		if($tip !== $this->tip){
			$this->tip = $tip;
			$world->setBlock($this->position, $this);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer){
			return false;
		}
		$bottom = $this;
		while(($next = $bottom->getSide(Facing::DOWN)) instanceof PaleHangingMoss){
			$bottom = $next;
		}
		$target = $bottom->getSide(Facing::DOWN);
		if($target->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$newState = (clone $this)->setTip(true);
		if(BlockEventHelper::grow($target, $newState, $player)){
			$item->pop();
		}
		return true;
	}

	public function getDropsForIncompatibleTool(Item $item) : array{
		if($item->hasEnchantment(VanillaEnchantments::SILK_TOUCH())){
			return $this->getDropsForCompatibleTool($item);
		}
		return [];
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}

	public function getCompostabilityChance() : int{
		return 30;
	}
}
