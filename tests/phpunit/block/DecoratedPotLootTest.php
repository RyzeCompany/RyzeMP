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

namespace pocketmine\block;

use pocketmine\block\tile\Container;
use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\loot\LootData;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Filesystem;
use function array_filter;
use function array_map;
use function array_values;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_THROW_ON_ERROR;

/**
 * Decorated pots with a loot table (trial chamber corridor pots) in an in-memory world.
 */
final class DecoratedPotLootTest extends MobTestCase{

	private string $lootDir;

	protected function setUp() : void{
		parent::setUp();
		//vanilla data with a corridor pot table: one emerald or one diamond
		$dir = sys_get_temp_dir() . "/pm-pot-loot-test-" . uniqid();
		mkdir($dir . "/data/minecraft/loot_table/pots/trial_chambers", 0777, true);
		Filesystem::safeFilePutContents($dir . "/" . LootData::JAVA_ITEMS_FILE, json_encode(["items" => [
			"minecraft:emerald" => [64, 0, 0],
			"minecraft:diamond" => [64, 0, 0],
		]], JSON_THROW_ON_ERROR));
		Filesystem::safeFilePutContents($dir . "/data/minecraft/loot_table/pots/trial_chambers/corridor.json", json_encode(["type" => "minecraft:chest", "pools" => [
			["rolls" => 1, "entries" => [
				["type" => "minecraft:item", "name" => "minecraft:emerald"],
				["type" => "minecraft:item", "name" => "minecraft:diamond"],
			]],
		]], JSON_THROW_ON_ERROR));
		$this->lootDir = $dir;
		LootTables::setDataRoot($dir);
		//the fixture is a Java table; the bundled Bedrock pot table would replace it
		LootTables::setBedrockTablesEnabled(false);
	}

	protected function tearDown() : void{
		LootTables::setBedrockTablesEnabled(true);
		LootTables::setDataRoot(null);
		Filesystem::recursiveUnlink($this->lootDir);
		parent::tearDown();
	}

	/**
	 * A pot as trial chambers generate it (Bedrock tags).
	 */
	private function pot(int $x, int $seed, ?CompoundTag $item = null) : TileDecoratedPot{
		$this->set($x, 1, 0, VanillaBlocks::DECORATED_POT());
		$tile = $this->world->getTile(new Vector3($x, 1, 0));
		self::assertInstanceOf(TileDecoratedPot::class, $tile);
		$nbt = CompoundTag::create()
			->setString(Container::TAG_LOOT_TABLE, "loot_tables/pots/trial_chambers/corridor.json")
			->setInt(Container::TAG_LOOT_TABLE_SEED, $seed);
		if($item !== null){
			$nbt->setTag(TileDecoratedPot::TAG_ITEM, $item);
		}
		$tile->readSaveData($nbt);
		return $tile;
	}

	private static function isLoot(Item $item) : bool{
		return $item->equals(VanillaItems::EMERALD()) || $item->equals(VanillaItems::DIAMOND());
	}

	public function testLootDropsWhenThePotBreaks() : void{
		$this->pot(0, 1234);
		$this->breakBlock(0, 1, 0);
		$drops = array_values(array_map(fn(ItemEntity $e) => $e->getItem(), array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity && !$e->isClosed())));
		$loot = array_values(array_filter($drops, fn(Item $item) => self::isLoot($item)));
		self::assertCount(1, $loot, "the loot of the pot drops with the pot");
		self::assertSame(1, $loot[0]->getCount());
	}

	public function testLootIsRolledOnceWhenNeeded() : void{
		$first = $this->pot(0, 99);
		$saved = $first->saveNBT();
		self::assertSame("loot_tables/pots/trial_chambers/corridor.json", $saved->getString(Container::TAG_LOOT_TABLE), "not rolled until needed");
		self::assertSame(99, $saved->getInt(Container::TAG_LOOT_TABLE_SEED));

		$item = $first->getItem();
		self::assertNotNull($item);
		self::assertTrue(self::isLoot($item));
		self::assertNull($first->saveNBT()->getTag(Container::TAG_LOOT_TABLE), "rolled once");
		self::assertTrue($item->equalsExact($first->getItem() ?? VanillaItems::AIR()));
		//the same seed gives the same item
		for($i = 1; $i <= 4; ++$i){
			self::assertTrue($item->equalsExact($this->pot($i, 99)->getItem() ?? VanillaItems::AIR()));
		}
	}

	public function testPotHoldingAnItemGetsNoLoot() : void{
		$tile = $this->pot(0, 5, VanillaItems::STICK()->nbtSerialize());
		$item = $tile->getItem();
		self::assertNotNull($item);
		self::assertTrue($item->equals(VanillaItems::STICK()), "vanilla only fills empty slots");
	}

	public function testItemPutInRollsTheLootFirst() : void{
		$tile = $this->pot(0, 5);
		$tile->setItem(VanillaItems::STICK());
		self::assertNull($tile->saveNBT()->getTag(Container::TAG_LOOT_TABLE));
		self::assertTrue($tile->getItem()?->equals(VanillaItems::STICK()) ?? false);
	}
}
