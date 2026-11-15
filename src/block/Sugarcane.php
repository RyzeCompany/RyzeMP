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
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\Position;

class Sugarcane extends Flowable implements Ageable{
	use AgeableTrait;
	use StaticSupportTrait {
		onNearbyBlockChange as onSupportBlockChange;
	}

	public const MAX_AGE = 15;

	/** Natural growth stops once the column is this tall */
	private const MAX_GROWTH_HEIGHT = 3;

	private function seekToBottom() : Position{
		$world = $this->position->getWorld();
		$bottom = $this->position;
		while(($next = $world->getBlock($bottom->down()))->hasSameTypeId($this)){
			$bottom = $next->position;
		}
		return $bottom;
	}

	private function grow(Position $pos, ?Player $player = null) : bool{
		$grew = false;
		$world = $pos->getWorld();
		for($y = 1; $y < 3; ++$y){
			if(!$world->isInWorld($pos->x, $pos->y + $y, $pos->z)){
				break;
			}
			$b = $world->getBlockAt($pos->x, $pos->y + $y, $pos->z);
			if($b->getTypeId() === BlockTypeIds::AIR){
				if(BlockEventHelper::grow($b, VanillaBlocks::SUGARCANE(), $player)){
					$grew = true;
				}else{
					break;
				}
			}elseif(!$b->hasSameTypeId($this)){
				break;
			}
		}
		$this->age = 0;
		$world->setBlock($pos, $this);
		return $grew;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			if($this->grow($this->seekToBottom(), $player)){
				$item->pop();
			}

			return true;
		}

		return false;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $supportBlock->hasSameTypeId($this) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
			$supportBlock->hasTypeTag(BlockTypeTags::SAND);
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		$down = $this->getSide(Facing::DOWN);
		if(!$down->hasSameTypeId($this) && !$this->hasNearbyWater($down)){
			$world->useBreakOn($this->position, createParticles: true);
			return;
		}

		//only the top cane of a column shorter than 3 grows: it ages 0-15 and adds ONE cane above at 15 (vanilla)
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(!$world->isInWorld($x, $y + 1, $z) || $world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		$height = 1;
		while($height < self::MAX_GROWTH_HEIGHT && $world->getBlockAt($x, $y - $height, $z)->hasSameTypeId($this)){
			++$height;
		}
		if($height >= self::MAX_GROWTH_HEIGHT){
			return;
		}

		if($this->age === self::MAX_AGE){
			BlockEventHelper::grow($world->getBlockAt($x, $y + 1, $z), VanillaBlocks::SUGARCANE(), null);
			$this->age = 0;
		}else{
			++$this->age;
		}
		$world->setBlock($this->position, $this, update: false);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$down = $blockReplace->getSide(Facing::DOWN);
		if($down->hasSameTypeId($this)){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		//support criteria are checked by FixedSupportTrait, but this part applies to placement only
		if($this->hasNearbyWater($down)){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		return false;
	}

	/**
	 * Water (including waterlogged blocks) or frosted ice next to the block the sugar cane stands on.
	 */
	private function hasNearbyWater(Block $down) : bool{
		foreach($down->getHorizontalSides() as $sideBlock){
			if($sideBlock->getTypeId() === BlockTypeIds::FROSTED_ICE || LiquidLayer::isWater($sideBlock)){
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$down = $this->getSide(Facing::DOWN);
		if(!$down->hasSameTypeId($this) && !$this->hasNearbyWater($down)){
			$this->position->getWorld()->useBreakOn($this->position, createParticles: true);
		}else{
			$this->onSupportBlockChange();
		}
	}

	public function getCompostabilityChance() : int{
		return 50;
	}
}
