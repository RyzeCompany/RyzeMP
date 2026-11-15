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

namespace pocketmine\entity\vehicle;

use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * The kinds of minecarts in Bedrock (there is no furnace minecart).
 */
enum MinecartType{
	case NORMAL;
	case CHEST;
	case HOPPER;
	case TNT;
	case COMMAND_BLOCK;

	/**
	 * Bedrock entity identifier of the cart.
	 */
	public function getEntityId() : string{
		return match($this){
			self::NORMAL => EntityIds::MINECART,
			self::CHEST => EntityIds::CHEST_MINECART,
			self::HOPPER => EntityIds::HOPPER_MINECART,
			self::TNT => EntityIds::TNT_MINECART,
			self::COMMAND_BLOCK => EntityIds::COMMAND_BLOCK_MINECART,
		};
	}

	/**
	 * The item that places this cart.
	 */
	public function getItem() : Item{
		return match($this){
			self::NORMAL => VanillaItems::MINECART(),
			self::CHEST => VanillaItems::CHEST_MINECART(),
			self::HOPPER => VanillaItems::HOPPER_MINECART(),
			self::TNT => VanillaItems::TNT_MINECART(),
			self::COMMAND_BLOCK => VanillaItems::COMMAND_BLOCK_MINECART(),
		};
	}

	/**
	 * Creates a new cart of this type (not spawned).
	 */
	public function createEntity(Location $location) : AbstractMinecart{
		return match($this){
			self::NORMAL => new Minecart($location),
			self::CHEST => new MinecartChest($location),
			self::HOPPER => new MinecartHopper($location),
			self::TNT => new MinecartTNT($location),
			self::COMMAND_BLOCK => new MinecartCommandBlock($location),
		};
	}
}
