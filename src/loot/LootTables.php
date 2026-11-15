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

use pocketmine\loot\bedrock\BedrockLootTables;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function getcwd;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Vanilla loot tables. Container tables (chests, dispensers, barrels, pots) are Bedrock's, bundled with PocketMine
 * (see {@link BedrockLootTables}); the others are Java Edition's, from the vanilla data cache (see {@link LootData}).
 * Both need the cache (item data and the Java -> Bedrock item mappings).
 *
 *   $table = LootTables::get("minecraft:chests/simple_dungeon"); //null if the data or the table is missing
 *   $items = $table?->generate(LootContext::create()) ?? [];
 *
 * The data cache is the one of the vanilla world generator (by default <working directory>/vanilla-worldgen, filled
 * automatically when a vanilla world is created); worlds using another generator use it too. Without a vanilla world,
 * the server downloads the loot part of it in the background at startup (see {@link LootDataManager}).
 */
final class LootTables{
	/**
	 * Bedrock loot table paths whose name differs from Java's. Other tables follow "loot_tables/<path>.json" <->
	 * "minecraft:<path>" (Bedrock-only tables, such as chests/monster_room or chests/village_blacksmith, too).
	 */
	private const BEDROCK_NAMES = [
		"minecraft:chests/buried_treasure" => "loot_tables/chests/buriedtreasure.json",
		"minecraft:chests/shipwreck_map" => "loot_tables/chests/shipwreck.json",
		"minecraft:chests/shipwreck_supply" => "loot_tables/chests/shipwrecksupply.json",
		"minecraft:chests/shipwreck_treasure" => "loot_tables/chests/shipwrecktreasure.json",
		"minecraft:chests/jungle_temple_dispenser" => "loot_tables/chests/dispenser_trap.json",
		"minecraft:archaeology/desert_pyramid" => "loot_tables/entities/desert_pyramid_brushable_block.json",
		"minecraft:archaeology/desert_well" => "loot_tables/entities/desert_well_brushable_block.json",
		"minecraft:archaeology/ocean_ruin_cold" => "loot_tables/entities/cold_ocean_ruins_brushable_block.json",
		"minecraft:archaeology/ocean_ruin_warm" => "loot_tables/entities/warm_ocean_ruins_brushable_block.json",
		"minecraft:archaeology/trail_ruins_common" => "loot_tables/entities/trail_ruins_brushable_block_common.json",
		"minecraft:archaeology/trail_ruins_rare" => "loot_tables/entities/trail_ruins_brushable_block_rare.json",
	];

	private static ?string $root = null;
	private static ?LootData $data = null;
	private static ?LootCompiler $compiler = null;
	private static bool $bedrockTables = true;
	/**
	 * @var LootTable[]|null[]
	 * @phpstan-var array<string, LootTable|null>
	 */
	private static array $tables = [];
	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private static array $warned = [];
	private static bool $unavailableWarned = false;

	private function __construct(){
		//NOOP
	}

	/**
	 * Sets the vanilla data cache to read the loot tables from (null: the default one). The vanilla world generator
	 * calls this with its data directory.
	 */
	public static function setDataRoot(?string $root) : void{
		if($root !== self::$root){
			self::$root = $root;
			self::$data = null;
			self::$compiler = null;
			self::$tables = [];
		}
	}

	/**
	 * Sets the data root only if none was set yet.
	 */
	public static function setDefaultDataRoot(string $root) : void{
		if(self::$root === null && self::$data === null){
			self::setDataRoot($root);
		}
	}

	public static function getDataRoot() : string{
		return self::$root ?? ((getcwd() !== false ? getcwd() : ".") . "/" . VanillaGenerator::DEFAULT_DATA_DIR);
	}

	/**
	 * The loot data, or null if the vanilla data cache has no loot tables (not fetched yet, or an older format).
	 */
	public static function getData() : ?LootData{
		if(self::$data === null){
			$root = self::getDataRoot();
			if(!LootData::isAvailable($root)){
				return null;
			}
			self::$data = new LootData($root);
		}
		return self::$data;
	}

	public static function isAvailable() : bool{
		return self::getData() !== null;
	}

	/**
	 * Returns a loot table by Java id ("minecraft:chests/simple_dungeon", "chests/simple_dungeon") or Bedrock path
	 * ("loot_tables/chests/simple_dungeon.json"), or null if it does not exist or the data is not available.
	 */
	public static function get(string $name) : ?LootTable{
		$data = self::getData();
		if($data === null){
			if(!self::$unavailableWarned){
				self::$unavailableWarned = true;
				$root = self::getDataRoot();
				\GlobalLogger::get()->warning("[Loot] Loot table " . self::toJavaId($name) . " not rolled: the loot table data in $root is not available (" .
					LootDataManager::getUnavailableReason($root) . "); vaults, trial spawners, decorated pots, suspicious sand/gravel and loot chests roll nothing until it is");
			}
			return null;
		}
		$id = self::toJavaId($name);
		if(array_key_exists($id, self::$tables)){
			return self::$tables[$id];
		}
		self::$tables[$id] = null; //recursion guard while compiling
		$json = (self::$bedrockTables ? BedrockLootTables::get($id) : null) ?? $data->lootTableJson($id);
		if($json === null){
			return null;
		}
		try{
			return self::$tables[$id] = self::getCompiler($data)->table($id, $json);
		}catch(\RuntimeException $e){
			self::warn("table:$id", "Invalid loot table $id: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Whether the Bedrock container tables replace the Java ones (default). Java parity tests turn them off.
	 * @internal
	 */
	public static function setBedrockTablesEnabled(bool $enabled) : void{
		if($enabled !== self::$bedrockTables){
			self::$bedrockTables = $enabled;
			self::$tables = [];
		}
	}

	private static function getCompiler(LootData $data) : LootCompiler{
		return self::$compiler ??= new LootCompiler(
			$data,
			fn(string $id) : ?LootTable => self::get($id),
			fn(string $key, string $message) => self::warn($key, $message)
		);
	}

	/**
	 * Keys of the unsupported features met so far while compiling tables ("function:exploration_map"...).
	 * @internal
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getWarnings() : array{
		return array_keys(self::$warned);
	}

	private static function warn(string $key, string $message) : void{
		if(!isset(self::$warned[$key])){
			self::$warned[$key] = true;
			\GlobalLogger::get()->debug("[Loot] " . $message);
		}
	}

	/**
	 * Java id of a loot table given by Java id or Bedrock path.
	 */
	public static function toJavaId(string $name) : string{
		$name = strtolower(str_replace("\\", "/", $name));
		static $bedrockToJava = null;
		$bedrockToJava ??= array_flip(self::BEDROCK_NAMES);
		if(isset($bedrockToJava[$name])){
			return $bedrockToJava[$name];
		}
		if(str_starts_with($name, "loot_tables/")){
			$name = substr($name, strlen("loot_tables/"));
			if(str_ends_with($name, ".json")){
				$name = substr($name, 0, -5);
			}
		}
		return LootData::namespaced($name);
	}

	/**
	 * Bedrock path of a loot table (what Bedrock stores in the LootTable tag of containers and brushable blocks).
	 */
	public static function toBedrockPath(string $javaId) : string{
		$javaId = LootData::namespaced($javaId);
		return self::BEDROCK_NAMES[$javaId] ?? "loot_tables/" . LootData::stripNamespace($javaId) . ".json";
	}
}
