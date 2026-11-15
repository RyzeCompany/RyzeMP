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

use pocketmine\entity\animal\AbstractHorse;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\inventory\transaction\action\validator\CallbackSlotValidator;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Item;

/**
 * The inventory of a horse, donkey, mule, llama, nautilus... (Bedrock "horse" container, window type HORSE). Its slots
 * are the slots of the client's horse screen, the same way BDS and GeyserMC lay them out:
 *  - slot 0: the saddle, or the carpet of a llama ("horse_equip_items" index 0, HORSE_EQUIP);
 *  - slot 1: the horse armor of a horse, the nautilus armor of a nautilus (HORSE_EQUIP index 1);
 *  - slots 1 and up: the chest of a donkey, mule or llama (LEVEL_ENTITY, "container_items").
 * The size follows the animal (see {@link AbstractHorse::getInventorySize()}); what each slot accepts is checked by
 * {@link MountInventoryHolder::canHoldInSlot()}.
 */
final class HorseInventory extends SimpleInventory{
	/** Saddle slot, or the carpet slot of a llama. */
	public const SLOT_EQUIP = 0;
	/** Body armor slot of the mounts that wear armor (they never have a chest). */
	public const SLOT_ARMOR = 1;
	/** First slot of the chest of a chested animal. */
	public const FIRST_CHEST_SLOT = 1;

	public function __construct(
		private MountInventoryHolder $horse,
		int $size
	){
		parent::__construct($size);
		$this->validators->add(new CallbackSlotValidator(function(Inventory $inventory, Item $item, int $slot) : ?TransactionValidationException{
			if(!$this->horse->canHoldInSlot($slot, $item)){
				return new TransactionValidationException("Item is not accepted in this slot of the " . $this->horse->getName());
			}
			return null;
		}));
	}

	/**
	 * Returns the mount this inventory belongs to (an {@link AbstractHorse}, a nautilus...).
	 */
	public function getHorse() : MountInventoryHolder{
		return $this->horse;
	}
}
