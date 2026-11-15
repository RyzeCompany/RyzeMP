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

namespace pocketmine\block\tile;

use pocketmine\block\utils\ItemTransfer;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\world\Position;
use function floor;
use function min;

/**
 * Implements {@link ComparatorReadableContainer} for container tiles: the output follows the fullness of the
 * inventory, and comparators and nearby hoppers are told when the contents change.
 */
trait ComparatorReadableContainerTrait{

	abstract public function getRealInventory() : Inventory;

	abstract protected function getPosition() : Position;

	public function getComparatorOutput() : int{
		return self::calculateComparatorOutput($this->getInventory());
	}

	/**
	 * Vanilla formula: 0 when empty, otherwise 1 + floor(14 * average slot fullness).
	 */
	public static function calculateComparatorOutput(Inventory $inventory) : int{
		$size = $inventory->getSize();
		if($size === 0){
			return 0;
		}
		$fullness = 0.0;
		$maxStackSize = $inventory->getMaxStackSize();
		foreach($inventory->getContents() as $item){
			$fullness += $item->getCount() / min($maxStackSize, $item->getMaxStackSize());
		}
		if($fullness <= 0){
			return 0;
		}
		return (int) floor(($fullness / $size) * 14) + 1;
	}

	/**
	 * Call this from the tile constructor, after the inventory is created.
	 */
	protected function addComparatorInventoryListener() : void{
		$position = $this->getPosition();
		$world = $position->getWorld();
		$x = $position->getFloorX();
		$y = $position->getFloorY();
		$z = $position->getFloorZ();
		$this->getRealInventory()->getListeners()->add(CallbackInventoryListener::onAnyChange(
			static function(Inventory $unused) use ($world, $x, $y, $z) : void{
				if($world->isLoaded()){
					$world->getRedstone()?->updateComparatorsReading($x, $y, $z);
					ItemTransfer::notifyContainerChanged($world, $x, $y, $z);
				}
			}
		));
	}
}
