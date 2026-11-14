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

namespace pocketmine\entity\monster;

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Entity;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\Banner;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use function max;
use function min;
use function mt_rand;

/**
 * Equipment slots of mobs (the four armor slots of {@link ArmorInventory} and both hands, see {@link HeldItemsTrait})
 * and helpers shared by spawn equipment and equipment drops.
 */
final class MobEquipment{
	public const SLOT_HEAD = ArmorInventory::SLOT_HEAD;
	public const SLOT_CHEST = ArmorInventory::SLOT_CHEST;
	public const SLOT_LEGS = ArmorInventory::SLOT_LEGS;
	public const SLOT_FEET = ArmorInventory::SLOT_FEET;
	public const SLOT_MAINHAND = 4;
	public const SLOT_OFFHAND = 5;

	/** Bit mask of the slots always dropped on death (PocketMine). */
	public const TAG_GUARANTEED_DROPS = "GuaranteedDrops"; //TAG_Byte
	/** Bit mask of the slots never dropped on death (PocketMine; vanilla drop chance 0). */
	public const TAG_NO_DROPS = "NoDropSlots"; //TAG_Byte
	/** Whether the mob picks up items (Java CanPickUpLoot; rolled at spawn for behavior.pickup_items pickup_based_on_chance). */
	public const TAG_CAN_PICK_UP_LOOT = "CanPickUpLoot"; //TAG_Byte

	/**
	 * Vanilla drop chance of equipment a mob spawned with, when killed by a player (Java 8.5%, +1% per Looting level),
	 * in 1000ths.
	 */
	public const DEFAULT_DROP_CHANCE = 85;
	public const LOOTING_DROP_CHANCE = 10;

	private function __construct(){
		//NOOP
	}

	/**
	 * Whether the saved data is the one of a freshly created mob (no saved hands nor armor), which should roll its
	 * spawn equipment. Mobs saved before they had equipment keep what they had.
	 */
	public static function isFreshSpawn(?CompoundTag $nbt) : bool{
		return $nbt === null || ($nbt->getTag("Mainhand") === null && $nbt->getTag("Armor") === null);
	}

	/**
	 * Returns the slot an item goes to when equipped: armor in its armor slot, heads and carved pumpkins on the head,
	 * banners on the chest (the ominous banner of raid captains), nautilus shells, shields and totems in the off hand,
	 * anything else in the main hand.
	 */
	public static function getSlotFor(Item $item) : int{
		if($item instanceof Armor){
			return $item->getArmorSlot();
		}
		if($item instanceof Banner || $item->getTypeId() === ItemTypeIds::OMINOUS_BANNER){
			return self::SLOT_CHEST;
		}
		//block items have the block's type ID: no copy of the block (getBlock())
		$blockTypeId = ItemTypeIds::toBlockTypeId($item->getTypeId());
		if($blockTypeId === BlockTypeIds::CARVED_PUMPKIN || $blockTypeId === BlockTypeIds::MOB_HEAD){
			return self::SLOT_HEAD;
		}
		return match($item->getTypeId()){
			ItemTypeIds::NAUTILUS_SHELL, ItemTypeIds::SHIELD, ItemTypeIds::TOTEM => self::SLOT_OFFHAND,
			default => self::SLOT_MAINHAND
		};
	}

	/**
	 * Returns the saved data to create the mob $from turns into with (husk -> zombie, piglin -> zombified piglin): like
	 * vanilla conversions (Java ConversionParams) the new mob keeps which equipment slots always or never drop and
	 * whether it picks up items, and it doesn't roll spawn equipment (an empty main hand: the caller gives it the
	 * equipment of $from).
	 *
	 * @internal
	 */
	public static function getConversionData(Entity $from) : CompoundTag{
		$saved = $from->saveNBT();
		$nbt = CompoundTag::create()->setTag("Mainhand", new ListTag([], NBT::TAG_Compound));
		foreach([self::TAG_GUARANTEED_DROPS, self::TAG_NO_DROPS, self::TAG_CAN_PICK_UP_LOOT] as $name){
			$tag = $saved->getTag($name);
			if($tag !== null){
				$nbt->setTag($name, $tag);
			}
		}
		return $nbt;
	}

	/**
	 * Damages a dropped spawned equipment item like vanilla (Java Mob.dropCustomDeathLoot): it keeps a random,
	 * usually small, part of its durability.
	 */
	public static function randomizeDropDamage(Item $item) : Item{
		if($item instanceof Durable && !$item->isUnbreakable()){
			$max = $item->getMaxDurability();
			$left = mt_rand(0, mt_rand(0, max($max - 3, 1) - 1));
			$item->setDamage(max(0, min($max - 1, $max - $left)));
		}
		return $item;
	}
}
