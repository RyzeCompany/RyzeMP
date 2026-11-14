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

use pocketmine\item\BoatType;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/**
 * The wood of a boat, sent to clients as the VARIANT of minecraft:boat and minecraft:chest_boat and saved as "Variant"
 * (Bedrock numbering: the bamboo raft is variant 7).
 */
enum BoatVariant : int{
	case OAK = 0;
	case SPRUCE = 1;
	case BIRCH = 2;
	case JUNGLE = 3;
	case ACACIA = 4;
	case DARK_OAK = 5;
	case MANGROVE = 6;
	case BAMBOO = 7;
	case CHERRY = 8;
	case PALE_OAK = 9;
	case POPLAR = 10;

	public static function fromBoatType(BoatType $type) : self{
		return match($type){
			BoatType::OAK => self::OAK,
			BoatType::SPRUCE => self::SPRUCE,
			BoatType::BIRCH => self::BIRCH,
			BoatType::JUNGLE => self::JUNGLE,
			BoatType::ACACIA => self::ACACIA,
			BoatType::DARK_OAK => self::DARK_OAK,
			BoatType::MANGROVE => self::MANGROVE,
			BoatType::POPLAR => self::POPLAR,
			BoatType::CHERRY => self::CHERRY,
			BoatType::PALE_OAK => self::PALE_OAK,
		};
	}

	/**
	 * Whether this is the bamboo raft (flat, its seats are higher).
	 */
	public function isRaft() : bool{
		return $this === self::BAMBOO;
	}

	/**
	 * The item of the boat of this wood (the bamboo raft for bamboo).
	 */
	public function getBoatItem() : Item{
		return match($this){
			self::OAK => VanillaItems::OAK_BOAT(),
			self::SPRUCE => VanillaItems::SPRUCE_BOAT(),
			self::BIRCH => VanillaItems::BIRCH_BOAT(),
			self::JUNGLE => VanillaItems::JUNGLE_BOAT(),
			self::ACACIA => VanillaItems::ACACIA_BOAT(),
			self::DARK_OAK => VanillaItems::DARK_OAK_BOAT(),
			self::MANGROVE => VanillaItems::MANGROVE_BOAT(),
			self::BAMBOO => VanillaItems::BAMBOO_RAFT(),
			self::CHERRY => VanillaItems::CHERRY_BOAT(),
			self::PALE_OAK => VanillaItems::PALE_OAK_BOAT(),
			self::POPLAR => VanillaItems::POPLAR_BOAT(),
		};
	}

	/**
	 * The item of the boat with chest of this wood (the bamboo chest raft for bamboo).
	 */
	public function getChestBoatItem() : Item{
		return match($this){
			self::OAK => VanillaItems::OAK_CHEST_BOAT(),
			self::SPRUCE => VanillaItems::SPRUCE_CHEST_BOAT(),
			self::BIRCH => VanillaItems::BIRCH_CHEST_BOAT(),
			self::JUNGLE => VanillaItems::JUNGLE_CHEST_BOAT(),
			self::ACACIA => VanillaItems::ACACIA_CHEST_BOAT(),
			self::DARK_OAK => VanillaItems::DARK_OAK_CHEST_BOAT(),
			self::MANGROVE => VanillaItems::MANGROVE_CHEST_BOAT(),
			self::BAMBOO => VanillaItems::BAMBOO_CHEST_RAFT(),
			self::CHERRY => VanillaItems::CHERRY_CHEST_BOAT(),
			self::PALE_OAK => VanillaItems::PALE_OAK_CHEST_BOAT(),
			self::POPLAR => VanillaItems::POPLAR_CHEST_BOAT(),
		};
	}
}
