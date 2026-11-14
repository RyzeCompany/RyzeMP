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

namespace pocketmine\loot;

use pocketmine\block\Block;
use pocketmine\block\tile\Barrel as TileBarrel;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Hopper as TileHopper;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\utils\Filesystem;
use pocketmine\world\redstone\RedstoneTestCase;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_THROW_ON_ERROR;

/**
 * Lazy container loot: containers generated with a loot table are filled when first used (opened, emptied by a
 * hopper, broken), always with the same items for the same seed, and keep the table and seed until then.
 */
final class LootContainerTest extends RedstoneTestCase{
	private const TABLE = "minecraft:chests/test_chest";

	private string $dataDir;

	protected function setUp() : void{
		parent::setUp();
		$dir = sys_get_temp_dir() . "/pm-loot-container-test-" . uniqid();
		mkdir($dir . "/data/minecraft/loot_table/chests", 0777, true);
		Filesystem::safeFilePutContents($dir . "/" . LootData::JAVA_ITEMS_FILE, json_encode(["items" => [
			"minecraft:ender_pearl" => [16, 0, 0],
			"minecraft:bone" => [64, 0, 0],
			"minecraft:iron_ingot" => [64, 0, 0],
		]], JSON_THROW_ON_ERROR));
		Filesystem::safeFilePutContents($dir . "/data/minecraft/loot_table/chests/test_chest.json", json_encode(["type" => "minecraft:chest", "pools" => [
			["rolls" => ["type" => "minecraft:uniform", "min" => 3, "max" => 6], "entries" => [
				["type" => "minecraft:item", "name" => "minecraft:ender_pearl", "functions" => [["function" => "minecraft:set_count", "count" => ["type" => "minecraft:uniform", "min" => 1, "max" => 4]]]],
				["type" => "minecraft:item", "name" => "minecraft:bone", "functions" => [["function" => "minecraft:set_count", "count" => ["type" => "minecraft:uniform", "min" => 1, "max" => 8]]]],
				["type" => "minecraft:item", "name" => "minecraft:iron_ingot"],
			]],
		]], JSON_THROW_ON_ERROR));
		$this->dataDir = $dir;
		LootTables::setDataRoot($dir);
	}

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
		Filesystem::recursiveUnlink($this->dataDir);
		parent::tearDown();
	}

	private function place(int $x, int $y, int $z, Block $block, int $seed) : TileChest|TileBarrel{
		$this->set($x, $y, $z, $block);
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertTrue($tile instanceof TileChest || $tile instanceof TileBarrel);
		$tile->setLootTable(self::TABLE, $seed);
		return $tile;
	}

	/**
	 * @return int[]
	 * @phpstan-return array<string, int>
	 */
	private static function totals(Inventory $inventory) : array{
		$totals = [];
		foreach($inventory->getContents() as $item){
			$totals[$item->getVanillaName()] = ($totals[$item->getVanillaName()] ?? 0) + $item->getCount();
		}
		return $totals;
	}

	public function testSavedUntilOpenedAndDeterministic() : void{
		$chest = $this->place(0, 1, 0, VanillaBlocks::CHEST(), -5_123_456_789_012_345);
		self::assertInstanceOf(TileChest::class, $chest);

		$nbt = $chest->saveNBT();
		self::assertSame(self::TABLE, $nbt->getString(Container::TAG_LOOT_TABLE));
		self::assertSame(-5_123_456_789_012_345, $nbt->getLong(Container::TAG_LOOT_TABLE_SEED_LONG));
		self::assertSame((-5_123_456_789_012_345 << 32) >> 32, $nbt->getInt(Container::TAG_LOOT_TABLE_SEED));

		//a reloaded chest keeps the table and the full seed, and is still empty
		$reloaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileChest::class, $reloaded);
		self::assertSame(self::TABLE, $reloaded->getLootTable());
		self::assertSame(-5_123_456_789_012_345, $reloaded->getLootTableSeed());
		self::assertCount(0, $reloaded->getRealInventory()->getContents());

		//Bedrock worlds only have the 32-bit seed
		$nbt->removeTag(Container::TAG_LOOT_TABLE_SEED_LONG);
		$bedrock = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileChest::class, $bedrock);
		self::assertSame((-5_123_456_789_012_345 << 32) >> 32, $bedrock->getLootTableSeed());

		//same seed, same contents in the same slots
		$contents = $reloaded->getInventory()->getContents();
		self::assertNotEmpty($contents);
		$other = $this->place(4, 1, 0, VanillaBlocks::CHEST(), -5_123_456_789_012_345);
		self::assertEquals($contents, $other->getInventory()->getContents());
		self::assertEquals($contents, $chest->getInventory()->getContents());

		//filled only once: the table is gone from the saved data, the items are saved instead
		self::assertNull($chest->getLootTable());
		$chest->getInventory()->clearAll();
		self::assertCount(0, $chest->getInventory()->getContents());
		$nbt = $chest->saveNBT();
		self::assertNull($nbt->getTag(Container::TAG_LOOT_TABLE));
		self::assertNull($nbt->getTag(Container::TAG_LOOT_TABLE_SEED));
	}

	public function testBarrel() : void{
		$barrel = $this->place(0, 1, 0, VanillaBlocks::BARREL(), 42);
		self::assertInstanceOf(TileBarrel::class, $barrel);
		$reloaded = TileFactory::getInstance()->createFromData($this->world, $barrel->saveNBT());
		self::assertInstanceOf(TileBarrel::class, $reloaded);
		self::assertSame(42, $reloaded->getLootTableSeed());
		self::assertNotEmpty($barrel->getInventory()->getContents());
		self::assertEquals($barrel->getInventory()->getContents(), $reloaded->getInventory()->getContents());
	}

	public function testBreakingDropsTheLoot() : void{
		$this->place(4, 1, 4, VanillaBlocks::CHEST(), 7);
		$expected = $this->place(8, 1, 8, VanillaBlocks::CHEST(), 7);
		$totals = self::totals($expected->getInventory());
		self::assertNotEmpty($totals);

		$this->breakBlock(4, 1, 4);
		$dropped = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				$item = $entity->getItem();
				if(!$item->equals(VanillaBlocks::CHEST()->asItem(), false, false)){
					$dropped[$item->getVanillaName()] = ($dropped[$item->getVanillaName()] ?? 0) + $item->getCount();
				}
			}
		}
		self::assertEquals($totals, $dropped);
	}

	public function testHopperUnpacks() : void{
		$chest = $this->place(0, 2, 0, VanillaBlocks::CHEST(), 99);
		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		self::assertInstanceOf(TileChest::class, $chest);
		self::assertSame(self::TABLE, $chest->getLootTable());
		$this->runTicks(40);
		$hopper = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileHopper::class, $hopper);
		self::assertNull($chest->getLootTable());
		$pulled = 0;
		foreach($hopper->getInventory()->getContents() as $item){
			$pulled += $item->getCount();
		}
		self::assertGreaterThan(0, $pulled);
	}

	public function testOpenedByPlayerInteraction() : void{
		$chest = $this->place(0, 1, 0, VanillaBlocks::CHEST(), 1234);
		//no player: the block does not open a window, the loot stays packed
		$this->interact(0, 1, 0);
		self::assertSame(self::TABLE, $chest->getLootTable());
		self::assertCount(0, $chest->getRealInventory()->getContents());
		self::assertContainsOnlyInstancesOf(Item::class, $chest->getInventory()->getContents());
		self::assertNull($chest->getLootTable());
	}
}
