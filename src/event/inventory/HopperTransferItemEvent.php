<?php

declare(strict_types=1);

namespace pocketmine\event\inventory;

use pocketmine\block\Hopper;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;

/**
 * Called before one item is transferred between block inventories by a hopper.
 * The destination slot can be redirected by automation plugins; the engine
 * validates the selected slot and stack capacity again after this event.
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
    ){}

    public function getHopper() : Hopper{ return $this->hopper; }
    public function getSourceInventory() : Inventory{ return $this->sourceInventory; }
    public function getDestinationInventory() : Inventory{ return $this->destinationInventory; }
    public function getSourceSlot() : int{ return $this->sourceSlot; }
    public function getDestinationSlot() : int{ return $this->destinationSlot; }
    public function setDestinationSlot(int $slot) : void{ $this->destinationSlot = $slot; }
    public function getItem() : Item{ return clone $this->item; }
}
