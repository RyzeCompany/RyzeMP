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
use pocketmine\loot\bedrock\BedrockLootConverter;
use pocketmine\loot\bedrock\BedrockLootTables;
use pocketmine\utils\Utils;
use function getenv;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

final class BedrockLootTablesTest extends TestCase{

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
	}

	/**
	 * @return mixed[]
	 */
	private static function table(string $id) : array{
		$table = BedrockLootTables::get($id);
		self::assertNotNull($table, $id);
		return $table;
	}

	/**
	 * The value at a path of keys of decoded JSON (asserting it exists).
	 */
	private static function at(mixed $json, int|string ...$path) : mixed{
		foreach($path as $key){
			self::assertIsArray($json);
			self::assertArrayHasKey($key, $json);
			$json = $json[$key];
		}
		return $json;
	}

	/**
	 * @param mixed[] $table
	 * @return mixed[]|null
	 */
	private static function findEntry(array $table, string $item, ?int $pool = null) : ?array{
		$pools = self::at($table, "pools");
		self::assertIsArray($pools);
		foreach(Utils::promoteKeys($pools) as $index => $p){
			if($pool !== null && $index !== $pool){
				continue;
			}
			$entries = self::at($p, "entries");
			self::assertIsArray($entries);
			foreach($entries as $entry){
				if(is_array($entry) && ($entry["name"] ?? null) === $item){
					return $entry;
				}
			}
		}
		return null;
	}

	/**
	 * A few of the Bedrock chest tables that differ from Java's (vanilla-audit AI2-02).
	 */
	public function testBundledTablesAreBedrocks() : void{
		self::assertNotNull(self::findEntry(self::table("minecraft:chests/stronghold_crossing"), "minecraft:ink_sac"), "ink sacs (Java has none)");
		self::assertNotNull(self::findEntry(self::table("minecraft:chests/stronghold_corridor"), "minecraft:emerald"), "emeralds (Java has none)");

		$bastion = self::table("minecraft:chests/bastion_other");
		self::assertSame(["type" => "minecraft:uniform", "min" => 3, "max" => 5], self::at($bastion, "pools", 2, "rolls"));
		self::assertSame(6, self::findEntry($bastion, "minecraft:iron_sword", 1)["weight"] ?? null);

		$tnt = self::findEntry(self::table("minecraft:chests/buried_treasure"), "minecraft:tnt");
		self::assertSame(["type" => "minecraft:uniform", "min" => 1, "max" => 5], self::at($tnt, "functions", 0, "count"));

		$dispenser = self::table("minecraft:chests/jungle_temple_dispenser");
		self::assertSame(2, self::at($dispenser, "pools", 0, "rolls"));

		//no spectral arrows in Bedrock's bastion treasure, no nautilus armor in its shipwreck map chest
		self::assertNull(self::findEntry(self::table("minecraft:chests/bastion_treasure"), "minecraft:spectral_arrow"));
		self::assertNull(self::findEntry(self::table("minecraft:chests/shipwreck_map"), "minecraft:iron_nautilus_armor"));

		//Bedrock-only tables of BDS worlds
		self::assertNotNull(BedrockLootTables::get("minecraft:chests/monster_room"));
		self::assertNotNull(BedrockLootTables::get("minecraft:chests/village_blacksmith"));
		self::assertNotNull(BedrockLootTables::get("minecraft:chests/spawn_bonus_chest"));
	}

	/**
	 * Every potion of the bundled tables is a PocketMine potion type.
	 */
	public function testPotionsExist() : void{
		$count = 0;
		foreach(BedrockLootTables::getIds() as $id){
			$this->forEachFunction(self::table($id), "minecraft:set_potion", function(array $function) use ($id, &$count) : void{
				self::assertIsString($function["id"]);
				self::assertNotNull(LootItemConverter::potionType($function["id"]), "$id: " . $function["id"]);
				++$count;
			});
		}
		self::assertGreaterThan(10, $count);
	}

	/**
	 * @param mixed[] $json
	 * @phpstan-param \Closure(mixed[]) : void $callback
	 */
	private function forEachFunction(array $json, string $function, \Closure $callback) : void{
		if(($json["function"] ?? null) === $function){
			$callback($json);
		}
		foreach($json as $value){
			if(is_array($value)){
				$this->forEachFunction($value, $function, $callback);
			}
		}
	}

	public function testConverter() : void{
		$resolved = [];
		$warnings = [];
		$converter = new BedrockLootConverter(
			function(string $name, int $data) use (&$resolved) : ?string{
				$resolved[] = "$name:$data";
				return match("$name:$data"){
					"minecraft:dye:4" => "minecraft:lapis_lazuli",
					"minecraft:book:0" => "minecraft:book",
					default => null
				};
			},
			function(string $message) use (&$warnings) : void{
				$warnings[] = $message;
			}
		);
		$json = json_decode(BedrockLootConverter::stripComments(<<<'JSON'
{
	"pools": [{
		"rolls": {"min": 2, "max": 4}, // a comment
		"conditions": [{"condition": "biome_has_tag", "operator": "!=", "tag": "jungle"}],
		"entries": [
			{"type": "item", "name": "minecraft:dye", "weight": 3, "functions": [{"function": "set_data", "data": 4}, {"function": "set_count", "count": 10.0}]},
			{"type": "item", "name": "minecraft:arrow", "functions": [{"function": "set_potion", "id": "nightvision"}]},
			{"type": "item", "name": "minecraft:book", "functions": [{"function": "enchant_with_levels", "levels": 30, "treasure": true}, {"function": "specific_enchants", "enchants": [{"id": "soul_speed", "level": [1, 3]}]}]},
			{"type": "item", "name": "minecraft:goat_horn", "functions": [{"function": "set_data", "data": {"min": 0, "max": 3}}]},
			{"type": "loot_table", "name": "loot_tables/chests/shipwrecksupply.json"},
			{"type": "empty", "weight": 5}
		]
	}]
}
JSON), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		$table = $converter->table($json);
		self::assertSame(["minecraft:dye:4", "minecraft:book:0", "minecraft:goat_horn:0"], $resolved);
		self::assertSame(["No Java item for minecraft:goat_horn:0"], $warnings);

		self::assertSame(["type" => "minecraft:uniform", "min" => 2, "max" => 4], self::at($table, "pools", 0, "rolls"));
		self::assertSame([["condition" => "pocketmine:biome_has_tag", "tag" => "jungle", "operator" => "!="]], self::at($table, "pools", 0, "conditions"));
		$entry = fn(int $i) : mixed => self::at($table, "pools", 0, "entries", $i);
		self::assertSame(["type" => "minecraft:item", "name" => "minecraft:lapis_lazuli", "weight" => 3, "functions" => [["function" => "minecraft:set_count", "count" => 10]]], $entry(0));
		self::assertSame("minecraft:tipped_arrow", self::at($entry(1), "name"));
		self::assertSame([["function" => "minecraft:set_potion", "id" => "minecraft:night_vision"]], self::at($entry(1), "functions"));
		self::assertSame([
			["function" => "minecraft:enchant_with_levels", "levels" => 30, "options" => "#minecraft:on_random_loot"],
			["function" => "minecraft:set_enchantments", "enchantments" => ["minecraft:soul_speed" => ["type" => "minecraft:uniform", "min" => 1, "max" => 3]]],
		], self::at($entry(2), "functions"));
		self::assertSame([["function" => "minecraft:set_instrument", "options" => "#minecraft:regular_goat_horns"]], self::at($entry(3), "functions"));
		self::assertSame(["type" => "minecraft:loot_table", "value" => "minecraft:chests/shipwreck_supply"], $entry(4));
		self::assertSame(["type" => "minecraft:empty", "weight" => 5], $entry(5));
	}

	/**
	 * The Bedrock tables roll through the Java loot engine (needs the vanilla data cache).
	 */
	public function testBedrockNamedTablesRoll() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if(!is_string($dir) || !is_dir($dir) || !LootData::isAvailable($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no loot tables");
		}
		LootTables::setDataRoot($dir);
		foreach(["loot_tables/chests/shipwrecksupply.json", "loot_tables/chests/buriedtreasure.json", "loot_tables/chests/dispenser_trap.json", "loot_tables/chests/monster_room.json", "loot_tables/chests/village_blacksmith.json"] as $name){
			$table = LootTables::get($name);
			self::assertNotNull($table, $name);
			self::assertNotEmpty($table->fill(27, [], LootContext::seeded(12345)), $name);
		}
		//ink sacs come out of stronghold crossings (19.4%)
		$table = LootTables::get("minecraft:chests/stronghold_crossing");
		self::assertNotNull($table);
		$inkSacs = 0;
		for($seed = 1; $seed <= 200; ++$seed){
			foreach($table->getRandomItems(LootContext::seeded($seed)) as $stack){
				if($stack->id === "minecraft:ink_sac"){
					++$inkSacs;
					break;
				}
			}
		}
		self::assertGreaterThan(10, $inkSacs);
	}

	/**
	 * pocketmine:biome_has_tag without a world: the tag is missing.
	 */
	public function testBiomeTagCondition() : void{
		$compiler = new LootCompiler(new LootData("."), fn(string $id) : ?LootTable => null, fn(string $key, string $message) => null);
		$context = LootContext::seeded(1);
		self::assertFalse(($compiler->condition(["condition" => "pocketmine:biome_has_tag", "tag" => "jungle"]))($context));
		self::assertTrue(($compiler->condition(["condition" => "pocketmine:biome_has_tag", "tag" => "jungle", "operator" => "!="]))($context));
	}
}
