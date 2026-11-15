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

namespace pocketmine\entity\projectile;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantingHelper;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Random;
use pocketmine\utils\Utils;
use function floor;
use function max;
use function min;
use function mt_rand;

/**
 * The Bedrock fishing loot tables (gameplay/fishing.json, or gameplay/jungle_fishing.json when the bobber was cast in a
 * jungle biome): fish (weight 85, quality -1), junk (10, quality -2) and treasure (5, quality +2). Each level of Luck
 * of the Sea adds the quality to the weight, so it trades fish and junk for treasure. Unlike Java, treasure needs no
 * open water. Treasure bows, rods and books are enchanted like a level 30 enchanting table, treasure enchantments
 * (Mending...) included.
 */
final class FishingLoot{
	public const CATEGORY_FISH = "fish";
	public const CATEGORY_JUNK = "junk";
	public const CATEGORY_TREASURE = "treasure";

	private function __construct(){
		//NOOP
	}

	/**
	 * Weights of the three categories for the given luck.
	 * @return int[]
	 * @phpstan-return array<self::CATEGORY_*, int>
	 */
	public static function categoryWeights(int $luck) : array{
		return [
			self::CATEGORY_FISH => max(0, (int) floor(85 - $luck)),
			self::CATEGORY_JUNK => max(0, (int) floor(10 - 2 * $luck)),
			self::CATEGORY_TREASURE => max(0, (int) floor(5 + 2 * $luck))
		];
	}

	/**
	 * @phpstan-template T of string
	 * @phpstan-param array<T, int> $weights
	 * @phpstan-return T
	 */
	private static function pick(array $weights) : string{
		$total = 0;
		foreach($weights as $weight){
			$total += $weight;
		}
		$roll = mt_rand(0, max(0, $total - 1));
		foreach(Utils::stringifyKeys($weights) as $key => $weight){
			if($roll < $weight){
				return $key;
			}
			$roll -= $weight;
		}
		foreach(Utils::stringifyKeys($weights) as $key => $_){
			return $key;
		}
		throw new \LogicException("No weights given");
	}

	/**
	 * @param bool $jungle whether the bobber was cast in a jungle biome (jungle_fishing.json)
	 */
	public static function roll(int $luck, bool $jungle = false) : Item{
		return match(self::pick(self::categoryWeights($luck))){
			self::CATEGORY_FISH => self::fish($jungle),
			self::CATEGORY_JUNK => self::junk($jungle),
			default => self::treasure()
		};
	}

	/**
	 * fishing/fish.json, or fishing/jungle_fish.json (more salmon).
	 */
	public static function fish(bool $jungle = false) : Item{
		return match(self::pick(["cod" => 60, "salmon" => $jungle ? 40 : 25, "tropical" => 2, "pufferfish" => 13])){
			"cod" => VanillaItems::RAW_FISH(),
			"salmon" => VanillaItems::RAW_SALMON(),
			"tropical" => VanillaItems::CLOWNFISH(),
			default => VanillaItems::PUFFERFISH()
		};
	}

	/**
	 * fishing/junk.json (ink sacs are in it twice, as ink_sac and as dye 0), or fishing/jungle_junk.json (no lily pad,
	 * one ink sac entry, cocoa beans and bamboo).
	 */
	public static function junk(bool $jungle = false) : Item{
		$weights = $jungle ? [
			"leather_boots" => 10, "leather" => 10, "bone" => 10, "water_bottle" => 10, "string" => 5, "fishing_rod" => 2,
			"bowl" => 10, "stick" => 5, "ink_sac" => 1, "cocoa_beans" => 10, "tripwire_hook" => 10, "rotten_flesh" => 10,
			"bamboo" => 10
		] : [
			"leather_boots" => 10, "lily_pad" => 17, "ink_sac" => 2, "leather" => 10, "bone" => 10, "water_bottle" => 10,
			"string" => 5, "fishing_rod" => 2, "bowl" => 10, "stick" => 5, "tripwire_hook" => 10, "rotten_flesh" => 10
		];
		return match(self::pick($weights)){
			"lily_pad" => VanillaBlocks::LILY_PAD()->asItem(),
			"leather_boots" => self::damaged(VanillaItems::LEATHER_BOOTS(), 0.9),
			"leather" => VanillaItems::LEATHER(),
			"bone" => VanillaItems::BONE(),
			"water_bottle" => VanillaItems::POTION()->setType(PotionType::WATER),
			"string" => VanillaItems::STRING(),
			"fishing_rod" => self::damaged(VanillaItems::FISHING_ROD(), 0.9),
			"bowl" => VanillaItems::BOWL(),
			"stick" => VanillaItems::STICK(),
			"ink_sac" => VanillaItems::INK_SAC()->setCount(10),
			"cocoa_beans" => VanillaItems::COCOA_BEANS(),
			"bamboo" => VanillaItems::BAMBOO(),
			"tripwire_hook" => VanillaBlocks::TRIPWIRE_HOOK()->asItem(),
			default => VanillaItems::ROTTEN_FLESH()
		};
	}

	/**
	 * fishing/treasure.json (the same in jungles).
	 */
	public static function treasure() : Item{
		return match(self::pick(["nautilus_shell" => 5, "name_tag" => 5, "saddle" => 5, "bow" => 5, "fishing_rod" => 5, "book" => 6])){
			"name_tag" => VanillaItems::NAME_TAG(),
			"saddle" => VanillaItems::SADDLE(),
			"bow" => self::enchanted(self::damaged(VanillaItems::BOW(), 0.25)),
			"fishing_rod" => self::enchanted(self::damaged(VanillaItems::FISHING_ROD(), 0.25)),
			"book" => self::enchanted(VanillaItems::BOOK()),
			default => VanillaItems::NAUTILUS_SHELL()
		};
	}

	/**
	 * Vanilla set_damage {min: 0, max: $maxRemaining}: the value is the fraction of durability LEFT, uniform between 0
	 * and $maxRemaining.
	 * @internal
	 */
	public static function damaged(Item $item, float $maxRemaining) : Item{
		if($item instanceof Durable){
			$max = $item->getMaxDurability();
			$remaining = $maxRemaining * mt_rand(0, 1000) / 1000;
			//at least 1 durability left: a stack with all its durability used counts as broken
			$item->setDamage(max(0, min($max - 1, (int) floor($max * (1 - $remaining)))));
		}
		return $item;
	}

	private static function enchanted(Item $item) : Item{
		return EnchantingHelper::enchantWithLevels($item, 30, new Random(mt_rand()), treasure: true);
	}
}
