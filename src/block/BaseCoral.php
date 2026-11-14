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
use pocketmine\block\utils\SupportType;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use function mt_rand;

abstract class BaseCoral extends Transparent implements CoralMaterial{
	use CoralTypeTrait;

	public function onNearbyBlockChange() : void{
		if(!$this->dead){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, mt_rand(40, 200));
		}
	}

	public function onScheduledUpdate() : void{
		if(!$this->dead && !$this->isCoveredWithWater()){
			BlockEventHelper::die($this, (clone $this)->setDead(true));
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function isSolid() : bool{ return false; }

	/**
	 * Returns whether the coral has water around it: its own water (the liquid layer, or the water it is being placed
	 * into) or water in any neighbouring block, waterlogged blocks included.
	 */
	protected function isCoveredWithWater() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();

		if(LiquidLayer::hasWaterAt($world, $x, $y, $z)){
			return true;
		}
		foreach(Facing::OFFSET as [$dx, $dy, $dz]){
			if(LiquidLayer::hasWaterAt($world, $x + $dx, $y + $dy, $z + $dz)){
				return true;
			}
		}
		return false;
	}

	protected function recalculateCollisionBoxes() : array{ return []; }

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
