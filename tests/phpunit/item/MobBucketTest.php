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

use pocketmine\block\Dispenser;
use pocketmine\block\tile\Container;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\math\Facing;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\redstone\RedstoneTestCase;

class MobBucketTest extends RedstoneTestCase{

	/**
	 * @return \Generator<string, array{string, \Closure() : Item}>
	 */
	public static function bucketProvider() : \Generator{
		yield "cod" => [ItemTypeNames::COD_BUCKET, fn() => VanillaItems::COD_BUCKET()];
		yield "salmon" => [ItemTypeNames::SALMON_BUCKET, fn() => VanillaItems::SALMON_BUCKET()];
		yield "tropical fish" => [ItemTypeNames::TROPICAL_FISH_BUCKET, fn() => VanillaItems::TROPICAL_FISH_BUCKET()];
		yield "pufferfish" => [ItemTypeNames::PUFFERFISH_BUCKET, fn() => VanillaItems::PUFFERFISH_BUCKET()];
		yield "axolotl" => [ItemTypeNames::AXOLOTL_BUCKET, fn() => VanillaItems::AXOLOTL_BUCKET()];
		yield "tadpole" => [ItemTypeNames::TADPOLE_BUCKET, fn() => VanillaItems::TADPOLE_BUCKET()];
		yield "sulfur cube" => [ItemTypeNames::SULFUR_CUBE_BUCKET, fn() => VanillaItems::SULFUR_CUBE_BUCKET()];
	}

	/**
	 * @dataProvider bucketProvider
	 * @phpstan-param \Closure() : Item $item
	 */
	public function testRoundTrip(string $id, \Closure $item) : void{
		$expected = $item();
		self::assertSame(1, $expected->getMaxStackSize());
		self::assertSame($id, GlobalItemDataHandlers::getSerializer()->serializeType($expected)->getName());
		self::assertTrue(GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($id))->equals($expected));
	}

	public function testAquaticBucketsHoldWater() : void{
		$bucket = VanillaItems::AXOLOTL_BUCKET();
		self::assertInstanceOf(Water::class, $bucket->getLiquid());
		self::assertSame(0, $bucket->getFuelTime());
	}

	public function testDispenserEmptiesFishBucket() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::EAST));
		$this->runTicks(1);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(Container::class, $tile);
		$tile->getInventory()->addItem(VanillaItems::COD_BUCKET());

		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);

		self::assertInstanceOf(Water::class, $this->get(1, 1, 0));
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));
	}
}
