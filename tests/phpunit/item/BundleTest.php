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
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;

class BundleTest extends TestCase{

	public function testItemWeights() : void{
		self::assertSame(1, Bundle::getItemWeight(VanillaItems::DIAMOND()));
		self::assertSame(4, Bundle::getItemWeight(VanillaItems::ENDER_PEARL()));
		self::assertSame(64, Bundle::getItemWeight(VanillaItems::DIAMOND_SWORD()));
		self::assertSame(Bundle::BUNDLE_WEIGHT, Bundle::getItemWeight(VanillaItems::BUNDLE()));

		$inner = VanillaItems::BUNDLE();
		$inner->addItem(VanillaItems::APPLE()->setCount(10));
		self::assertSame(Bundle::BUNDLE_WEIGHT + 10, Bundle::getItemWeight($inner));
	}

	public function testCapacity() : void{
		$bundle = VanillaItems::BUNDLE();
		self::assertSame(40, $bundle->addItem(VanillaItems::DIAMOND()->setCount(40)));
		//24 weight left: 6 ender pearls (4 each)
		self::assertSame(6, $bundle->addItem(VanillaItems::ENDER_PEARL()->setCount(16)));
		self::assertSame(64, $bundle->getContentsWeight());
		self::assertSame(1.0, $bundle->getFullness());
		self::assertSame(0, $bundle->addItem(VanillaItems::DIAMOND()));

		$swords = VanillaItems::BUNDLE();
		self::assertSame(1, $swords->addItem(VanillaItems::DIAMOND_SWORD()));
		self::assertSame(0, $swords->addItem(VanillaItems::DIAMOND_SWORD()));
	}

	public function testShulkerBoxesAreRejected() : void{
		$bundle = VanillaItems::BUNDLE();
		self::assertSame(0, $bundle->addItem(VanillaBlocks::SHULKER_BOX()->asItem()));
		self::assertSame(0, $bundle->addItem(VanillaBlocks::DYED_SHULKER_BOX()->setColor(DyeColor::RED)->asItem()));
		self::assertTrue($bundle->isEmpty());
	}

	public function testMergeMovesStackToTop() : void{
		$bundle = VanillaItems::BUNDLE();
		$bundle->addItem(VanillaItems::DIAMOND()->setCount(5));
		$bundle->addItem(VanillaItems::APPLE()->setCount(3));
		$bundle->addItem(VanillaItems::DIAMOND()->setCount(2));

		$contents = $bundle->getContents();
		self::assertCount(2, $contents);
		self::assertTrue($contents[0]->equalsExact(VanillaItems::APPLE()->setCount(3)));
		self::assertTrue($contents[1]->equalsExact(VanillaItems::DIAMOND()->setCount(7)));

		$newest = $bundle->removeNewest();
		self::assertNotNull($newest);
		self::assertTrue($newest->equalsExact(VanillaItems::DIAMOND()->setCount(7)));
		self::assertCount(1, $bundle->getContents());
	}

	public function testNbtRoundTrip() : void{
		$bundle = VanillaItems::DYED_BUNDLE()->setColor(DyeColor::LIME);
		$inner = VanillaItems::BUNDLE();
		$inner->addItem(VanillaItems::STICK()->setCount(8));
		$bundle->addItem(VanillaItems::GOLD_INGOT()->setCount(12));
		$bundle->addItem($inner);

		$tag = $bundle->getNamedTag();
		$contentsTag = $tag->getListTag(Bundle::TAG_CONTENTS);
		self::assertNotNull($contentsTag);
		self::assertCount(2, $contentsTag);

		$restored = Item::nbtDeserialize($bundle->nbtSerialize());
		self::assertInstanceOf(DyedBundle::class, $restored);
		self::assertSame(DyeColor::LIME, $restored->getColor());
		self::assertTrue($restored->equalsExact($bundle));
		$restoredContents = $restored->getContents();
		self::assertCount(2, $restoredContents);
		self::assertInstanceOf(Bundle::class, $restoredContents[1]);
		self::assertSame(8, $restoredContents[1]->getContentsWeight());
	}

	public function testNetworkBundleIdIsNotKept() : void{
		$tag = CompoundTag::create()->setInt(Bundle::TAG_BUNDLE_ID, 42);
		$bundle = VanillaItems::BUNDLE()->setNamedTag($tag);
		self::assertFalse($bundle->hasNamedTag());
	}

	public function testEmptyBundleHasNoContentsTag() : void{
		self::assertNull(VanillaItems::BUNDLE()->getNamedTag()->getTag(Bundle::TAG_CONTENTS));
	}
}
