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

namespace pocketmine\block\inventory;

use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\transaction\action\validator\CallbackSlotValidator;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Item;
use pocketmine\world\Position;

/**
 * The 3x3 crafting grid of a crafter. Slot 0 is the top left corner, slots go row by row.
 *
 * Disabled slots never hold items. They are stored as a bit mask, bit N for slot N (the Bedrock "disabled_slots"
 * block entity tag; same layout as GeyserMC CrafterContainer.disabledSlotsMask).
 */
class CrafterInventory extends SimpleInventory implements BlockInventory{
	use BlockInventoryTrait;

	public const SIZE = 9;
	public const ALL_SLOTS_MASK = (1 << self::SIZE) - 1;

	private int $disabledSlots = 0;

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(self::SIZE);
		$this->validators->add(new CallbackSlotValidator(function(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{
			if(!$item->isNull() && $this->isSlotDisabled($slot)){
				return new TransactionValidationException("Crafter slot $slot is disabled");
			}
			return null;
		}));
	}

	public function isSlotDisabled(int $slot) : bool{
		return ($this->disabledSlots & (1 << $slot)) !== 0;
	}

	public function getDisabledSlotsMask() : int{
		return $this->disabledSlots;
	}

	public function setDisabledSlotsMask(int $mask) : void{
		$this->disabledSlots = $mask & self::ALL_SLOTS_MASK;
	}

	/**
	 * Disables or enables a slot. Only empty slots can be disabled; returns false if the slot could not be changed.
	 */
	public function setSlotDisabled(int $slot, bool $disabled) : bool{
		if($slot < 0 || $slot >= self::SIZE || ($disabled && !$this->isSlotEmpty($slot))){
			return false;
		}
		if($disabled){
			$this->disabledSlots |= 1 << $slot;
		}else{
			$this->disabledSlots &= ~(1 << $slot);
		}
		return true;
	}
}
