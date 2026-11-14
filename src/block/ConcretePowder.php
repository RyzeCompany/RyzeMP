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
use pocketmine\block\utils\Colored;
use pocketmine\block\utils\ColoredTrait;
use pocketmine\block\utils\Fallable;
use pocketmine\block\utils\FallableTrait;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SupportType;
use pocketmine\math\Facing;

class ConcretePowder extends Opaque implements Fallable, Colored{
	use ColoredTrait;
	use FallableTrait {
		onNearbyBlockChange as protected startFalling;
	}

	public function onNearbyBlockChange() : void{
		if(($water = $this->getAdjacentWater()) !== null){
			BlockEventHelper::form($this, VanillaBlocks::CONCRETE()->setColor($this->color), $water);
		}else{
			$this->startFalling();
		}
	}

	public function tickFalling() : ?Block{
		if($this->getAdjacentWater() === null){
			return null;
		}
		return VanillaBlocks::CONCRETE()->setColor($this->color);
	}

	/**
	 * Returns the water (or waterlogged block holding water) touching the powder from above or a side, if any.
	 */
	private function getAdjacentWater() : ?Block{
		foreach(Facing::ALL as $i){
			if($i === Facing::DOWN){
				continue;
			}
			$block = $this->getSide($i);
			//like Java, water behind a full face of a waterlogged block (stairs, slabs...) does not touch the powder
			if(LiquidLayer::isWater($block) && $block->getSupportType(Facing::opposite($i)) !== SupportType::FULL){
				return $block;
			}
		}

		return null;
	}
}
