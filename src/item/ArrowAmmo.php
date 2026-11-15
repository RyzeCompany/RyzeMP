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

namespace pocketmine\item;

use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;

/**
 * The arrow stack a bow or crossbow shoots: the off-hand first, then the player's inventory in slot order (hotbar
 * first). Normal and tipped arrows are both ammo.
 * @internal
 */
final class ArrowAmmo{

	private function __construct(
		private Inventory $inventory,
		private int $slot,
		private Arrow $item
	){}

	public static function find(Player $player) : ?self{
		$offHand = $player->getOffHandInventory()->getItem(0);
		if($offHand instanceof Arrow && !$offHand->isNull()){
			return new self($player->getOffHandInventory(), 0, $offHand);
		}
		foreach($player->getInventory()->getContents() as $slot => $item){
			if($item instanceof Arrow){
				return new self($player->getInventory(), $slot, $item);
			}
		}
		return null;
	}

	/**
	 * Returns one arrow of the stack (count 1).
	 */
	public function getItem() : Arrow{
		return (clone $this->item)->setCount(1);
	}

	/**
	 * Removes one arrow from the stack.
	 */
	public function consume() : void{
		$item = $this->inventory->getItem($this->slot);
		if($item->equals($this->item)){
			$item->pop();
			$this->inventory->setItem($this->slot, $item);
		}
	}

	/**
	 * Copies the potion of a tipped arrow (if any) to the arrow entity shot with it.
	 */
	public static function applyTip(Item $arrow, ArrowEntity $entity) : void{
		if($arrow instanceof TippedArrow){
			$entity->setPotionType($arrow->getType());
		}
	}
}
