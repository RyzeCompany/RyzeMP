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
use pocketmine\block\MobHead;
use pocketmine\block\utils\MobHeadType;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;

/**
 * What a mob picks up and which equipment it prefers: Bedrock behavior.pickup_items (can_pickup_any_item,
 * excluded_items, pickup_based_on_chance, max_dist) with the minecraft:shareables item priorities used by
 * behavior.equip_item (a lower priority is better; a mob swaps its equipment for an item with a lower priority in the
 * same slot, or for the same item enchanted).
 *
 * Data of the 1.26.50 behavior pack (zombie, husk, zombie_villager_v2, drowned, zombie_pigman, skeleton, stray, bogged,
 * parched, wither_skeleton, piglin).
 *
 * @internal
 */
final class MobShareables{
	/** Java Zombie/AbstractSkeleton.finalizeSpawn: canPickUpLoot = random < 0.55 x regional difficulty special multiplier */
	public const PICKUP_CHANCE = 0.55;
	/** behavior.pickup_items max_dist of the hostile mobs */
	public const PICKUP_DISTANCE = 3.0;

	/** Shareables key of the mob heads (block items share one item type ID): this minus the head type. */
	private const SKULL_KEY = -100_000_000;

	/** behavior.pickup_items excluded_items: glow ink sacs */
	private const EXCLUDE_GLOW_INK_SAC = 1;
	/** behavior.pickup_items excluded_items: spears */
	private const EXCLUDE_SPEARS = 2;
	/** minecraft:equip_item excluded_items banner:15: the ominous banner (only raiders take it, see {@link Illager}) */
	private const EXCLUDE_OMINOUS_BANNER = 4;

	private const SWORDS = [
		ItemTypeIds::NETHERITE_SWORD, ItemTypeIds::DIAMOND_SWORD, ItemTypeIds::IRON_SWORD, ItemTypeIds::GOLDEN_SWORD,
		ItemTypeIds::COPPER_SWORD, ItemTypeIds::STONE_SWORD, ItemTypeIds::WOODEN_SWORD
	];
	private const SPEARS = [
		ItemTypeIds::NETHERITE_SPEAR, ItemTypeIds::DIAMOND_SPEAR, ItemTypeIds::IRON_SPEAR, ItemTypeIds::GOLDEN_SPEAR,
		ItemTypeIds::COPPER_SPEAR, ItemTypeIds::STONE_SPEAR, ItemTypeIds::WOODEN_SPEAR
	];
	/** Armor by slot, best first: netherite, diamond, iron, chainmail, golden, copper, leather. */
	private const ARMOR = [
		[ItemTypeIds::NETHERITE_HELMET, ItemTypeIds::DIAMOND_HELMET, ItemTypeIds::IRON_HELMET, ItemTypeIds::CHAINMAIL_HELMET, ItemTypeIds::GOLDEN_HELMET, ItemTypeIds::COPPER_HELMET, ItemTypeIds::LEATHER_CAP],
		[ItemTypeIds::NETHERITE_CHESTPLATE, ItemTypeIds::DIAMOND_CHESTPLATE, ItemTypeIds::IRON_CHESTPLATE, ItemTypeIds::CHAINMAIL_CHESTPLATE, ItemTypeIds::GOLDEN_CHESTPLATE, ItemTypeIds::COPPER_CHESTPLATE, ItemTypeIds::LEATHER_TUNIC],
		[ItemTypeIds::NETHERITE_LEGGINGS, ItemTypeIds::DIAMOND_LEGGINGS, ItemTypeIds::IRON_LEGGINGS, ItemTypeIds::CHAINMAIL_LEGGINGS, ItemTypeIds::GOLDEN_LEGGINGS, ItemTypeIds::COPPER_LEGGINGS, ItemTypeIds::LEATHER_PANTS],
		[ItemTypeIds::NETHERITE_BOOTS, ItemTypeIds::DIAMOND_BOOTS, ItemTypeIds::IRON_BOOTS, ItemTypeIds::CHAINMAIL_BOOTS, ItemTypeIds::GOLDEN_BOOTS, ItemTypeIds::COPPER_BOOTS, ItemTypeIds::LEATHER_BOOTS]
	];
	/** Wither skeleton tools (priority 7), by tier. */
	private const TOOLS = [
		ItemTypeIds::WOODEN_SHOVEL, ItemTypeIds::STONE_SHOVEL, ItemTypeIds::COPPER_SHOVEL, ItemTypeIds::GOLDEN_SHOVEL, ItemTypeIds::IRON_SHOVEL, ItemTypeIds::DIAMOND_SHOVEL, ItemTypeIds::NETHERITE_SHOVEL,
		ItemTypeIds::WOODEN_PICKAXE, ItemTypeIds::STONE_PICKAXE, ItemTypeIds::COPPER_PICKAXE, ItemTypeIds::GOLDEN_PICKAXE, ItemTypeIds::IRON_PICKAXE, ItemTypeIds::DIAMOND_PICKAXE, ItemTypeIds::NETHERITE_PICKAXE,
		ItemTypeIds::WOODEN_AXE, ItemTypeIds::STONE_AXE, ItemTypeIds::COPPER_AXE, ItemTypeIds::GOLDEN_AXE, ItemTypeIds::IRON_AXE, ItemTypeIds::DIAMOND_AXE, ItemTypeIds::NETHERITE_AXE,
		ItemTypeIds::WOODEN_HOE, ItemTypeIds::STONE_HOE, ItemTypeIds::COPPER_HOE, ItemTypeIds::GOLDEN_HOE, ItemTypeIds::IRON_HOE, ItemTypeIds::DIAMOND_HOE, ItemTypeIds::NETHERITE_HOE
	];

