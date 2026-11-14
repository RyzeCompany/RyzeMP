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
use pocketmine\block\utils\CoralMaterial;
use pocketmine\block\utils\CoralTypeTrait;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use function mt_rand;

final class CoralBlock extends Opaque implements CoralMaterial{
	use CoralTypeTrait;

	public function onNearbyBlockChange() : void{
		if(!$this->dead){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, mt_rand(40, 200));
		}
	}

	public function onScheduledUpdate() : void{
		if(!$this->dead){
			$world = $this->position->getWorld();
			$x = $this->position->getFloorX();
			$y = $this->position->getFloorY();
			$z = $this->position->getFloorZ();

			//waterlogged neighbours count as water
			$hasWater = false;
			foreach(Facing::OFFSET as [$dx, $dy, $dz]){
				if(LiquidLayer::hasWaterAt($world, $x + $dx, $y + $dy, $z + $dz)){
					$hasWater = true;
					break;
				}
			}
			if(!$hasWater){
				BlockEventHelper::die($this, (clone $this)->setDead(true));
			}
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [(clone $this)->setDead(true)->asItem()];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
