<?php

/*
 * This file is part of RyzeMP.
 *
 * RyzeMP is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License, version 3
 * or (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\event\inventory;

use pocketmine\block\Hopper;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;

/**
 * Called immediately before a hopper moves one item between two inventories.
 *
 * This includes pushes into a container or a container minecart, and pulls from a
 * container or a container minecart. Cancelling the event leaves both inventories
 * unchanged by the attempted transfer.
 *
 * Item entities are handled by BlockItemPickupEvent instead. Composters do not
 * expose inventories, so composting operations do not trigger this event.
 *
 * The item is a snapshot of the ONE item about to be moved, not the full stack.
 */
final class HopperTransferItemEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Hopper $hopper,
		private Inventory $sourceInventory,
		private int $sourceSlot,
		private Inventory $destinationInventory,
		private int $destinationSlot,
		private Item $item
	){
		$this->item = clone $item;
	}

	public function getHopper() : Hopper{
		return $this->hopper;
	}

	public function getSourceInventory() : Inventory{
		return $this->sourceInventory;
	}

	public function getSourceSlot() : int{
		return $this->sourceSlot;
	}

	public function getDestinationInventory() : Inventory{
		return $this->destinationInventory;
	}

	public function getDestinationSlot() : int{
		return $this->destinationSlot;
	}

	public function getItem() : Item{
		return clone $this->item;
	}
}
