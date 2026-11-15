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

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantingHelper;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Random;
use function in_array;
use function mt_srand;

final class FishingLootTest extends TestCase{

	public function testCategoryWeights() : void{
		self::assertSame([FishingLoot::CATEGORY_FISH => 85, FishingLoot::CATEGORY_JUNK => 10, FishingLoot::CATEGORY_TREASURE => 5], FishingLoot::categoryWeights(0));
		self::assertSame([FishingLoot::CATEGORY_FISH => 82, FishingLoot::CATEGORY_JUNK => 4, FishingLoot::CATEGORY_TREASURE => 11], FishingLoot::categoryWeights(3), "Luck of the Sea III");
	}

	public function testRollsAreMostlyFish() : void{
		$fish = [ItemTypeIds::RAW_FISH, ItemTypeIds::RAW_SALMON, ItemTypeIds::CLOWNFISH, ItemTypeIds::PUFFERFISH];
		$caught = 0;
		for($i = 0; $i < 400; ++$i){
			$item = FishingLoot::roll(0);
			self::assertFalse($item->isNull());
			if(in_array($item->getTypeId(), $fish, true)){
				++$caught;
			}
		}
		self::assertGreaterThan(300, $caught, "about 85% fish");
	}

	public function testTreasureIsEnchanted() : void{
		$book = EnchantingHelper::enchantWithLevels(VanillaItems::BOOK(), 30, new Random(1));
		self::assertSame(ItemTypeIds::ENCHANTED_BOOK, $book->getTypeId());
		self::assertTrue($book->hasEnchantments());
		$bow = EnchantingHelper::enchantWithLevels(VanillaItems::BOW(), 30, new Random(2));
		self::assertTrue($bow->hasEnchantments());
	}

	/**
	 * Bedrock enchant_with_levels "treasure": true (fishing treasure) can give Mending; enchanting tables never do.
	 */
	public function testTreasureEnchantments() : void{
		$mending = 0;
		for($seed = 0; $seed < 400; ++$seed){
			self::assertFalse(EnchantingHelper::enchantWithLevels(VanillaItems::BOOK(), 30, new Random($seed))->hasEnchantment(VanillaEnchantments::MENDING()));
			if(EnchantingHelper::enchantWithLevels(VanillaItems::BOOK(), 30, new Random($seed), true)->hasEnchantment(VanillaEnchantments::MENDING())){
				++$mending;
			}
		}
		self::assertGreaterThan(0, $mending);
	}

	/**
	 * set_damage gives the durability LEFT: treasure bows and rods keep 0-25%, junk boots and rods 0-90%.
	 */
	public function testDamageIsRemainingDurability() : void{
		mt_srand(42);
		for($i = 0; $i < 200; ++$i){
			$bow = FishingLoot::damaged(VanillaItems::BOW(), 0.25);
			self::assertInstanceOf(Durable::class, $bow);
			$max = $bow->getMaxDurability();
			self::assertGreaterThanOrEqual((int) ($max * 0.75), $bow->getDamage());
			self::assertLessThan($max, $bow->getDamage());

			$boots = FishingLoot::damaged(VanillaItems::LEATHER_BOOTS(), 0.9);
			self::assertInstanceOf(Durable::class, $boots);
			self::assertGreaterThanOrEqual((int) ($boots->getMaxDurability() * 0.1), $boots->getDamage());
		}
	}

	public function testJungleTables() : void{
		mt_srand(7);
		$lilyPad = VanillaBlocks::LILY_PAD()->asItem()->getTypeId();
		$seen = [];
		for($i = 0; $i < 600; ++$i){
			$junk = FishingLoot::junk(true);
			$seen[$junk->getTypeId()] = true;
			self::assertNotSame($lilyPad, $junk->getTypeId(), "no lily pad in jungle junk");
		}
		self::assertArrayHasKey(ItemTypeIds::BAMBOO, $seen);
		self::assertArrayHasKey(ItemTypeIds::COCOA_BEANS, $seen);

		$seen = [];
		for($i = 0; $i < 600; ++$i){
			$seen[FishingLoot::junk()->getTypeId()] = true;
		}
		self::assertArrayNotHasKey(ItemTypeIds::BAMBOO, $seen);
		self::assertArrayNotHasKey(ItemTypeIds::COCOA_BEANS, $seen);
	}
}
