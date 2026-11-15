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

use pocketmine\block\utils\Ageable;
use pocketmine\block\utils\AgeableTrait;
use pocketmine\block\utils\AlwaysWaterloggedTrait;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function mt_rand;

/**
 * Kelp grows in columns inside water sources. Bedrock has a single block for the whole column (kelp_age); the client
 * draws the top of the column differently on its own.
 *
 * Vanilla (https://minecraft.wiki/w/Kelp): placed on top of a block with a full top face (not magma) or on kelp, gets a
 * random age 0-24 when placed, and the top of a column grows one block with a 14% chance per random tick while its age
 * is below 25 and the space above is a water source; the new block's age is one higher. Bone meal grows the column by
 * one block. Kelp leaves its water behind when broken, and drops itself.
 */
class Kelp extends Flowable implements Ageable, Waterlogged{
	use AgeableTrait;
	use AlwaysWaterloggedTrait;
	use StaticSupportTrait {
		onNearbyBlockChange as onSupportBlockChange;
	}

	public const MAX_AGE = 25;
	/** Chance (in %) that the top of a column grows on a random tick. */
	public const GROWTH_CHANCE = 14;

	private function canBeSupportedAt(Block $block) : bool{
		$down = $block->getSide(Facing::DOWN);
		return $down->hasSameTypeId($this) || ($down->getSupportType(Facing::UP) === SupportType::FULL && !$down instanceof Magma);
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return WaterloggedHelper::isWaterSource($blockReplace) && !$blockReplace instanceof Waterlogged && $this->canBeSupportedAt($blockReplace);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->age = mt_rand(0, self::MAX_AGE - 1);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$this->onSupportBlockChange();
		if($this->position->getWorld()->getBlock($this->position)->hasSameTypeId($this)){
			WaterloggedHelper::scheduleFlow($this);
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if($this->age < self::MAX_AGE && mt_rand(1, 100) <= self::GROWTH_CHANCE){
			$this->growAbove(null);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			$top = $this;
			$world = $this->position->getWorld();
			while(($above = $world->getBlock($top->position->getSide(Facing::UP)))->hasSameTypeId($this) && $above instanceof Kelp){
				$top = $above;
			}
			if($top->growAbove($player)){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	/**
	 * Grows a new kelp block on top of this one if the space above is a water source.
	 */
	private function growAbove(?Player $player) : bool{
		$world = $this->position->getWorld();
		$abovePos = $this->position->getSide(Facing::UP);
		if(!$world->isInWorld($abovePos->getFloorX(), $abovePos->getFloorY(), $abovePos->getFloorZ())){
			return false;
		}
		$above = $world->getBlock($abovePos);
		if(!WaterloggedHelper::isWaterSource($above) || $above instanceof Waterlogged){
			return false;
		}
		$new = VanillaBlocks::KELP()->setAge($this->age < self::MAX_AGE ? $this->age + 1 : self::MAX_AGE);
		return BlockEventHelper::grow($above, $new, $player);
	}

	public function getCompostabilityChance() : int{
		return 30;
	}
}
