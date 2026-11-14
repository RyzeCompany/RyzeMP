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
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use function intdiv;

class GrindstoneTransactionTest extends TestCase{

	public function testSingleUnenchantedItemHasNoResult() : void{
		self::assertNull(GrindstoneTransaction::calculateResult(VanillaItems::IRON_SWORD(), null));
		self::assertNull(GrindstoneTransaction::calculateResult(null, VanillaItems::STICK()));
		self::assertNull(GrindstoneTransaction::calculateResult(null, null));
	}

	public function testDisenchantKeepsCursesAndDamage() : void{
		$sword = VanillaItems::DIAMOND_SWORD();
		$sword->setDamage(100);
		$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
		$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::VANISHING(), 1));
		$sword->getNamedTag(); //make sure the tag exists
		$result = GrindstoneTransaction::calculateResult(null, $sword);
		self::assertNotNull($result);
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame(100, $result->getDamage());
		self::assertFalse($result->hasEnchantment(VanillaEnchantments::SHARPNESS()));
		self::assertTrue($result->hasEnchantment(VanillaEnchantments::VANISHING()));
		self::assertSame(1, $result->getNamedTag()->getInt(GrindstoneTransaction::TAG_REPAIR_COST));

		self::assertSame(VanillaEnchantments::SHARPNESS()->getMinEnchantingPower(3), GrindstoneTransaction::getEnchantmentExperience($sword));
	}

	public function testRepairCombinesDurability() : void{
		$a = VanillaItems::IRON_PICKAXE();
		$b = VanillaItems::IRON_PICKAXE();
		$max = $a->getMaxDurability();
		$a->setDamage($max - 10);
		$b->setDamage($max - 20);
		$b->addEnchantment(new EnchantmentInstance(VanillaEnchantments::EFFICIENCY(), 2));
		$result = GrindstoneTransaction::calculateResult($a, $b);
		self::assertInstanceOf(Durable::class, $result);
		self::assertSame($max - (10 + 20 + intdiv($max * 5, 100)), $result->getDamage());
		self::assertFalse($result->hasEnchantments());

		self::assertNull(GrindstoneTransaction::calculateResult($a, VanillaItems::DIAMOND_PICKAXE()), "different items can't be combined");
		self::assertNull(GrindstoneTransaction::calculateResult(VanillaItems::STICK()->setCount(2), null));
	}

	public function testEnchantedBookBecomesBook() : void{
		$book = VanillaItems::ENCHANTED_BOOK();
		$book->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));
		$result = GrindstoneTransaction::calculateResult($book, null);
		self::assertNotNull($result);
		self::assertSame(ItemTypeIds::BOOK, $result->getTypeId());
		self::assertFalse($result->hasEnchantments());
	}
}
