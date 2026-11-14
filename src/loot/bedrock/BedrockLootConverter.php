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

namespace pocketmine\loot\bedrock;

use pocketmine\loot\LootData;
use pocketmine\loot\LootTables;
use pocketmine\utils\Utils;
use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_replace;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Converts Bedrock behavior pack loot tables (loot_tables/chests/*.json...) to the Java Edition loot table format that
 * {@link \pocketmine\loot\LootCompiler} compiles, with Java item, enchantment and potion ids.
 * tools/generate-bedrock-loot-tables.php uses it to bundle the Bedrock container tables in
 * resources/bedrock_loot_tables.json.
 *
 * Bedrock functions and conditions become their Java equivalents:
 * - set_count, set_damage (fraction of durability left, like Java), set_ominous_bottle_amplifier, looting_enchant,
 *   exploration_map: the same function;
 * - enchant_randomly / enchant_with_levels: options #non_treasure / #in_enchanting_table, or #on_random_loot (curses,
 *   Frost Walker and Mending added) with "treasure": true;
 * - specific_enchants: set_enchantments; enchant_book_for_trading: enchant_randomly among its enchantments;
 * - set_data: part of the item id (minecraft:dye + 4 = lapis lazuli...), resolved by the item resolver; a set_data
 *   range on a goat horn picks a regular goat horn; random_aux_value and Bedrock's set_stew_effect on a suspicious
 *   stew pick the stew effect of the aux values;
 * - set_potion: Bedrock potion names (nightvision...) become Java ids; arrows with a potion are tipped arrows;
 * - conditions random_chance, and biome_has_tag as the "pocketmine:biome_has_tag" condition (Bedrock biome tags).
 * Anything else is reported to the warning callback and left out.
 *
 * @internal
 */
final class BedrockLootConverter{
	/** Bedrock potion ids whose Java id differs. */
	private const POTIONS = [
		"nightvision" => "night_vision",
		"long_nightvision" => "long_night_vision",
	];
	/** Bedrock enchantment ids whose Java id differs. */
	private const ENCHANTMENTS = [
		"binding" => "binding_curse",
		"vanishing" => "vanishing_curse",
	];
	/** Suspicious stew aux value (Bedrock flower) => Java effect id. */
	private const STEW_EFFECTS = [
		0 => "minecraft:night_vision", //poppy
		1 => "minecraft:jump_boost", //cornflower
		2 => "minecraft:weakness", //tulips
		3 => "minecraft:blindness", //azure bluet
		4 => "minecraft:poison", //lily of the valley
		5 => "minecraft:saturation", //dandelion
		6 => "minecraft:saturation", //blue orchid
		7 => "minecraft:fire_resistance", //allium
		8 => "minecraft:regeneration", //oxeye daisy
		9 => "minecraft:wither", //wither rose
		10 => "minecraft:night_vision", //torchflower
		11 => "minecraft:blindness", //open eyeblossom
		12 => "minecraft:nausea", //closed eyeblossom
	];
	/** Functions that only carry data used elsewhere in the conversion. */
	private const CONSUMED_FUNCTIONS = ["set_data", "random_aux_value", "set_stew_effect"];

	/**
	 * @phpstan-param \Closure(string, int) : ?string $itemResolver Bedrock item name and data value => Java item id
	 * @phpstan-param \Closure(string) : void $warn
	 */
	public function __construct(
		private \Closure $itemResolver,
		private \Closure $warn
	){}

	/**
	 * Removes the // comments some behavior pack files have (outside strings), so that they can be decoded.
	 */
	public static function stripComments(string $json) : string{
		return preg_replace('#("(?:[^"\\\\]|\\\\.)*")|//[^\r\n]*#', '$1', $json) ?? $json;
	}

	/**
	 * @param mixed[] $table decoded Bedrock table
	 * @return mixed[] Java format table
	 */
	public function table(array $table, string $type = "minecraft:chest") : array{
		$pools = [];
		foreach(self::list($table["pools"] ?? null) as $pool){
			if(is_array($pool)){
				$pools[] = $this->pool($pool);
			}
		}
		return ["type" => $type, "pools" => $pools];
	}

	/**
	 * @param mixed[] $pool
	 * @return mixed[]
	 */
	private function pool(array $pool) : array{
		if(isset($pool["tiers"])){
			($this->warn)("Pool tiers are not supported in container tables");
		}
		$result = ["rolls" => self::number($pool["rolls"] ?? 1)];
		if(isset($pool["bonus_rolls"])){
			$result["bonus_rolls"] = self::number($pool["bonus_rolls"]);
		}
		$conditions = $this->conditions($pool["conditions"] ?? null);
		if(count($conditions) > 0){
			$result["conditions"] = $conditions;
		}
		$entries = [];
		foreach(self::list($pool["entries"] ?? null) as $entry){
			if(is_array($entry)){
				$entries[] = $this->entry($entry);
			}
		}
		$result["entries"] = $entries;
		return $result;
	}

	/**
	 * @param mixed[] $entry
	 * @return mixed[]
	 */
	private function entry(array $entry) : array{
		$type = self::strip(is_string($entry["type"] ?? null) ? $entry["type"] : "item");
		$functions = [];
		$name = is_string($entry["name"] ?? null) ? $entry["name"] : "";
		switch($type){
			case "item":
				[$id, $functions] = $this->item($name, self::list($entry["functions"] ?? null));
				$result = ["type" => "minecraft:item", "name" => $id];
				break;
			case "loot_table":
				$result = ["type" => "minecraft:loot_table", "value" => LootTables::toJavaId($name)];
				$functions = $this->functions(self::list($entry["functions"] ?? null), "");
				break;
			case "empty":
				$result = ["type" => "minecraft:empty"];
				break;
			default:
				($this->warn)("Unsupported entry type $type (treated as empty)");
				$result = ["type" => "minecraft:empty"];
		}
		if(isset($entry["weight"])){
			$result["weight"] = self::int($entry["weight"]);
		}
		if(isset($entry["quality"])){
			$result["quality"] = self::int($entry["quality"]);
		}
		$conditions = $this->conditions($entry["conditions"] ?? null);
		if(count($conditions) > 0){
			$result["conditions"] = $conditions;
		}
		if(count($functions) > 0){
			$result["functions"] = $functions;
		}
		if(isset($entry["pools"])){
			($this->warn)("Nested pools are not supported in container tables");
		}
		return $result;
	}

	/**
	 * Resolves the Java item id of an item entry (its set_data is part of the id) and converts its functions.
	 *
	 * @param mixed[] $functions
	 * @return mixed[]
	 * @phpstan-return array{string, list<mixed[]>}
	 */
	private function item(string $name, array $functions) : array{
		$name = strtolower(LootData::namespaced($name));
		$data = 0;
		$extra = [];
		$explorationMap = false;
		$tippedArrow = false;
		foreach($functions as $function){
			if(!is_array($function)){
				continue;
			}
			switch(self::strip(is_string($function["function"] ?? null) ? $function["function"] : "")){
				case "set_data":
					$value = $function["data"] ?? 0;
					if(is_int($value)){
						$data = $value;
					}elseif($name === "minecraft:goat_horn"){
						//data 0-3: ponder, sing, seek, feel
						$extra[] = ["function" => "minecraft:set_instrument", "options" => "#minecraft:regular_goat_horns"];
					}else{
						($this->warn)("set_data range on $name is not supported");
					}
					break;
				case "random_aux_value":
				case "set_stew_effect":
					if($name !== "minecraft:suspicious_stew"){
						($this->warn)("Aux values of $name are not supported");
						break;
					}
					$effects = [];
					foreach(self::auxValues($function) as $value){
						if(isset(self::STEW_EFFECTS[$value])){
							$effects[] = ["type" => self::STEW_EFFECTS[$value], "duration" => 7];
						}
					}
					if(count($effects) > 0){
						$extra[] = ["function" => "minecraft:set_stew_effect", "effects" => $effects];
					}
					break;
				case "exploration_map":
					$explorationMap = true;
					break;
				case "set_potion":
					//Bedrock tipped arrows are arrows with a potion
					$tippedArrow = $name === "minecraft:arrow";
					break;
			}
		}
		//exploration maps stay empty maps, like the Java tables (no structure search)
		$id = match(true){
			$explorationMap => "minecraft:map",
			$tippedArrow => "minecraft:tipped_arrow",
			default => ($this->itemResolver)($name, $data)
		};
		if($id === null){
			($this->warn)("No Java item for $name:$data");
			$id = "minecraft:air";
		}
		$converted = $this->functions($functions, $id);
		foreach($extra as $function){
			$converted[] = $function;
		}
		return [$id, $converted];
	}

	/**
	 * Aux values of random_aux_value ({min, max}) or Bedrock set_stew_effect (effects: [{id}]).
	 * @param mixed[] $function
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function auxValues(array $function) : array{
		$values = [];
		if(isset($function["values"])){
			$range = $function["values"];
			$min = is_array($range) ? self::int($range["min"] ?? 0) : self::int($range);
			$max = is_array($range) ? self::int($range["max"] ?? $min) : $min;
			for($i = $min; $i <= $max; ++$i){
				$values[] = $i;
			}
		}else{
			foreach(self::list($function["effects"] ?? null) as $effect){
				if(is_array($effect)){
					$values[] = self::int($effect["id"] ?? 0);
				}
			}
		}
		return $values;
	}

	/**
	 * @param mixed[] $functions
	 * @return mixed[][]
	 * @phpstan-return list<mixed[]>
	 */
	private function functions(array $functions, string $item) : array{
		$result = [];
		foreach($functions as $function){
			if(!is_array($function)){
				continue;
			}
			$type = self::strip(is_string($function["function"] ?? null) ? $function["function"] : "");
			$converted = $this->function($type, $function, $item);
			if($converted !== null){
				$conditions = $this->conditions($function["conditions"] ?? null);
				if(count($conditions) > 0){
					$converted["conditions"] = $conditions;
				}
				$result[] = $converted;
			}
		}
		return $result;
	}

	/**
	 * @param mixed[] $function
	 * @return mixed[]|null
	 */
	private function function(string $type, array $function, string $item) : ?array{
		switch($type){
			case "set_count":
				$result = ["function" => "minecraft:set_count", "count" => self::number($function["count"] ?? 1)];
				if(($function["add"] ?? false) === true){
					$result["add"] = true;
				}
				return $result;
			case "set_damage":
				return ["function" => "minecraft:set_damage", "damage" => self::number($function["damage"] ?? 1.0)];
			case "enchant_randomly":
				return ["function" => "minecraft:enchant_randomly", "options" => self::enchantmentOptions($function, "#minecraft:non_treasure")];
			case "enchant_with_levels":
				return [
					"function" => "minecraft:enchant_with_levels",
					"levels" => self::number($function["levels"] ?? 0),
					"options" => self::enchantmentOptions($function, "#minecraft:in_enchanting_table")
				];
			case "enchant_book_for_trading":
				$options = [];
				foreach(self::list($function["enchantments"] ?? null) as $enchantment){
					if(is_array($enchantment) && is_string($enchantment["name"] ?? null)){
						$options[] = self::enchantment($enchantment["name"]);
					}
				}
				return ["function" => "minecraft:enchant_randomly", "options" => $options];
			case "specific_enchants":
				$enchantments = [];
				foreach(self::list($function["enchants"] ?? null) as $enchantment){
					if(is_string($enchantment)){
						$enchantments[self::enchantment($enchantment)] = 1;
					}elseif(is_array($enchantment) && is_string($enchantment["id"] ?? null)){
						$level = $enchantment["level"] ?? 1;
						$enchantments[self::enchantment($enchantment["id"])] = is_array($level) && array_is_list($level) && count($level) === 2 ?
							["type" => "minecraft:uniform", "min" => self::int($level[0]), "max" => self::int($level[1])] :
							self::number($level);
					}
				}
				return ["function" => "minecraft:set_enchantments", "enchantments" => $enchantments];
			case "set_potion":
				$id = strtolower(self::strip(is_string($function["id"] ?? null) ? $function["id"] : "water"));
				return ["function" => "minecraft:set_potion", "id" => "minecraft:" . (self::POTIONS[$id] ?? $id)];
			case "set_ominous_bottle_amplifier":
				return ["function" => "minecraft:set_ominous_bottle_amplifier", "amplifier" => self::number($function["amplifier"] ?? 0)];
			case "looting_enchant":
				return ["function" => "minecraft:enchanted_count_increase", "enchantment" => "minecraft:looting", "count" => self::number($function["count"] ?? 0)];
			case "exploration_map":
				return ["function" => "minecraft:exploration_map", "destination" => is_string($function["destination"] ?? null) ? $function["destination"] : ""];
			default:
				if(!in_array($type, self::CONSUMED_FUNCTIONS, true)){
					($this->warn)("Unsupported function $type on $item (left out)");
				}
				return null;
		}
	}

	/**
	 * Enchantments a Bedrock enchanting function may pick: with "treasure", the random loot ones (curses, Frost Walker
	 * and Mending added).
	 * @param mixed[] $function
	 */
	private static function enchantmentOptions(array $function, string $normal) : string{
		return ($function["treasure"] ?? false) === true ? "#minecraft:on_random_loot" : $normal;
	}

	/**
	 * @return mixed[][]
	 * @phpstan-return list<mixed[]>
	 */
	private function conditions(mixed $conditions) : array{
		$result = [];
		foreach(self::list($conditions) as $condition){
			if(!is_array($condition)){
				continue;
			}
			$type = self::strip(is_string($condition["condition"] ?? null) ? $condition["condition"] : "");
			switch($type){
				case "random_chance":
					$result[] = ["condition" => "minecraft:random_chance", "chance" => self::number($condition["chance"] ?? 1.0)];
					break;
				case "biome_has_tag":
					$result[] = [
						"condition" => "pocketmine:biome_has_tag",
						"tag" => is_string($condition["tag"] ?? null) ? $condition["tag"] : "",
						"operator" => is_string($condition["operator"] ?? null) ? $condition["operator"] : "=="
					];
					break;
				default:
					($this->warn)("Unsupported condition $type (it always fails)");
					$result[] = ["condition" => "minecraft:inverted", "term" => ["condition" => "minecraft:all_of", "terms" => []]];
			}
		}
		return $result;
	}

	private static function enchantment(string $id) : string{
		$id = strtolower(self::strip($id));
		return "minecraft:" . (self::ENCHANTMENTS[$id] ?? $id);
	}

	private static function strip(string $id) : string{
		return str_starts_with($id, "minecraft:") ? substr($id, 10) : $id;
	}

	/**
	 * @return mixed[]
	 */
	private static function list(mixed $value) : array{
		return is_array($value) ? Utils::promoteKeys($value) : [];
	}

	private static function int(mixed $value) : int{
		return is_int($value) ? $value : (is_float($value) ? (int) $value : 0);
	}

	/**
	 * A Bedrock number or {min, max} range as a Java number provider.
	 * @return int|float|mixed[]
	 */
	private static function number(mixed $value) : int|float|array{
		if(is_array($value)){
			$min = $value["min"] ?? 0;
			$max = $value["max"] ?? $min;
			$min = is_int($min) || is_float($min) ? $min : 0;
			$max = is_int($max) || is_float($max) ? $max : $min;
			return $min === $max ? $min : ["type" => "minecraft:uniform", "min" => $min, "max" => $max];
		}
		if(is_float($value) && $value === (float) (int) $value){
			return (int) $value;
		}
		return is_int($value) || is_float($value) ? $value : 0;
	}
}
