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

namespace pocketmine\entity\npc;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\npc\trade\TradeContext;
use pocketmine\entity\npc\trade\TradeFunctions;
use pocketmine\entity\npc\trade\TradeTables;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Random;
use function array_map;
use function count;
use function implode;
use function in_array;
use function min;

final class VillagerTradesTest extends TestCase{

	private static function names(TradeOffer $offer) : string{
		$name = fn(Item $item) => $item->getCount() . " " . $item->getVanillaName();
		$buyB = $offer->getBuyB();
		return $name($offer->getBuyA()) . ($buyB !== null ? " + " . $name($buyB) : "") . " -> " . $name($offer->getSell());
	}

	/**
	 * @param TradeOffer[] $offers
	 */
	private static function sells(array $offers, Item $item) : bool{
		foreach($offers as $offer){
			if($offer->getSell()->getTypeId() === $item->getTypeId()){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param TradeOffer[] $offers
	 */
	private static function buys(array $offers, Item $item) : bool{
		foreach($offers as $offer){
			if($offer->getBuyA()->getTypeId() === $item->getTypeId()){
				return true;
			}
		}
		return false;
	}

	public function testEveryItemIsKnown() : void{
		self::assertSame([], VillagerTrades::getUnknownItemNames());
	}

	public function testLevelThresholdsMatchTables() : void{
		foreach(VillagerTrades::getTradingProfessions() as $profession){
			$table = VillagerTrades::getTableName($profession);
			self::assertNotNull($table);
			self::assertSame(VillagerTrades::TIER_EXP_REQUIREMENTS, TradeTables::getTierExpRequirements($table), $table);
		}
	}

	/**
	 * Every level gives num_to_select trades of each group (all of them for -1), whatever the villager type. Only the
	 * cartographer's explorer maps are not made yet.
	 */
	public function testOfferCountsFollowGroups() : void{
		$expected = [];
		foreach(VillagerTrades::getTradingProfessions() as $profession){
			$table = VillagerTrades::getTableName($profession);
			self::assertNotNull($table);
			foreach(TradeTables::getTiers($table) as $tier => $tierData){
				$sum = 0;
				foreach($tierData["groups"] as $group){
					$sum += $group["select"] < 0 ? count($group["trades"]) : min($group["select"], count($group["trades"]));
				}
				$expected[$profession][$tier] = $sum;
			}
		}
		for($seed = 1; $seed <= 20; ++$seed){
			$random = new Random($seed);
			foreach($expected as $profession => $tiers){
				foreach($tiers as $tier => $count){
					$offers = VillagerTrades::createOffers($profession, $tier, $random, $seed % 7);
					if($profession === VillagerV2::PROFESSION_CARTOGRAPHER){
						self::assertGreaterThanOrEqual(1, count($offers), "cartographer level $tier");
						self::assertLessThanOrEqual($count, count($offers), "cartographer level $tier");
					}else{
						self::assertCount($count, $offers, VillagerTrades::getTableName($profession) . " level $tier, seed $seed: " . implode(", ", array_map(self::names(...), $offers)));
					}
					foreach($offers as $offer){
						self::assertSame($tier, $offer->getTier());
					}
				}
			}
		}
	}

	public function testNoviceTrades() : void{
		for($seed = 1; $seed <= 10; ++$seed){
			$farmer = VillagerTrades::createOffers(VillagerV2::PROFESSION_FARMER, 0, new Random($seed));
			self::assertCount(2, $farmer);
			self::assertTrue(self::sells($farmer, VanillaItems::BREAD()), "farmer sells bread");

			$armorer = VillagerTrades::createOffers(VillagerV2::PROFESSION_ARMORER, 0, new Random($seed));
			self::assertCount(2, $armorer);
			self::assertTrue(self::buys($armorer, VanillaItems::COAL()), "armorer buys coal");
			self::assertSame(0.05, $armorer[0]->getPriceMultiplier());
			self::assertSame(0.2, $armorer[1]->getPriceMultiplier(), "gear has a 0.2 price multiplier");

			$weaponsmith = VillagerTrades::createOffers(VillagerV2::PROFESSION_WEAPONSMITH, 0, new Random($seed));
			self::assertCount(3, $weaponsmith);
			self::assertTrue(self::sells($weaponsmith, VanillaItems::IRON_AXE()));
			self::assertTrue(self::sells($weaponsmith, VanillaItems::IRON_SWORD()));
			foreach($weaponsmith as $offer){
				if($offer->getSell()->getTypeId() === ItemTypeIds::IRON_SWORD){
					self::assertTrue($offer->getSell()->hasEnchantments(), "the novice weaponsmith sword is enchanted");
					self::assertSame(3, $offer->getMaxUses());
				}
			}

			$cleric = VillagerTrades::createOffers(VillagerV2::PROFESSION_CLERIC, 3, new Random($seed));
			self::assertCount(2, $cleric);
			self::assertTrue(self::sells($cleric, VanillaItems::ENDER_PEARL()), "expert cleric sells ender pearls");
			self::assertTrue(self::buys($cleric, VanillaItems::SCUTE()) || self::buys($cleric, VanillaItems::GLASS_BOTTLE()));

			$mason = VillagerTrades::createOffers(VillagerV2::PROFESSION_MASON, 0, new Random($seed));
			self::assertCount(2, $mason, "the mason gets every trade of its level");
		}
	}

	public function testEnchantedGear() : void{
		$toolsmith = VillagerTrades::createOffers(VillagerV2::PROFESSION_TOOLSMITH, 4, new Random(5));
		self::assertCount(1, $toolsmith);
		self::assertSame(ItemTypeIds::DIAMOND_PICKAXE, $toolsmith[0]->getSell()->getTypeId());
		self::assertTrue($toolsmith[0]->getSell()->hasEnchantments());
		self::assertSame(13, $toolsmith[0]->getBuyA()->getCount());
		self::assertSame(0.2, $toolsmith[0]->getPriceMultiplier());
		self::assertSame(3, $toolsmith[0]->getMaxUses());
	}

	public function testLibrarianBooks() : void{
		$untradeable = [VanillaEnchantments::SOUL_SPEED(), VanillaEnchantments::SWIFT_SNEAK(), VanillaEnchantments::WIND_BURST()];
		$treasure = [VanillaEnchantments::MENDING(), VanillaEnchantments::FROST_WALKER(), VanillaEnchantments::BINDING(), VanillaEnchantments::VANISHING()];
		$books = 0;
		for($seed = 1; $seed <= 300; ++$seed){
			$offers = VillagerTrades::createOffers(VillagerV2::PROFESSION_LIBRARIAN, $seed % 4, new Random($seed));
			foreach($offers as $offer){
				$sell = $offer->getSell();
				if($sell->getTypeId() !== ItemTypeIds::ENCHANTED_BOOK){
					continue;
				}
				++$books;
				$buyB = $offer->getBuyB();
				self::assertNotNull($buyB);
				self::assertSame(ItemTypeIds::BOOK, $buyB->getTypeId());
				self::assertSame(1, $buyB->getCount());
				self::assertSame(0.2, $offer->getPriceMultiplier());
				self::assertSame(0.2, $offer->getPriceMultiplierB());
				$enchantments = $sell->getEnchantments();
				self::assertCount(1, $enchantments);
				foreach($enchantments as $instance){
					$type = $instance->getType();
					self::assertNotContains($type, $untradeable);
					self::assertGreaterThanOrEqual(1, $instance->getLevel());
					self::assertLessThanOrEqual($type->getMaxLevel(), $instance->getLevel());
					$level = $instance->getLevel();
					$factor = in_array($type, $treasure, true) ? 2 : 1;
					$price = $offer->getBuyA()->getCount();
					self::assertGreaterThanOrEqual(min(64, (2 + 3 * $level) * $factor), $price);
					self::assertLessThanOrEqual(min(64, (2 + 5 + 13 * $level) * $factor), $price);
				}
			}
		}
		self::assertGreaterThan(50, $books);
	}

	public function testTradeableEnchantments() : void{
		$tradeable = [];
		foreach(TradeFunctions::getTradeableEnchantments() as [$enchantment, $treasure]){
			$tradeable[] = $enchantment;
			if($enchantment === VanillaEnchantments::MENDING()){
				self::assertTrue($treasure);
			}
			if($enchantment === VanillaEnchantments::SHARPNESS()){
				self::assertFalse($treasure);
			}
		}
		self::assertNotContains(VanillaEnchantments::SOUL_SPEED(), $tradeable);
		self::assertNotContains(VanillaEnchantments::WIND_BURST(), $tradeable);
		self::assertContains(VanillaEnchantments::MENDING(), $tradeable);
	}

	public function testMarkVariantFilters() : void{
		//master fisherman: pufferfish and a boat of the villager's biome
		$boats = [
			VillagerV2::TYPE_PLAINS => VanillaItems::OAK_BOAT(),
			VillagerV2::TYPE_DESERT => VanillaItems::JUNGLE_BOAT(),
			VillagerV2::TYPE_SAVANNA => VanillaItems::ACACIA_BOAT(),
			VillagerV2::TYPE_SNOW => VanillaItems::SPRUCE_BOAT(),
			VillagerV2::TYPE_SWAMP => VanillaItems::DARK_OAK_BOAT(),
		];
		foreach($boats as $type => $boat){
			$offers = VillagerTrades::createOffers(VillagerV2::PROFESSION_FISHERMAN, 4, new Random(1), $type);
			self::assertCount(2, $offers);
			self::assertTrue(self::buys($offers, $boat), "villager type $type");
		}
	}

	public function testFilterEvaluation() : void{
		$context = new TradeContext(new Random(1), 4);
		self::assertTrue(TradeTables::matchesFilter(["test" => "is_mark_variant", "subject" => "self", "value" => 4, "operator" => "="], $context));
		self::assertFalse(TradeTables::matchesFilter(["test" => "is_mark_variant", "value" => 3], $context));
		self::assertTrue(TradeTables::matchesFilter(["any_of" => [["test" => "is_mark_variant", "value" => 3], ["test" => "is_mark_variant", "value" => 4]]], $context));
		self::assertFalse(TradeTables::matchesFilter(["all_of" => [["test" => "in_overworld", "value" => true], ["test" => "is_mark_variant", "value" => 3]]], $context));
		self::assertTrue(TradeTables::matchesFilter(["none_of" => [["test" => "is_mark_variant", "value" => 3]]], $context));
		self::assertFalse(TradeTables::matchesFilter(["test" => "unknown_test", "value" => 1], $context));
	}

	public function testWanderingTrader() : void{
		for($seed = 1; $seed <= 20; ++$seed){
			$offers = VillagerTrades::createWanderingTraderOffers(new Random($seed));
			self::assertCount(9, $offers, implode(", ", array_map(self::names(...), $offers)));
			$emerald = VanillaItems::EMERALD()->getTypeId();
			for($i = 0; $i < 2; ++$i){
				self::assertSame($emerald, $offers[$i]->getSell()->getTypeId(), "two trades buy items for emeralds");
			}
			for($i = 2; $i < 9; ++$i){
				self::assertSame($emerald, $offers[$i]->getBuyA()->getTypeId(), "seven trades sell items for emeralds");
			}
		}
	}

	public function testSeededOffersAreDeterministic() : void{
		foreach([VillagerV2::PROFESSION_LIBRARIAN, VillagerV2::PROFESSION_ARMORER, VillagerV2::PROFESSION_SHEPHERD] as $profession){
			for($tier = 0; $tier <= VillagerV2::MAX_TIER; ++$tier){
				self::assertSame(
					array_map(self::names(...), VillagerTrades::createOffers($profession, $tier, new Random(42))),
					array_map(self::names(...), VillagerTrades::createOffers($profession, $tier, new Random(42)))
				);
			}
		}
	}

	public function testOfferNbtKeepsMultipliers() : void{
		$offer = new TradeOffer(VanillaItems::EMERALD()->setCount(12), VanillaItems::BOOK(), VanillaItems::ENCHANTED_BOOK(), 12, 2, 10, false, 3, 0.2, 0.2);
		$copy = TradeOffer::fromNbt($offer->toNbt());
		//TAG_Float: single precision
		self::assertEqualsWithDelta(0.2, $copy->getPriceMultiplier(), 1e-6);
		self::assertEqualsWithDelta(0.2, $copy->getPriceMultiplierB(), 1e-6);
		self::assertFalse($copy->givesPlayerExp());
		self::assertSame(3, $copy->getUses());

		//offers saved before the B multiplier existed
		$nbt = $offer->toNbt();
		$nbt->removeTag("priceMultiplierB");
		self::assertSame(0.0, TradeOffer::fromNbt($nbt)->getPriceMultiplierB());
	}
}
