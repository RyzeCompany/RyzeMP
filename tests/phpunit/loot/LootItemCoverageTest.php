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
use pocketmine\loot\bedrock\BedrockLootTables;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_diff;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function ksort;
use function ltrim;
use function sort;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Every item of every vanilla loot table must become a PocketMine item, except the documented ones below. Needs the
 * vanilla data cache (POCKETMINE_VANILLA_WORLDGEN_DATA, data format 3 or later).
 */
class LootItemCoverageTest extends TestCase{
	/**
	 * Items of vanilla loot tables PocketMine does not have (the stacks are dropped).
	 */
	private const UNSUPPORTED_ITEMS = [
		"minecraft:white_cushion", //cushions (Java 26.3) are not in PocketMine yet: abandoned camp barrels
	];

	/**
	 * Items that PocketMine serializes to another Bedrock id than the mappings' one (block items of blocks whose item
	 * PocketMine does not register separately).
	 */
	private const SERIALIZED_AS = [
		"minecraft:straw_bed" => "minecraft:item.straw_bed",
	];

	/**
	 * Unsupported loot table features (they are ignored: see LootCompiler::NO_OP_FUNCTIONS for the silent ones).
	 */
	private const ALLOWED_WARNINGS = [];

	private string $root;

	protected function setUp() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir) || !LootData::isAvailable($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no loot tables");
		}
		$this->root = $dir;
		LootTables::setDataRoot($dir);
	}

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
	}

	/**
	 * @return mixed[][]
	 * @phpstan-return array<string, mixed[]>
	 */
	private function tables() : array{
		$base = str_replace("\\", "/", $this->root) . "/data/minecraft/loot_table/";
		$tables = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach($files as $file){
			if(!$file instanceof \SplFileInfo){
				continue;
			}
			$path = str_replace("\\", "/", $file->getPathname());
			if(str_ends_with($path, ".json")){
				$json = json_decode(Filesystem::fileGetContents($path), true, 512, JSON_THROW_ON_ERROR);
				if(is_array($json)){
					$tables["minecraft:" . substr($path, strlen($base), -5)] = $json;
				}
			}
		}
		ksort($tables);
		self::assertNotEmpty($tables);
		return $tables;
	}

	/**
	 * Item ids a loot table JSON can produce: item entries, tag entries and set_item functions.
	 * @param string[] $items
	 * @phpstan-param array<string, string> $items
	 */
	private static function collectItems(LootData $data, mixed $json, string $table, array &$items) : void{
		if(!is_array($json)){
			return;
		}
		$type = is_string($json["type"] ?? null) ? LootData::stripNamespace($json["type"]) : (is_string($json["function"] ?? null) ? LootData::stripNamespace($json["function"]) : "");
		if($type === "item" && is_string($json["name"] ?? null)){
			$items[LootData::namespaced($json["name"])] ??= $table;
		}elseif($type === "tag" && is_string($json["name"] ?? null)){
			foreach($data->tag("item", ltrim($json["name"], "#")) as $item){
				$items[$item] ??= $table;
			}
		}elseif($type === "set_item" && is_string($json["item"] ?? null)){
			$items[LootData::namespaced($json["item"])] ??= $table;
		}
		foreach($json as $value){
			self::collectItems($data, $value, $table, $items);
		}
	}

	public function testAllItemsResolve() : void{
		$data = LootTables::getData();
		self::assertNotNull($data);
		$items = [];
		foreach(Utils::stringifyKeys($this->tables()) as $id => $json){
			self::collectItems($data, $json, $id, $items);
		}
		foreach(BedrockLootTables::getIds() as $id){
			self::collectItems($data, BedrockLootTables::get($id), "$id (Bedrock)", $items);
		}
		$items["minecraft:enchanted_book"] ??= "set_enchantments";
		self::assertGreaterThan(1000, count($items));

		$missing = [];
		$wrong = [];
		foreach(Utils::stringifyKeys($items) as $item => $table){
			$converted = LootItemConverter::toItem(new LootStack($item, 1));
			if($converted === null){
				$missing[] = "$item ($table)";
				continue;
			}
			//the PocketMine item must be the Bedrock item of the mappings, not just any item
			[$bedrockId] = LootItemConverter::bedrockItem($item);
			$serialized = GlobalItemDataHandlers::getSerializer()->serializeType($converted)->getName();
			if($serialized !== (self::SERIALIZED_AS[$item] ?? $bedrockId)){
				$wrong[] = "$item: expected $bedrockId, got $serialized";
			}
		}
		$expectedMissing = [];
		foreach(self::UNSUPPORTED_ITEMS as $item){
			if(isset($items[$item])){
				$expectedMissing[] = "$item ($items[$item])";
			}
		}
		sort($missing);
		self::assertSame($expectedMissing, $missing, "Loot table items without a PocketMine item (add them to UNSUPPORTED_ITEMS if PocketMine really lacks them)");
		self::assertSame([], $wrong);
	}

	public function testAllTablesRoll() : void{
		$tool = new LootStack("minecraft:diamond_pickaxe", 1);
		$tool->enchantments["minecraft:fortune"] = 3;
		$rolled = 0;
		foreach(array_unique([...array_keys($this->tables()), ...BedrockLootTables::getIds()]) as $id){
			$table = LootTables::get($id);
			self::assertNotNull($table, "Loot table $id does not compile");
			for($seed = 0; $seed < 8; ++$seed){
				$context = LootContext::seeded($seed * 7919 + strlen($id));
				$context->luck = $seed % 2;
				if($seed % 4 >= 2){
					$context->tool = $tool;
					$context->explosionRadius = 4.0;
				}
				foreach($table->getRandomItems($context) as $stack){
					if(!$stack->isEmpty() && !in_array($stack->id, self::UNSUPPORTED_ITEMS, true)){
						self::assertNotNull(LootItemConverter::toItem($stack), "$id produced " . $stack->describe());
					}
					++$rolled;
				}
				$table->fill(27, [], LootContext::seeded($seed));
			}
		}
		self::assertGreaterThan(0, $rolled);
		$warnings = array_values(array_diff(LootTables::getWarnings(), self::ALLOWED_WARNINGS));
		self::assertSame([], $warnings, "Unsupported loot table features: " . implode(", ", $warnings));
	}
}
