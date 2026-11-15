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

namespace pocketmine\block\utils;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\player\Player;

/**
 * For {@link Waterlogged} blocks that only exist inside a water source (kelp, seagrass). Classes using this must call
 * {@link WaterloggedHelper::scheduleFlow()} from onNearbyBlockChange() so that the water spreads into empty neighbours.
 */
trait AlwaysWaterloggedTrait{

	public function isWaterlogged() : bool{
		return true;
	}

	public function canBeFlowedInto() : bool{
		return false;
	}

	public function getLightFilter() : int{
		return 2; //same as water
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		return VanillaBlocks::WATER()->onEntityInside($entity);
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		WaterloggedHelper::leaveWater($this);
		return true;
	}

	public function onScheduledUpdate() : void{
		WaterloggedHelper::flow($this);
	}
}
