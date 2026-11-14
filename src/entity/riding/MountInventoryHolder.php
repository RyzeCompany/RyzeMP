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

namespace pocketmine\entity\riding;

use pocketmine\inventory\HorseInventory;
use pocketmine\item\Item;
use pocketmine\player\Player;

/**
 * A mount with a vanilla "horse" container (minecraft:inventory container_type horse): horses, donkeys, llamas, nautilus,
 * zombie nautilus, camels and camel husks. Its {@link HorseInventory} is opened with the horse screen (sneak-interacting,
 * or the inventory button of its rider): slot 0 is the saddle (or a llama's carpet), slot 1 the body armor.
 */
interface MountInventoryHolder{

	public function getId() : int;

	public function getName() : string;

	public function getInventory() : HorseInventory;

	/**
	 * Returns whether the item may be put in the given slot of the inventory.
	 */
	public function canHoldInSlot(int $slot, Item $item) : bool;

	/**
	 * Whether slot 0 is a saddle slot.
	 */
	public function canWearSaddle() : bool;

	/**
	 * Whether slot 1 is a body armor slot.
	 */
	public function hasArmorSlot() : bool;

	/**
	 * Returns the network name of the item shown in the empty armor slot of the horse screen.
	 */
	public function getArmorSlotIcon() : string;

	/**
	 * Returns the network names of the items accepted in the armor slot, for the horse screen.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getArmorSlotItemNames() : array;

	/**
	 * Whether the player may open the inventory now.
	 */
	public function canOpenInventory(Player $player) : bool;

	/**
	 * Opens the inventory to the player. Returns whether it opened.
	 */
	public function openInventory(Player $player) : bool;
}
