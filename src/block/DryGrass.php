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
use pocketmine\block\utils\BoneMealSpread;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Short and tall dry grass (Bedrock 1.21.80): replaceable desert plants that only drop with shears.
 * Bone meal turns short dry grass into tall dry grass, and makes tall dry grass place short dry grass next to it.
 */
class DryGrass extends Flowable{
	use StaticSupportTrait;

	/** @phpstan-var (\Closure() : Block)|null */
	private ?\Closure $grownVariant;

	/**
	 * @phpstan-param (\Closure() : Block)|null $grownVariant block that bone meal turns this one into
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, ?\Closure $grownVariant = null){
		$this->grownVariant = $grownVariant;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function canBeReplaced() : bool{
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		//same blocks as the dead bush
		$supportBlock = $block->getSide(Facing::DOWN);
		return
			$supportBlock->hasTypeTag(BlockTypeTags::SAND) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			match($supportBlock->getTypeId()){
				BlockTypeIds::PODZOL,
				BlockTypeIds::MYCELIUM,
				BlockTypeIds::DIRT,
				BlockTypeIds::GRASS,
				BlockTypeIds::HARDENED_CLAY,
				BlockTypeIds::STAINED_CLAY,
				BlockTypeIds::MOSS_BLOCK,
				BlockTypeIds::PALE_MOSS_BLOCK => true,
				default => false,
			};
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Fertilizer){
			return false;
		}
		if($this->grownVariant !== null){
			if(BlockEventHelper::grow($this, ($this->grownVariant)(), $player)){
				$item->pop();
			}
			return true;
		}
		//tall dry grass places short dry grass on a free neighbour (vanilla TallDryGrassBlock.performBonemeal())
		return BoneMealSpread::placeOnNeighbour($this, VanillaBlocks::SHORT_DRY_GRASS(), $item, $player);
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 100;
	}

	public function getCompostabilityChance() : int{
		return 30;
	}
}