	private static ?self $zombie = null;
	private static ?self $zombifiedPiglin = null;
	private static ?self $drowned = null;
	private static ?self $skeleton = null;
	private static ?self $bogged = null;
	private static ?self $witherSkeleton = null;
	private static ?self $piglin = null;

	/**
	 * @param int[] $priorities shareables key (see {@link MobShareables::getKey()}) => priority
	 * @phpstan-param array<int, int> $priorities
	 * @param int $excluded bit mask of EXCLUDE_* constants
	 */
	private function __construct(
		private array $priorities,
		public readonly bool $canPickUpAnyItem,
		public readonly bool $pickupBasedOnChance,
		public readonly bool $singularPickup,
		private int $excluded
	){}

	/**
	 * Zombies, husks and zombie villagers: any item but glow ink sacs and the ominous banner; swords, spears, armor,
	 * skeleton and wither skeleton skulls and carved pumpkins by priority.
	 */
	public static function zombie() : self{
		return self::$zombie ??= new self(self::weapons(0, true) + self::armor(), true, true, true, self::EXCLUDE_GLOW_INK_SAC | self::EXCLUDE_OMINOUS_BANNER);
	}

	/**
	 * Zombified piglins: any item (whole stacks), the zombie priorities.
	 */
	public static function zombifiedPiglin() : self{
		return self::$zombifiedPiglin ??= new self(self::weapons(0, true) + self::armor(), true, true, false, 0);
	}

	/**
	 * Drowned: any item but glow ink sacs, spears and the ominous banner; trident, nautilus shell, then swords, and armor.
	 */
	public static function drowned() : self{
		return self::$drowned ??= new self([ItemTypeIds::TRIDENT => 0, ItemTypeIds::NAUTILUS_SHELL => 1] + self::weapons(2, false) + self::armor(), true, true, true, self::EXCLUDE_GLOW_INK_SAC | self::EXCLUDE_SPEARS | self::EXCLUDE_OMINOUS_BANNER);
	}

