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

use pocketmine\block\Beacon;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\TemporaryInventory;
use pocketmine\inventory\transaction\action\validator\CallbackSlotValidator;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Item;
use pocketmine\world\Position;

/**
 * The beacon window: a single payment slot (UI slot 27, container BEACON_PAYMENT) that only accepts one beacon
 * payment item. Anything left in it is given back when the window is closed.
 */
final class BeaconInventory extends SimpleInventory implements BlockInventory, TemporaryInventory{
	use BlockInventoryTrait;

	public const SLOT_PAYMENT = 0;

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(1);
		$this->setMaxStackSize(1);
		$this->validators->add(new CallbackSlotValidator(function(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{
			return Beacon::isPaymentItem($item) ? null : new TransactionValidationException("Only iron ingots, gold ingots, emeralds, diamonds and netherite ingots can pay a beacon");
		}));
	}

	public function getPayment() : Item{
		return $this->getItem(self::SLOT_PAYMENT);
	}
}
