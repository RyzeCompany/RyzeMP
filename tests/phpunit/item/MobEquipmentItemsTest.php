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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/**
 * Items without behaviour of their own (saddles, horse and nautilus armor, harnesses, chest boats...) and the eye of
 * ender: they must survive a round trip through their Bedrock IDs.
 */
class MobEquipmentItemsTest extends TestCase{

	/**
	 * @return \Generator<string, array{string, \Closure() : Item}>
	 */
	public static function itemProvider() : \Generator{
		yield "saddle" => [ItemTypeNames::SADDLE, fn() => VanillaItems::SADDLE()];
		yield "netherite horse armor" => [ItemTypeNames::NETHERITE_HORSE_ARMOR, fn() => VanillaItems::NETHERITE_HORSE_ARMOR()];
		yield "copper nautilus armor" => [ItemTypeNames::COPPER_NAUTILUS_ARMOR, fn() => VanillaItems::COPPER_NAUTILUS_ARMOR()];
		yield "wolf armor" => [ItemTypeNames::WOLF_ARMOR, fn() => VanillaItems::WOLF_ARMOR()];
		yield "carrot on a stick" => [ItemTypeNames::CARROT_ON_A_STICK, fn() => VanillaItems::CARROT_ON_A_STICK()];
		yield "eye of ender" => [ItemTypeNames::ENDER_EYE, fn() => VanillaItems::ENDER_EYE()];
		yield "lime harness" => [ItemTypeNames::LIME_HARNESS, fn() => VanillaItems::HARNESS()->setColor(DyeColor::LIME)];
		yield "cherry boat" => [ItemTypeNames::CHERRY_BOAT, fn() => VanillaItems::CHERRY_BOAT()];
		yield "pale oak chest boat" => [ItemTypeNames::PALE_OAK_CHEST_BOAT, fn() => VanillaItems::PALE_OAK_CHEST_BOAT()];
		yield "tnt minecart" => [ItemTypeNames::TNT_MINECART, fn() => VanillaItems::TNT_MINECART()];
		yield "lead" => [ItemTypeNames::LEAD, fn() => VanillaItems::LEAD()];
	}

	/**
	 * @dataProvider itemProvider
	 * @phpstan-param \Closure() : Item $item
	 */
	public function testRoundTrip(string $id, \Closure $item) : void{
		$expected = $item();
		self::assertSame($id, GlobalItemDataHandlers::getSerializer()->serializeType($expected)->getName());
		$back = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($id));
		self::assertTrue($back->equals($expected), $expected->getName());
	}

	public function testStackSizes() : void{
		self::assertSame(1, VanillaItems::SADDLE()->getMaxStackSize());
		self::assertSame(1, VanillaItems::DIAMOND_HORSE_ARMOR()->getMaxStackSize());
		self::assertSame(1, VanillaItems::HARNESS()->getMaxStackSize());
		self::assertSame(1, VanillaItems::WARPED_FUNGUS_ON_A_STICK()->getMaxStackSize());
		self::assertSame(101, VanillaItems::WARPED_FUNGUS_ON_A_STICK()->getMaxDurability()); //Bedrock 100 + 1, like every durable item
		self::assertSame(64, VanillaItems::ENDER_EYE()->getMaxStackSize());
		self::assertTrue(VanillaItems::NETHERITE_NAUTILUS_ARMOR()->isFireProof());
		self::assertFalse(VanillaItems::IRON_NAUTILUS_ARMOR()->isFireProof());
		self::assertSame("Red Harness", VanillaItems::HARNESS()->setColor(DyeColor::RED)->getVanillaName());
	}

	public function testHarnessColoursHaveDistinctIds() : void{
		$ids = [];
		foreach(DyeColor::cases() as $color){
			$ids[GlobalItemDataHandlers::getSerializer()->serializeType(VanillaItems::HARNESS()->setColor($color))->getName()] = true;
		}
		self::assertCount(16, $ids);
	}
}
