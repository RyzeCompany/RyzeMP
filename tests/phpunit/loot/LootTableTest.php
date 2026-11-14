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

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Armor;
use pocketmine\item\PotionType;
use pocketmine\item\TippedArrow;
use pocketmine\item\VanillaArmorTrimMaterials;
use pocketmine\item\VanillaArmorTrimPatterns;
use pocketmine\utils\Filesystem;
use function count;
use function explode;
use function getenv;
use function implode;
use function is_dir;
use function json_encode;
use function ksort;
use function mkdir;
use function preg_match;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_THROW_ON_ERROR;

class LootTableTest extends TestCase{
	private ?string $tempDir = null;

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
		LootTables::setBedrockTablesEnabled(true);
		if($this->tempDir !== null){
			Filesystem::recursiveUnlink($this->tempDir);
			$this->tempDir = null;
		}
	}

	/**
	 * @param mixed[] $table
	 */
	private function makeData(array $table) : string{
		$dir = sys_get_temp_dir() . "/pm-loot-test-" . uniqid();
		mkdir($dir . "/data/minecraft/loot_table/test", 0777, true);
		Filesystem::safeFilePutContents($dir . "/" . LootData::JAVA_ITEMS_FILE, json_encode(["items" => [
			"minecraft:bone" => [64, 0, 0],
			"minecraft:ender_pearl" => [16, 0, 0],
			"minecraft:iron_sword" => [1, 250, 14],
		]], JSON_THROW_ON_ERROR));
		Filesystem::safeFilePutContents($dir . "/data/minecraft/loot_table/test/table.json", json_encode($table, JSON_THROW_ON_ERROR));
		$this->tempDir = $dir;
		return $dir;
	}

	public function testBlockItemConversion() : void{
		//block items have no item deserializer of their own; GeyserMC's meta of a block item is the legacy one
		$terracotta = LootItemConverter::bedrockToItem("minecraft:black_terracotta", 15);
		self::assertNotNull($terracotta);
		self::assertTrue($terracotta->equals(VanillaBlocks::STAINED_CLAY()->setColor(DyeColor::BLACK)->asItem()));
		$copper = LootItemConverter::bedrockToItem("minecraft:waxed_weathered_cut_copper", 0);
		self::assertNotNull($copper);
		self::assertTrue($copper->equals(VanillaBlocks::CUT_COPPER()->setOxidation(CopperOxidation::WEATHERED)->setWaxed(true)->asItem()));
		self::assertNotNull(LootItemConverter::bedrockToItem("minecraft:iron_chain", 0));
		self::assertNull(LootItemConverter::bedrockToItem("minecraft:not_an_item", 0));

		$stack = new LootStack("minecraft:iron_chestplate", 1);
		$stack->trim = ["minecraft:copper", "minecraft:bolt"];
		$chestplate = LootItemConverter::toItem($stack);
		self::assertInstanceOf(Armor::class, $chestplate);
		$trim = $chestplate->getTrim();
		self::assertNotNull($trim);
		self::assertSame(VanillaArmorTrimMaterials::COPPER(), $trim->getMaterial());
		self::assertSame(VanillaArmorTrimPatterns::BOLT(), $trim->getPattern());

		$stack = new LootStack("minecraft:tipped_arrow", 4);
		$stack->potion = "minecraft:poison";
		$arrow = LootItemConverter::toItem($stack);
		self::assertInstanceOf(TippedArrow::class, $arrow);
		self::assertSame(PotionType::POISON, $arrow->getType());
		self::assertSame(4, $arrow->getCount());
	}

	public function testNames() : void{
		self::assertSame("minecraft:chests/simple_dungeon", LootTables::toJavaId("loot_tables/chests/simple_dungeon.json"));
		self::assertSame("minecraft:chests/simple_dungeon", LootTables::toJavaId("chests/simple_dungeon"));
		self::assertSame("loot_tables/chests/simple_dungeon.json", LootTables::toBedrockPath("minecraft:chests/simple_dungeon"));
		self::assertSame("loot_tables/entities/desert_well_brushable_block.json", LootTables::toBedrockPath("minecraft:archaeology/desert_well"));
		self::assertSame("minecraft:archaeology/desert_well", LootTables::toJavaId("loot_tables/entities/desert_well_brushable_block.json"));
		//tables of BDS worlds named differently from Java's, both ways
		foreach([
			"minecraft:chests/buried_treasure" => "loot_tables/chests/buriedtreasure.json",
			"minecraft:chests/shipwreck_map" => "loot_tables/chests/shipwreck.json",
			"minecraft:chests/shipwreck_supply" => "loot_tables/chests/shipwrecksupply.json",
			"minecraft:chests/shipwreck_treasure" => "loot_tables/chests/shipwrecktreasure.json",
			"minecraft:chests/jungle_temple_dispenser" => "loot_tables/chests/dispenser_trap.json",
		] as $java => $bedrock){
			self::assertSame($java, LootTables::toJavaId($bedrock));
			self::assertSame($bedrock, LootTables::toBedrockPath($java));
		}
		//Bedrock-only tables keep their name
		self::assertSame("minecraft:chests/monster_room", LootTables::toJavaId("loot_tables/chests/monster_room.json"));
		self::assertSame("loot_tables/chests/village_blacksmith.json", LootTables::toBedrockPath(LootTables::toJavaId("loot_tables/chests/village_blacksmith.json")));
	}

	public function testRollsAndFill() : void{
		LootTables::setDataRoot($this->makeData(["type" => "minecraft:chest", "pools" => [
			["rolls" => 1, "entries" => [["type" => "minecraft:item", "name" => "minecraft:ender_pearl", "modifier" => ["type" => "minecraft:set_count", "count" => 40]]]],
			["rolls" => ["type" => "minecraft:uniform", "min" => 2, "max" => 2], "entries" => [
				["type" => "minecraft:item", "name" => "minecraft:iron_sword", "modifier" => ["type" => "minecraft:set_damage", "damage" => 0.5]],
				["type" => "minecraft:empty", "weight" => 0]
			]],
			["rolls" => 1, "condition" => ["type" => "minecraft:killed_by_player"], "entries" => [["type" => "minecraft:item", "name" => "minecraft:bone"]]],
		]]));
		$table = LootTables::get("minecraft:test/table");
		self::assertNotNull($table);

		//oversized stacks are split at the max stack size; conditions without their parameter fail
		$stacks = $table->getRandomItems(LootContext::seeded(42));
		$described = [];
		foreach($stacks as $stack){
			$described[] = $stack->describe();
		}
		self::assertSame([
			"minecraft:ender_pearl x16", "minecraft:ender_pearl x16", "minecraft:ender_pearl x8",
			"minecraft:iron_sword x1 {damage=125}", "minecraft:iron_sword x1 {damage=125}"
		], $described);

		//fill: same seed, same slots; all 40 pearls and both swords end up in distinct slots
		$first = $table->fill(27, [], LootContext::seeded(12345));
		$second = $table->fill(27, [], LootContext::seeded(12345));
		self::assertEquals($first, $second);
		$pearls = 0;
		$swords = 0;
		foreach($first as $slot => $stack){
			self::assertTrue($slot >= 0 && $slot < 27);
			if($stack->id === "minecraft:ender_pearl"){
				$pearls += $stack->count;
			}else{
				$swords += $stack->count;
			}
		}
		self::assertSame(40, $pearls);
		self::assertSame(2, $swords);
		//occupied slots are left alone
		foreach($table->fill(3, [0 => true, 1 => true], LootContext::seeded(7)) as $slot => $_){
			self::assertSame(2, $slot);
		}
	}

	/**
	 * @return string[][]
	 * @phpstan-return list<array{string, int, string}>
	 */
	public static function javaChestProvider() : array{
		//contents of chests filled by a Java Edition 26.3 server (setblock chest{LootTable, LootTableSeed} + unpack)
		return [
			["minecraft:chests/simple_dungeon", -9126482810251688793, "1:minecraft:leather x1 | 3:minecraft:gunpowder x1 | 4:minecraft:gunpowder x1 | 5:minecraft:gunpowder x1 | 7:minecraft:string x1 | 10:minecraft:redstone x1 | 12:minecraft:rotten_flesh x1 | 13:minecraft:string x3 | 16:minecraft:redstone x1 | 17:minecraft:leather x2 | 18:minecraft:string x1 | 19:minecraft:gunpowder x1 | 20:minecraft:rotten_flesh x2 | 21:minecraft:gunpowder x1 | 24:minecraft:string x3 | 25:minecraft:redstone x1 | 26:minecraft:gunpowder x3"],
			["minecraft:chests/ancient_city", 8413006387136952186, "0:minecraft:amethyst_shard x2 | 1:minecraft:amethyst_shard x2 | 4:minecraft:book x2 | 9:minecraft:amethyst_shard x2 | 10:minecraft:diamond_leggings x1 {minecraft:mending=1,minecraft:unbreaking=3} | 12:minecraft:amethyst_shard x6 | 14:minecraft:amethyst_shard x1 | 15:minecraft:book x1 | 16:minecraft:diamond_horse_armor x1 | 18:minecraft:sculk_catalyst x1 | 20:minecraft:book x1 | 21:minecraft:sculk_catalyst x1 | 25:minecraft:book x1"],
			["minecraft:chests/end_city_treasure", -1836962710417774274, "13:minecraft:iron_shovel x1 {minecraft:efficiency=5,minecraft:vanishing_curse=1} | 23:minecraft:diamond x2 | 25:minecraft:diamond x1"],
			["minecraft:chests/shipwreck_supply", 7160471280684129607, "0:minecraft:suspicious_stew x1 {minecraft:weakness:160} | 2:minecraft:suspicious_stew x1 {minecraft:weakness:140} | 5:minecraft:rotten_flesh x4 | 6:minecraft:rotten_flesh x3 | 9:minecraft:carrot x3 | 10:minecraft:leather_boots x1 {minecraft:blast_protection=2} | 12:minecraft:suspicious_stew x1 {minecraft:poison:380} | 13:minecraft:carrot x5 | 14:minecraft:rotten_flesh x1 | 17:minecraft:leather_leggings x1 {minecraft:fire_protection=1} | 18:minecraft:rotten_flesh x3 | 20:minecraft:carrot x2 | 21:minecraft:carrot x2"],
		];
	}

	/**
	 * Needs the vanilla data cache (POCKETMINE_VANILLA_WORLDGEN_DATA, data format 3 or later).
	 * @dataProvider javaChestProvider
	 */
	public function testMatchesJava(string $tableId, int $seed, string $expected) : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir) || !LootData::isAvailable($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no loot tables");
		}
		LootTables::setDataRoot($dir);
		//the Java tables themselves, not the Bedrock ones that replace them
		LootTables::setBedrockTablesEnabled(false);
		$table = LootTables::get($tableId);
		self::assertNotNull($table);
		$slots = $table->fill(27, [], LootContext::seeded($seed));
		ksort($slots);
		$actual = [];
		foreach($slots as $slot => $stack){
			$actual[] = $slot . ":" . self::sortedComponents($stack->describe());
		}
		$expectedSlots = [];
		foreach(explode(" | ", $expected, limit: 64) as $part){
			$expectedSlots[] = self::sortedComponents($part);
		}
		self::assertSame($expectedSlots, $actual);
		self::assertGreaterThan(0, count($actual));
	}

	/**
	 * Java does not keep enchantments in a stable order: compare them sorted.
	 */
	private static function sortedComponents(string $description) : string{
		if(preg_match('/^(.*) \{(.*)\}$/', $description, $matches) !== 1){
			return $description;
		}
		$parts = explode(",", $matches[2], limit: 64);
		sort($parts);
		return $matches[1] . " {" . implode(",", $parts) . "}";
	}
}