	/**
	 * Skeletons and strays: any item but spears and the ominous banner; swords before their bow (priority 7), and armor.
	 */
	public static function skeleton() : self{
		return self::$skeleton ??= new self(self::weapons(0, false) + [ItemTypeIds::BOW => 7] + self::armor(), true, true, true, self::EXCLUDE_SPEARS | self::EXCLUDE_OMINOUS_BANNER);
	}

	/**
	 * Bogged and parched: as skeletons, but a bow is as good as a wooden sword (priority 6).
	 */
	public static function bogged() : self{
		return self::$bogged ??= new self(self::weapons(0, false) + [ItemTypeIds::BOW => 6] + self::armor(), true, true, true, self::EXCLUDE_SPEARS | self::EXCLUDE_OMINOUS_BANNER);
	}

	/**
	 * Wither skeletons: only swords, tools (priority 7) and armor.
	 */
	public static function witherSkeleton() : self{
		if(self::$witherSkeleton === null){
			$priorities = self::weapons(0, false);
			foreach(self::TOOLS as $id){
				$priorities[$id] = 7;
			}
			self::$witherSkeleton = new self($priorities + self::armor(), false, true, true, 0);
		}
		return self::$witherSkeleton;
	}

	/**
	 * Piglin gear (the golden items it admires and the food it eats are handled by {@link Piglin}): golden items 2,
	 * crossbow 2, other swords and spears 3-8, other armor 3-8, elytra 8, turtle helmet, heads, carved pumpkin and shield 9.
	 */
	public static function piglin() : self{
		if(self::$piglin === null){
			$priorities = [ItemTypeIds::CROSSBOW => 2, ItemTypeIds::ELYTRA => 8, ItemTypeIds::SHIELD => 9, ItemTypeIds::TURTLE_HELMET => 9, self::carvedPumpkinKey() => 9];
			foreach([self::SWORDS, self::SPEARS] as $tiers){
				foreach($tiers as $tier => $id){
					$priorities[$id] = $id === ItemTypeIds::GOLDEN_SWORD || $id === ItemTypeIds::GOLDEN_SPEAR ? 2 : ($tier < 3 ? $tier + 3 : $tier + 2);
				}
			}
			foreach(self::ARMOR as $tiers){
				foreach($tiers as $tier => $id){
					//golden (tier 4) is admired: 2; the others 3-8 without it
					$priorities[$id] = $tier === 4 ? 2 : ($tier < 4 ? $tier + 3 : $tier + 2);
				}
			}
			$priorities[ItemTypeIds::GOLDEN_AXE] = 2;
			foreach([MobHeadType::SKELETON, MobHeadType::WITHER_SKELETON, MobHeadType::ZOMBIE, MobHeadType::PLAYER, MobHeadType::CREEPER, MobHeadType::DRAGON] as $type){
				$priorities[self::skullKey($type)] = 9;
			}
			self::$piglin = new self($priorities, false, false, true, 0);
		}
		return self::$piglin;
	}

