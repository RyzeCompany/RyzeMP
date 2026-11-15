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

use pocketmine\block\utils\LeavesType;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\VanillaTreeGrower;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function mt_rand;

/**
 * Mangrove propagule. Planted propagules stand on dirt, mud, clay or farmland and are always fully grown (stage 4),
 * like vanilla; they grow into mangrove trees like saplings (random ticks or bone meal, also under water). Hanging
 * propagules hang from mangrove leaves (bone meal on the leaves makes one) and grow from stage 0 to 4 on random ticks
 * or with bone meal; they only drop themselves once fully grown.
 */
class MangrovePropagule extends Flowable{
	use StaticSupportTrait;

	public const MAX_STAGE = 4;
	/** Percent chance for bone meal to grow a planted propagule (MangrovePropaguleBlock.isBonemealSuccess()) */
	private const BONE_MEAL_CHANCE = 45;

	private bool $hanging = false;
	private int $stage = self::MAX_STAGE;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->hanging);
		$w->boundedIntAuto(0, self::MAX_STAGE, $this->stage);
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	public function getStage() : int{ return $this->stage; }

	/** @return $this */
	public function setStage(int $stage) : self{
		if($stage < 0 || $stage > self::MAX_STAGE){
			throw new \InvalidArgumentException("Stage must be in range 0-" . self::MAX_STAGE);
		}
		$this->stage = $stage;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		if($this->hanging){
			$above = $block->getSide(Facing::UP);
			return $above instanceof Leaves && $above->getLeavesType() === LeavesType::MANGROVE;
		}
		$below = $block->getSide(Facing::DOWN);
		return $below->hasTypeTag(BlockTypeTags::DIRT) ||
			$below->hasTypeTag(BlockTypeTags::MUD) ||
			$below->getTypeId() === BlockTypeIds::CLAY ||
			$below->getTypeId() === BlockTypeIds::FARMLAND;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			if($this->hanging){
				if($this->stage >= self::MAX_STAGE){
					return false;
				}
				$this->stage++;
				$this->position->getWorld()->setBlock($this->position, $this);
			}else{
				//a planted propagule grows like a sapling (MangrovePropaguleBlock: 45%, bone meal used either way)
				if(!VanillaTreeGrower::isAvailable($this)){
					return false;
				}
				if(($player !== null && !$player->hasFiniteResources()) || mt_rand(0, 99) < self::BONE_MEAL_CHANCE){
					$this->growTree($player);
				}
			}
			$item->pop();
			return true;
		}
		return false;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		if($this->hanging){
			if($this->stage < self::MAX_STAGE){
				$this->stage++;
				$this->position->getWorld()->setBlock($this->position, $this);
			}
		}elseif(mt_rand(0, 6) === 0){
			//vanilla (MangrovePropaguleBlock.randomTick): 1 in 7, without the sapling's light check, so propagules grow
			//in the dark and under deep water too; Bedrock propagules have no sapling stage, so the tree grows at once
			$this->growTree(null);
		}
	}

	/**
	 * Grows a mangrove tree (TreeGrower.MANGROVE: mangrove 15, tall_mangrove 85), in water too.
	 */
	private function growTree(?Player $player) : void{
		$pos = $this->position;
		VanillaTreeGrower::growTree(
			$this,
			$player,
			VanillaTreeGrower::MANGROVE,
			fn(Block $block) : bool => false,
			LiquidLayer::hasLayerWaterAt($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())
		);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->stage === self::MAX_STAGE ? parent::getDropsForCompatibleTool($item) : [];
	}

	public function getCompostabilityChance() : int{
		return 30;
	}
}
