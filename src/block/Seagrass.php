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
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

/**
 * Seagrass (https://minecraft.wiki/w/Seagrass): placed inside a water source on top of a block with a full top face.
 * Only drops with shears. Bone meal turns it into tall seagrass when the space above is a water source.
 */
class Seagrass extends Flowable implements Waterlogged{
	use AlwaysWaterloggedTrait;
	use StaticSupportTrait {
		onNearbyBlockChange as onSupportBlockChange;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return WaterloggedHelper::isWaterSource($blockReplace) && !$blockReplace instanceof Waterlogged && $this->canBeSupportedAt($blockReplace);
	}

	public function onNearbyBlockChange() : void{
		$this->onSupportBlockChange();
		if($this->position->getWorld()->getBlock($this->position)->hasSameTypeId($this)){
			WaterloggedHelper::scheduleFlow($this);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			$world = $this->position->getWorld();
			$abovePos = $this->position->getSide(Facing::UP);
			if(!$world->isInWorld($abovePos->getFloorX(), $abovePos->getFloorY(), $abovePos->getFloorZ())){
				return true;
			}
			$above = $world->getBlock($abovePos);
			if(!WaterloggedHelper::isWaterSource($above) || $above instanceof Waterlogged){
				return true;
			}
			$tx = new BlockTransaction($world);
			$tx->addBlock($this->position, VanillaBlocks::TALL_SEAGRASS()->setTop(false));
			$tx->addBlock($abovePos, VanillaBlocks::TALL_SEAGRASS()->setTop(true));
			$ev = new StructureGrowEvent($this, $tx, $player);
			$ev->call();
			if(!$ev->isCancelled() && $tx->apply()){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function getCompostabilityChance() : int{
		return 30;
	}
}
