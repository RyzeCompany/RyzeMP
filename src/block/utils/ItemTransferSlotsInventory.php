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

use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;

/**
 * Short-lived inventory view of a block that keeps its items outside of an inventory (decorated pot, jukebox) or that
 * has side effects when its items change (chiseled bookshelf). It starts with a copy of the items of the block, and
 * every change is written back to the block through the callback.
 *
 * @internal Used by {@link ItemTransferContainer} only.
 */
final class ItemTransferSlotsInventory extends SimpleInventory{

	/**
	 * @param Item[] $contents
	 * @phpstan-param array<int, Item> $contents
	 * @phpstan-param \Closure(int $slot, Item $item) : void $onSet
	 */
	public function __construct(int $size, int $maxStackSize, array $contents, private \Closure $onSet){
		parent::__construct($size);
		$this->setMaxStackSize($maxStackSize);
		foreach($contents as $slot => $item){
			if(!$item->isNull()){
				$this->slots[$slot] = clone $item;
			}
		}
	}

	protected function internalSetItem(int $index, Item $item) : void{
		parent::internalSetItem($index, $item);
		($this->onSet)($index, $item->isNull() ? $item : clone $item);
	}

	protected function internalSetContents(array $items) : void{
		parent::internalSetContents($items);
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			($this->onSet)($i, $this->getItem($i));
		}
	}
}
