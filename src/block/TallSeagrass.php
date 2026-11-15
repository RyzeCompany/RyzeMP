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

use pocketmine\block\utils\AlwaysWaterloggedTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

/**
 * Tall seagrass: two blocks (minecraft:seagrass with sea_grass_type double_bot / double_top), grown from seagrass with
 * bone meal. It has no item of its own; with shears it drops two seagrass (Bedrock), otherwise nothing.
 */
class TallSeagrass extends Flowable implements Waterlogged{
	use AlwaysWaterloggedTrait;

	protected bool $top = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->top);
	}

	public function isTop() : bool{ return $this->top; }

	/** @return $this */
	public function setTop(bool $top) : self{
		$this->top = $top;
		return $this;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return false; //only grown from seagrass
	}

	private function isValidHalf() : bool{
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);
		if(!$other instanceof TallSeagrass || $other->top === $this->top){
			return false;
		}
		return $this->top || $this->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->isValidHalf()){
			$this->position->getWorld()->useBreakOn($this->position);
		}else{
			WaterloggedHelper::scheduleFlow($this);
		}
	}

	public function getAffectedBlocks() : array{
		if($this->isValidHalf()){
			return [$this, $this->getSide($this->top ? Facing::DOWN : Facing::UP)];
		}
		return parent::getAffectedBlocks();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->top ? [VanillaBlocks::SEAGRASS()->asItem()->setCount(2)] : [];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return VanillaBlocks::SEAGRASS()->asItem();
	}

	public function asItem() : Item{
		return VanillaBlocks::SEAGRASS()->asItem();
	}
}
