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

namespace pocketmine\inventory;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/**
 * This class provides a complete implementation of a regular inventory.
 */
class SimpleInventory extends BaseInventory{
	/**
	 * @var \SplFixedArray|(Item|null)[]
	 * @phpstan-var \SplFixedArray<Item|null>
	 */
	protected \SplFixedArray $slots;

	public function __construct(int $size){
		$this->slots = new \SplFixedArray($size);
		parent::__construct();
	}

	/**
	 * Returns the size of the inventory.
	 */
	public function getSize() : int{
		return $this->slots->getSize();
	}

	public function getItem(int $index) : Item{
		return $this->slots[$index] !== null ? clone $this->slots[$index] : VanillaItems::AIR();
	}

	protected function internalSetItem(int $index, Item $item) : void{
		$this->slots[$index] = $item->isNull() ? null : $item;
	}

	/**
	 * @return Item[]
	 * @phpstan-return array<int, Item>
	 */
	public function getContents(bool $includeEmpty = false) : array{
		$contents = [];

		foreach($this->slots as $i => $slot){
			if($slot !== null){
				$contents[$i] = clone $slot;
			}elseif($includeEmpty){
				$contents[$i] = VanillaItems::AIR();
			}
		}

		return $contents;
	}

	protected function internalSetContents(array $items) : void{
		for($i = 0, $size = $this->getSize(); $i < $size; ++$i){
			if(!isset($items[$i]) || $items[$i]->isNull()){
				$this->slots[$i] = null;
			}else{
				$this->slots[$i] = clone $items[$i];
			}
		}
	}

	protected function getMatchingItemCount(int $slot, Item $test, bool $checkTags) : int{
		$slotItem = $this->slots[$slot];
		return $slotItem !== null && $slotItem->equals($test, true, $checkTags) ? $slotItem->getCount() : 0;
	}

	public function isSlotEmpty(int $index) : bool{
		return $this->slots[$index] === null || $this->slots[$index]->isNull();
	}

	/**
	 * Returns whether the slot holds an item which is an instance of the given class, without cloning it: the same as
	 * getItem($index) instanceof $class for any class air items are not instances of. Only for this class' own
	 * getItem(): callers must check that the inventory's class does not override it.
	 *
	 * @internal
	 * @phpstan-param class-string<Item> $class
	 */
	public function isSlotInstanceOf(int $index, string $class) : bool{
		return $this->slots[$index] instanceof $class;
	}
}
