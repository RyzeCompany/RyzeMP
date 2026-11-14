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

namespace pocketmine\world\raid;

use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantingHelper;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\loot\bedrock\BedrockEquipmentTables;
use pocketmine\utils\Random;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * The extra loot of pillagers and vindicators spawned by a raid (Bedrock raid_configuration: pillager_raid.json and
 * vindicator_raid.json are the mob's own table plus raider_drops.json):
 * - 0-1 emerald (+0-1 per Looting level), whoever killed it;
 * - then, with a chance of 65% (80% on Hard, never on Peaceful), one of: 0-1 emerald (weight 40), 2-3 emeralds (20),
 *   4-5 emeralds (8; each +0-1 per Looting level), a book enchanted with 30 levels (8), or an iron pickaxe, axe,
 *   shovel, sword, helmet, chestplate, leggings or boots (10 each) with 30-90% of its durability left and enchanted
 *   like mob gear (enchant_random_gear chance 0.5, scaled by the regional difficulty like the spawn equipment).
 *
 * Simplification: the book never gets treasure enchantments (Mending, Frost Walker...), like fishing treasure books.
 */
final class RaiderLoot{
	public const BONUS_CHANCE = 0.65;
	public const BONUS_CHANCE_HARD = 0.8;

	private const ENTRY_EMERALD_0_1 = 0;
	private const ENTRY_EMERALD_2_3 = 1;
	private const ENTRY_EMERALD_4_5 = 2;
	private const ENTRY_BOOK = 3;
	private const ENTRY_GEAR = 4;

	/** Weights of the bonus pool: [entry, weight]; the gear entry stands for the 8 iron items of weight 10. */
	private const BONUS_WEIGHTS = [
		[self::ENTRY_EMERALD_0_1, 40],
		[self::ENTRY_EMERALD_2_3, 20],
		[self::ENTRY_EMERALD_4_5, 8],
		[self::ENTRY_BOOK, 8],
		[self::ENTRY_GEAR, 80],
	];
	private const TOTAL_WEIGHT = 156;

	private function __construct(){
		//NOOP
	}

	/**
	 * Chance of the bonus roll in this difficulty.
	 */
	public static function getBonusChance(int $difficulty) : float{
		return match($difficulty){
			World::DIFFICULTY_PEACEFUL => 0.0,
			World::DIFFICULTY_HARD => self::BONUS_CHANCE_HARD,
			default => self::BONUS_CHANCE
		};
	}

	/**
	 * Rolls the raider drops. Randomness comes from mt_rand(), so that seeded tests are reproducible.
	 *
	 * @param int   $difficulty        one of the World::DIFFICULTY_* constants
	 * @param int   $lootingLevel      Looting level of the killer's weapon (0 if not killed by a player)
	 * @param float $specialMultiplier clamped regional difficulty (0-1) where the raider died
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public static function roll(int $difficulty, int $lootingLevel, float $specialMultiplier) : array{
		$drops = [];
		$count = mt_rand(0, 1) + self::looting($lootingLevel);
		if($count > 0){
			$drops[] = VanillaItems::EMERALD()->setCount($count);
		}
		if(Utils::getRandomFloat() >= self::getBonusChance($difficulty)){
			return $drops;
		}
		$bonus = self::rollBonus($lootingLevel, $specialMultiplier);
		if($bonus !== null){
			$drops[] = $bonus;
		}
		return $drops;
	}

	private static function rollBonus(int $lootingLevel, float $specialMultiplier) : ?Item{
		$pick = mt_rand(0, self::TOTAL_WEIGHT - 1);
		$entry = self::ENTRY_GEAR;
		foreach(self::BONUS_WEIGHTS as [$candidate, $weight]){
			if($pick < $weight){
				$entry = $candidate;
				break;
			}
			$pick -= $weight;
		}
		$count = match($entry){
			self::ENTRY_EMERALD_0_1 => mt_rand(0, 1),
			self::ENTRY_EMERALD_2_3 => mt_rand(2, 3),
			self::ENTRY_EMERALD_4_5 => mt_rand(4, 5),
			default => -1
		};
		if($count !== -1){
			$count += self::looting($lootingLevel);
			return $count > 0 ? VanillaItems::EMERALD()->setCount($count) : null;
		}
		if($entry === self::ENTRY_BOOK){
			return EnchantingHelper::enchantWithLevels(VanillaItems::BOOK(), 30, new Random(mt_rand()));
		}
		$item = match(mt_rand(0, 7)){
			0 => VanillaItems::IRON_PICKAXE(),
			1 => VanillaItems::IRON_AXE(),
			2 => VanillaItems::IRON_SHOVEL(),
			3 => VanillaItems::IRON_SWORD(),
			4 => VanillaItems::IRON_HELMET(),
			5 => VanillaItems::IRON_CHESTPLATE(),
			6 => VanillaItems::IRON_LEGGINGS(),
			default => VanillaItems::IRON_BOOTS()
		};
		$item = BedrockEquipmentTables::enchantRandomGear($item, 0.5, $specialMultiplier);
		if($item instanceof Durable){
			//set_damage 0.3-0.9: the fraction of the durability left
			$left = 0.3 + 0.6 * mt_rand(0, 1000) / 1000;
			$item->setDamage((int) floor($item->getMaxDurability() * (1 - $left)));
		}
		return $item;
	}

	/**
	 * Vanilla looting_enchant {0, 1}: 0-1 extra items per Looting level.
	 */
	private static function looting(int $level) : int{
		return $level > 0 ? mt_rand(0, $level) : 0;
	}
}