	/**
	 * Swords from $first (netherite) to $first + 6 (wooden), and the spears the same when $spears.
	 *
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private static function weapons(int $first, bool $spears) : array{
		$priorities = [];
		foreach(self::SWORDS as $tier => $id){
			$priorities[$id] = $first + $tier;
		}
		if($spears){
			foreach(self::SPEARS as $tier => $id){
				$priorities[$id] = $first + $tier;
			}
		}
		return $priorities;
	}

	/**
	 * The armor of the zombie and skeleton families: tiers 0-6 in each slot, turtle helmet 7, skeleton and wither
	 * skeleton skulls and carved pumpkin 8.
	 *
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private static function armor() : array{
		$priorities = [];
		foreach(self::ARMOR as $tiers){
			foreach($tiers as $tier => $id){
				$priorities[$id] = $tier;
			}
		}
		$priorities[ItemTypeIds::TURTLE_HELMET] = 7;
		$priorities[self::skullKey(MobHeadType::SKELETON)] = 8;
		$priorities[self::skullKey(MobHeadType::WITHER_SKELETON)] = 8;
		$priorities[self::carvedPumpkinKey()] = 8;
		return $priorities;
	}

	private static function skullKey(MobHeadType $type) : int{
		return self::SKULL_KEY - match($type){
			MobHeadType::SKELETON => 0,
			MobHeadType::WITHER_SKELETON => 1,
			MobHeadType::ZOMBIE => 2,
			MobHeadType::PLAYER => 3,
			MobHeadType::CREEPER => 4,
			MobHeadType::DRAGON => 5,
			MobHeadType::PIGLIN => 6
		};
	}

	private static function carvedPumpkinKey() : int{
		return ItemTypeIds::fromBlockTypeId(BlockTypeIds::CARVED_PUMPKIN);
	}

	/**
	 * Returns the key of the item in the priority table: its type ID, or one per head type for mob heads.
	 */
	private static function getKey(Item $item) : int{
		$id = $item->getTypeId();
		//only heads need their block (getBlock() copies it): all of them share one item type
		if($id < 0 && ItemTypeIds::toBlockTypeId($id) === BlockTypeIds::MOB_HEAD && $item instanceof ItemBlock){
			$block = $item->getBlock();
			if($block instanceof MobHead){
				return self::skullKey($block->getMobHeadType());
			}
		}
		return $id;
	}

	/**
	 * Returns the shareables priority of the item (lower is better), or null if the mob doesn't list it.
	 */
	public function getPriority(Item $item) : ?int{
		return $this->priorities[self::getKey($item)] ?? null;
	}

	/**
	 * Whether the mob never picks the item up (behavior.pickup_items excluded_items, and the items minecraft:equip_item
	 * excludes).
	 */
	public function isExcluded(Item $item) : bool{
		if($this->excluded === 0){
			return false;
		}
		$id = $item->getTypeId();
		return match(true){
			$id === ItemTypeIds::GLOW_INK_SAC => ($this->excluded & self::EXCLUDE_GLOW_INK_SAC) !== 0,
			$id === ItemTypeIds::OMINOUS_BANNER => ($this->excluded & self::EXCLUDE_OMINOUS_BANNER) !== 0,
			default => ($this->excluded & self::EXCLUDE_SPEARS) !== 0 && self::isSpear($id)
		};
	}

	private static function isSpear(int $typeId) : bool{
		return match($typeId){
			ItemTypeIds::WOODEN_SPEAR, ItemTypeIds::STONE_SPEAR, ItemTypeIds::COPPER_SPEAR, ItemTypeIds::IRON_SPEAR,
			ItemTypeIds::GOLDEN_SPEAR, ItemTypeIds::DIAMOND_SPEAR, ItemTypeIds::NETHERITE_SPEAR => true,
			default => false
		};
	}

	/**
	 * Returns the equipment slot (see {@link MobEquipment}) an item picked up goes to: listed items to their slot, any
	 * other item to the main hand.
	 */
	public function getSlotFor(Item $item) : int{
		return $this->getPriority($item) !== null ? MobEquipment::getSlotFor($item) : MobEquipment::SLOT_MAINHAND;
	}

	/**
	 * Whether the mob would swap $current, in the slot of $item, for $item (behavior.equip_item): a listed item replaces
	 * an unlisted one or one with a higher priority, and the same item enchanted replaces it plain. $priority is the
	 * priority of $item when the caller already has it.
	 */
	public function isBetter(Item $item, Item $current, ?int $priority = null) : bool{
		$priority ??= $this->getPriority($item);
		if($priority === null){
			return false;
		}
		$currentPriority = $this->getPriority($current);
		if($currentPriority === null || $priority < $currentPriority){
			return true;
		}
		return $priority === $currentPriority && $item->getTypeId() === $current->getTypeId() && $item->hasEnchantments() && !$current->hasEnchantments();
	}
}
