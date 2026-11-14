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

use pocketmine\block\VanillaBlocks;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use function mt_rand;

/**
 * The bartering loot table (Bedrock loot_tables/entities/piglin_barter.json): one roll, weights out of 469.
 * Bedrock gives plain arrows where Java gives spectral arrows, and soul speed books are enchanted books.
 */
final class PiglinBarter{

	private function __construct(){
		//NOOP
	}

	/**
	 * @var array[]|null
	 * @phpstan-var list<array{int, \Closure() : Item, int, int}>|null
	 */
	private static ?array $entries = null;

	/**
	 * Returns the entries of the table: weight, item factory, minimum and maximum count.
	 *
	 * @return array[]
	 * @phpstan-return list<array{int, \Closure() : Item, int, int}>
	 */
	public static function getEntries() : array{
		return self::$entries ??= [
			[5, fn() => self::soulSpeed(VanillaItems::ENCHANTED_BOOK()), 1, 1],
			[8, fn() => self::soulSpeed(VanillaItems::IRON_BOOTS()), 1, 1],
			[8, fn() => VanillaItems::POTION()->setType(PotionType::FIRE_RESISTANCE), 1, 1],
			[8, fn() => VanillaItems::SPLASH_POTION()->setType(PotionType::FIRE_RESISTANCE), 1, 1],
			[10, fn() => VanillaItems::POTION()->setType(PotionType::WATER), 1, 1],
			[10, fn() => VanillaItems::IRON_NUGGET(), 10, 36],
			[10, fn() => VanillaItems::ENDER_PEARL(), 2, 4],
			[20, fn() => VanillaItems::STRING(), 3, 9],
			[20, fn() => VanillaItems::NETHER_QUARTZ(), 5, 12],
			[40, fn() => VanillaBlocks::OBSIDIAN()->asItem(), 1, 1],
			[40, fn() => VanillaBlocks::CRYING_OBSIDIAN()->asItem(), 1, 3],
			[40, fn() => VanillaItems::FIRE_CHARGE(), 1, 1],
			[40, fn() => VanillaItems::LEATHER(), 2, 4],
			[40, fn() => VanillaBlocks::SOUL_SAND()->asItem(), 2, 8],
			[40, fn() => VanillaItems::NETHER_BRICK(), 2, 8],
			[40, fn() => VanillaItems::ARROW(), 6, 12],
			[40, fn() => VanillaBlocks::GRAVEL()->asItem(), 8, 16],
			[40, fn() => VanillaBlocks::BLACKSTONE()->asItem(), 8, 16],
			[10, fn() => VanillaBlocks::DRIED_GHAST()->asItem(), 1, 1],
		];
	}

	public static function getTotalWeight() : int{
		$total = 0;
		foreach(self::getEntries() as [$weight, , , ]){
			$total += $weight;
		}
		return $total;
	}

	/**
	 * Soul Speed I-III (loot function specific_enchants with a level range).
	 */
	private static function soulSpeed(Item $item) : Item{
		return $item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SOUL_SPEED(), mt_rand(1, 3)));
	}

	/**
	 * Rolls the table once.
	 */
	public static function roll() : Item{
		$entries = self::getEntries();
		$roll = mt_rand(0, self::getTotalWeight() - 1);
		foreach($entries as [$weight, $make, $min, $max]){
			if($roll < $weight){
				$item = $make();
				return $max > 1 ? $item->setCount(mt_rand($min, $max)) : $item;
			}
			$roll -= $weight;
		}
		return VanillaItems::STRING()->setCount(mt_rand(3, 9));
	}
}
