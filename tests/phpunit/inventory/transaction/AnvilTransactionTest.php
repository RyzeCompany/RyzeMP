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

namespace pocketmine\inventory\transaction;

use PHPUnit\Framework\TestCase;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function intdiv;

final class AnvilTransactionTest extends TestCase{

	private static function book(EnchantmentInstance $enchantment) : Item{
		return VanillaItems::ENCHANTED_BOOK()->addEnchantment($enchantment);
	}

	public function testRepairWithMaterial() : void{
		$pickaxe = VanillaItems::IRON_PICKAXE();
		$pickaxe->setDamage(200);
		$result = AnvilTransaction::calculateResult($pickaxe, VanillaItems::IRON_INGOT()->setCount(5), null, false);
		self::assertNotNull($result);
		$item = $result->getResult();
		self::assertInstanceOf(Durable::class, $item);
		self::assertSame(0, $item->getDamage(), "200 - 62 * 3, then the last 14");
		self::assertSame(4, $result->getMaterialUsed());
		self::assertSame(4, $result->getXpCost());
		self::assertSame(1, AnvilTransaction::getRepairCost($item));

		self::assertNull(AnvilTransaction::calculateResult(VanillaItems::IRON_PICKAXE(), VanillaItems::IRON_INGOT(), null, false), "nothing to repair");
		self::assertNull(AnvilTransaction::calculateResult($pickaxe, VanillaItems::DIAMOND(), null, false), "wrong material");
	}

	public function testCombineEnchantedBook() : void{
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1));
		$result = AnvilTransaction::calculateResult($sword, self::book(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1)), null, false);
		self::assertNotNull($result);
		self::assertSame(2, $result->getResult()->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
		self::assertSame(2, $result->getXpCost(), "common enchantment, halved for books (min 1), times level 2");
		self::assertSame(1, $result->getMaterialUsed());
	}

	public function testIncompatibleAndUnsuitableEnchantmentsFail() : void{
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1));
		self::assertNull(AnvilTransaction::calculateResult($sword, self::book(new EnchantmentInstance(VanillaEnchantments::SMITE(), 1)), null, false));
		self::assertNull(AnvilTransaction::calculateResult(VanillaItems::DIAMOND_SWORD(), self::book(new EnchantmentInstance(VanillaEnchantments::LURE(), 1)), null, false));
		self::assertNotNull(AnvilTransaction::calculateResult(VanillaItems::DIAMOND_SWORD(), self::book(new EnchantmentInstance(VanillaEnchantments::LURE(), 1)), null, true), "creative");
	}

	public function testRename() : void{
		$result = AnvilTransaction::calculateResult(VanillaItems::DIAMOND(), null, "Shiny", false);
		self::assertNotNull($result);
		self::assertSame("Shiny", $result->getResult()->getCustomName());
		self::assertSame(1, $result->getXpCost());
		self::assertSame(0, AnvilTransaction::getRepairCost($result->getResult()), "renaming alone adds no prior work penalty");
		self::assertNull(AnvilTransaction::calculateResult(VanillaItems::DIAMOND(), null, null, false), "nothing changed");
		self::assertNull(AnvilTransaction::calculateResult(VanillaItems::DIAMOND(), null, "Diamond", false), "same name");

		$named = VanillaItems::DIAMOND()->setCustomName("Shiny");
		$cleared = AnvilTransaction::calculateResult($named, null, "", false);
		self::assertNotNull($cleared);
		self::assertFalse($cleared->getResult()->hasCustomName());
	}

	public function testTooExpensive() : void{
		$sword = VanillaItems::DIAMOND_SWORD();
		$nbt = $sword->getNamedTag();
		$nbt->setInt(AnvilTransaction::TAG_REPAIR_COST, 39);
		$sword->setNamedTag($nbt);
		$rename = AnvilTransaction::calculateResult($sword, null, "Old", false);
		self::assertNotNull($rename);
		self::assertSame(39, $rename->getXpCost(), "renaming is capped at 39 levels");
		self::assertNull(AnvilTransaction::calculateResult($sword, self::book(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1)), null, false));
		self::assertNotNull(AnvilTransaction::calculateResult($sword, self::book(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1)), null, true));
	}

	public function testCombineDamagedTools() : void{
		$a = VanillaItems::DIAMOND_SWORD();
		$a->setDamage(1000);
		$b = VanillaItems::DIAMOND_SWORD();
		$b->setDamage(1000);
		$result = AnvilTransaction::calculateResult($a, $b, null, false);
		self::assertNotNull($result);
		$item = $result->getResult();
		self::assertInstanceOf(Durable::class, $item);
		//both remaining durabilities plus 12% of the maximum
		$max = $item->getMaxDurability();
		self::assertSame($max - (2 * ($max - 1000) + intdiv($max * 12, 100)), $item->getDamage());
		self::assertSame(2, $result->getXpCost());
	}
}
