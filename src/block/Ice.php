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
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;

class Ice extends Transparent{

	public function getLightFilter() : int{
		return 2;
	}

	public function getFrictionFactor() : float{
		return 0.98;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		//Java IceBlock.playerDestroy(): ice broken without silk touch leaves water, unless it evaporates (ultra-warm
		//dimension) or nothing below would hold the water (a block which isn't solid nor a liquid)
		if(($player === null || $player->isSurvival()) && !$item->hasEnchantment(VanillaEnchantments::SILK_TOUCH()) && !UltraWarmHelper::isUltraWarm($this->position)){
			$below = $this->getSide(Facing::DOWN);
			if($below->isSolid() || $below instanceof Liquid){
				$this->position->getWorld()->setBlock($this->position, VanillaBlocks::WATER());
				return true;
			}
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getHighestAdjacentBlockLight($this->position->x, $this->position->y, $this->position->z) >= 12){
			BlockEventHelper::melt($this, UltraWarmHelper::getMeltedIceState($this));
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
