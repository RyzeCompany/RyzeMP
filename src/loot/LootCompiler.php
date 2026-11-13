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

use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\math\FloatMath;
use function array_is_list;
use function count;
use function floor;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function substr;

/**
 * Turns the vanilla loot table JSON (Java Edition 26.3 format: "condition"/"modifier", older "conditions"/"functions"
 * also accepted) into loot tables, with conditions and functions compiled to closures.
 *
 * Supported conditions: all_of, any_of, inverted, reference, random_chance, random_chance_with_enchanted_bonus,
 * killed_by_player, survives_explosion, table_bonus, match_tool, match_block (block_state_property), entity_properties,
 * damage_source_properties and location_check (through the context resolvers), time_check, value_check, and
 * pocketmine:biome_has_tag (Bedrock biome tags of the origin, for the Bedrock container tables).
 * Supported functions: set_count, set_damage, enchant_randomly, enchant_with_levels, set_enchantments, set_potion,
 * set_stew_effect, set_name, explosion_decay, apply_bonus, furnace_smelt, enchanted_count_increase (looting_enchant),
 * limit_count, set_ominous_bottle_amplifier, set_instrument, filtered, discard, sequence, set_item. exploration_map
 * leaves the map empty (as vanilla does when no structure is found); functions that only copy block data or set
 * cosmetic components (copy_components, copy_state, set_components...) do nothing. None of the unsupported ones use
 * the random, so the rest of the rolls stay exact.
 */
final class LootCompiler{
	/** Java furnace results of the items loot tables smelt (furnace_smelt). */
	private const SMELTED = [
		"minecraft:beef" => "minecraft:cooked_beef",
		"minecraft:porkchop" => "minecraft:cooked_porkchop",
		"minecraft:chicken" => "minecraft:cooked_chicken",
		"minecraft:mutton" => "minecraft:cooked_mutton",
		"minecraft:rabbit" => "minecraft:cooked_rabbit",
		"minecraft:cod" => "minecraft:cooked_cod",
		"minecraft:salmon" => "minecraft:cooked_salmon",
		"minecraft:potato" => "minecraft:baked_potato",
		"minecraft:kelp" => "minecraft:dried_kelp",
		"minecraft:raw_iron" => "minecraft:iron_ingot",
		"minecraft:raw_gold" => "minecraft:gold_ingot",
		"minecraft:raw_copper" => "minecraft:copper_ingot",
	];
	/** Effects whose duration is not multiplied by 20 by set_stew_effect (instantaneous effects). */
	private const INSTANT_EFFECTS = ["minecraft:instant_health", "minecraft:instant_damage", "minecraft:saturation"];
	private const NO_OP_FUNCTIONS = [
		"copy_components", "copy_state", "copy_name", "copy_custom_data", "set_lore", "set_custom_data",
		"set_banner_pattern", "set_book_cover", "set_written_book_pages", "set_writable_book_pages", "toggle_tooltips",
		"set_custom_model_data", "fill_player_head", "set_fireworks", "set_firework_explosion", "set_loot_table",
		"exploration_map",
	];

	/**
	 * @phpstan-param \Closure(string) : ?LootTable $tableResolver
	 * @phpstan-param \Closure(string, string) : void $warn
	 */
	public function __construct(
		public readonly LootData $data,
		private \Closure $tableResolver,
		private \Closure $warn
	){}

	public function warn(string $key, string $message) : void{
		($this->warn)($key, $message);
	}

	private static function type(mixed $json, string $key = "type") : string{
		return is_array($json) && is_string($json[$key] ?? null) ? LootData::stripNamespace($json[$key]) : "";
	}

	/**
	 * @param mixed[] $json
	 */
	public function table(string $id, array $json) : LootTable{
		$pools = [];
		foreach(is_array($json["pools"] ?? null) ? $json["pools"] : [] as $pool){
			if(is_array($pool)){
				$pools[] = $this->pool($pool);
			}
		}
		return new LootTable(
			$id,
			self::type($json),
			$pools,
			$this->functionsOf($json),
			$this->data,
			is_string($json["random_sequence"] ?? null) ? $json["random_sequence"] : null
		);
	}

	/**
	 * @param mixed[] $json
	 */
	private function pool(array $json) : LootPool{
		$entries = [];
		foreach(is_array($json["entries"] ?? null) ? $json["entries"] : [] as $entry){
			if(is_array($entry)){
				$entries[] = $this->entry($entry);
			}
		}
		return new LootPool(
			$entries,
			NumberProvider::parse($json["rolls"] ?? 1, $this),
			NumberProvider::parse($json["bonus_rolls"] ?? 0, $this),
			$this->conditionsOf($json),
			$this->functionsOf($json)
		);
	}

	/**
	 * @param mixed[] $json
	 */
	public function entry(array $json) : LootEntry{
		$type = self::type($json);
		$condition = $this->conditionsOf($json);
		$children = [];
		foreach(is_array($json["children"] ?? null) ? $json["children"] : [] as $child){
			if(is_array($child)){
				$children[] = $this->entry($child);
			}
		}
		$weight = is_int($json["weight"] ?? null) ? $json["weight"] : 1;
		$quality = is_int($json["quality"] ?? null) ? $json["quality"] : 0;
		$function = $this->functionsOf($json);
		switch($type){
			case "item":
				return new LootEntry(LootEntry::ITEM, $weight, $quality, $condition, $function, LootData::namespaced(is_string($json["name"] ?? null) ? $json["name"] : "minecraft:air"));
			case "tag":
				$tag = is_string($json["items"] ?? null) ? $json["items"] : (is_string($json["name"] ?? null) ? $json["name"] : "");
				if($tag !== "" && $tag[0] === "#"){
					$tag = substr($tag, 1);
				}
				$entry = new LootEntry(LootEntry::TAG, $weight, $quality, $condition, $function, LootData::namespaced($tag));
				$entry->expand = LootData::bool($json["expand"] ?? null, false);
				$entry->tagItems = $this->data->tag("item", $tag);
				return $entry;
			case "loot_table":
				$entry = new LootEntry(LootEntry::TABLE, $weight, $quality, $condition, $function, "");
				$value = $json["value"] ?? ($json["name"] ?? null);
				if(is_string($value)){
					$entry->name = LootData::namespaced($value);
					$resolver = $this->tableResolver;
					$name = $entry->name;
					$entry->tableProvider = fn() : ?LootTable => $resolver($name);
				}elseif(is_array($value)){
					$inline = $this->table("inline", $value);
					$entry->tableProvider = fn() : LootTable => $inline;
				}
				return $entry;
			case "dynamic":
				return new LootEntry(LootEntry::DYNAMIC, $weight, $quality, $condition, $function, LootData::namespaced(is_string($json["name"] ?? null) ? $json["name"] : ""));
			case "empty":
				return new LootEntry(LootEntry::EMPTY, $weight, $quality, $condition, $function, "");
			case "alternatives":
				$entry = new LootEntry(LootEntry::ALTERNATIVES, $weight, $quality, $condition, null, "");
				$entry->children = $children;
				return $entry;
			case "group":
				$entry = new LootEntry(LootEntry::GROUP, $weight, $quality, $condition, null, "");
				$entry->children = $children;
				return $entry;
			case "sequence":
				$entry = new LootEntry(LootEntry::SEQUENCE, $weight, $quality, $condition, null, "");
				$entry->children = $children;
				return $entry;
			default:
				$this->warn("entry:$type", "Unsupported loot entry type $type (treated as empty)");
				return new LootEntry(LootEntry::EMPTY, $weight, $quality, $condition, null, "");
		}
	}

	// ---------------------------------------------------------------- conditions

	/**
	 * The conditions of an object ("condition" in 26.x, "conditions" before), all of which must pass.
	 * @param mixed[] $json
	 * @phpstan-return (\Closure(LootContext) : bool)|null
	 */
	public function conditionsOf(array $json) : ?\Closure{
		$value = $json["condition"] ?? ($json["conditions"] ?? null);
		return $value === null ? null : $this->condition($value);
	}

	/**
	 * @phpstan-return \Closure(LootContext) : bool
	 */
	public function condition(mixed $json) : \Closure{
		if(is_string($json)){
			return $this->reference($json);
		}
		if(is_array($json) && array_is_list($json)){
			$terms = [];
			foreach($json as $term){
				$terms[] = $this->condition($term);
			}
			return self::allOf($terms);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid loot condition");
		}
		$type = self::type($json);
		if($type === ""){
			$type = self::type($json, "condition");
		}
		switch($type){
			case "inverted":
				$term = $this->condition($json["term"] ?? []);
				return fn(LootContext $c) : bool => !$term($c);
			case "any_of":
			case "alternative":
				$terms = [];
				foreach(is_array($json["terms"] ?? null) ? $json["terms"] : [] as $term){
					$terms[] = $this->condition($term);
				}
				return function(LootContext $c) use ($terms) : bool{
					foreach($terms as $term){
						if($term($c)){
							return true;
						}
					}
					return false;
				};
			case "all_of":
				$terms = [];
				foreach(is_array($json["terms"] ?? null) ? $json["terms"] : [] as $term){
					$terms[] = $this->condition($term);
				}
				return self::allOf($terms);
			case "reference":
				return $this->reference(is_string($json["name"] ?? null) ? $json["name"] : "");
			case "random_chance":
				$chance = NumberProvider::parse($json["chance"] ?? 0, $this);
				return fn(LootContext $c) : bool => $c->random->nextFloat() < $chance->getFloat($c);
			case "random_chance_with_looting":
				$chance = FloatMath::fround(self::number($json["chance"] ?? 0));
				$multiplier = FloatMath::fround(self::number($json["looting_multiplier"] ?? 0));
				return function(LootContext $c) use ($chance, $multiplier) : bool{
					$level = $c->getEntityEnchantmentLevel($c->attackingEntity, "minecraft:looting");
					return $c->random->nextFloat() < FloatMath::fround($chance + FloatMath::fround($level * $multiplier));
				};
			case "random_chance_with_enchanted_bonus":
				$unenchanted = FloatMath::fround(self::number($json["unenchanted_chance"] ?? 0));
				$enchanted = $this->levelBasedValue($json["enchanted_chance"] ?? 0);
				$enchantment = LootData::namespaced(is_string($json["enchantment"] ?? null) ? $json["enchantment"] : "");
				return function(LootContext $c) use ($unenchanted, $enchanted, $enchantment) : bool{
					$level = $c->getEntityEnchantmentLevel($c->attackingEntity, $enchantment);
					return $c->random->nextFloat() < ($level > 0 ? $enchanted($level) : $unenchanted);
				};
			case "killed_by_player":
				return fn(LootContext $c) : bool => $c->lastDamagePlayer !== null;
			case "survives_explosion":
				return function(LootContext $c) : bool{
					if($c->explosionRadius === null){
						return true;
					}
					return $c->random->nextFloat() <= FloatMath::fround(1.0 / FloatMath::fround($c->explosionRadius));
				};
			case "table_bonus":
				$enchantment = LootData::namespaced(is_string($json["enchantment"] ?? null) ? $json["enchantment"] : "");
				$chances = [];
				foreach(is_array($json["chances"] ?? null) ? $json["chances"] : [] as $chance){
					$chances[] = FloatMath::fround(self::number($chance));
				}
				if(count($chances) === 0){
					$chances[] = 0.0;
				}
				return function(LootContext $c) use ($enchantment, $chances) : bool{
					$level = $c->tool !== null ? $c->tool->getEnchantmentLevel($enchantment) : 0;
					return $c->random->nextFloat() < $chances[min($level, count($chances) - 1)];
				};
			case "match_tool":
				$predicate = $this->itemPredicate($json["predicate"] ?? []);
				return fn(LootContext $c) : bool => $c->tool !== null && $predicate($c->tool, $c);
			case "match_block":
			case "block_state_property":
				$blocks = $this->data->holderSet("block", $json["blocks"] ?? ($json["block"] ?? []));
				$stateJson = $json["state"] ?? ($json["properties"] ?? null);
				$state = [];
				foreach(Utils::promoteKeys(is_array($stateJson) ? $stateJson : []) as $property => $expected){
					$state[(string) $property] = match(true){
						is_array($expected) => [is_int($expected["min"] ?? null) ? $expected["min"] : null, is_int($expected["max"] ?? null) ? $expected["max"] : null],
						is_bool($expected) => $expected ? "true" : "false",
						is_string($expected) => $expected,
						is_int($expected), is_float($expected) => (string) $expected,
						default => ""
					};
				}
				return function(LootContext $c) use ($blocks, $state) : bool{
					if($c->blockId === null || (count($blocks) > 0 && !in_array($c->blockId, $blocks, true))){
						return false;
					}
					foreach(Utils::stringifyKeys($state) as $property => $expected){
						$value = $c->blockProperties[$property] ?? null;
						if($value === null){
							return false;
						}
						if(is_array($expected)){
							//range of a numeric property
							if(($expected[0] !== null && (int) $value < $expected[0]) || ($expected[1] !== null && (int) $value > $expected[1])){
								return false;
							}
						}elseif($expected !== $value){
							return false;
						}
					}
					return true;
				};
			case "entity_properties":
				$target = is_string($json["entity"] ?? null) ? $json["entity"] : "this";
				$predicate = is_array($json["predicate"] ?? null) ? $json["predicate"] : [];
				return function(LootContext $c) use ($target, $predicate) : bool{
					$entity = match($target){
						"this" => $c->thisEntity,
						"attacker", "killer" => $c->attackingEntity,
						"direct_attacker", "direct_killer" => $c->directAttackingEntity,
						"attacking_player", "killer_player" => $c->lastDamagePlayer,
						default => null
					};
					if($entity === null){
						return false;
					}
					if(count($predicate) === 0){
						return true;
					}
					return $c->entityPredicate !== null && ($c->entityPredicate)($target, $predicate);
				};
			case "damage_source_properties":
				$predicate = is_array($json["predicate"] ?? null) ? $json["predicate"] : [];
				return fn(LootContext $c) : bool => $c->damageSourcePredicate !== null && ($c->damageSourcePredicate)($predicate);
			case "location_check":
				$predicate = is_array($json["predicate"] ?? null) ? $json["predicate"] : [];
				$dx = is_int($json["offsetX"] ?? null) ? $json["offsetX"] : 0;
				$dy = is_int($json["offsetY"] ?? null) ? $json["offsetY"] : 0;
				$dz = is_int($json["offsetZ"] ?? null) ? $json["offsetZ"] : 0;
				$biomes = isset($predicate["biomes"]) && count($predicate) === 1 ? $this->data->holderSet("worldgen/biome", $predicate["biomes"]) : null;
				return function(LootContext $c) use ($predicate, $dx, $dy, $dz, $biomes) : bool{
					if($c->origin === null){
						return false;
					}
					if($c->locationPredicate !== null){
						return ($c->locationPredicate)($predicate, $dx, $dy, $dz);
					}
					if($biomes !== null && $c->biomeAt !== null){
						$biome = ($c->biomeAt)((int) floor($c->origin->x) + $dx, (int) floor($c->origin->y) + $dy, (int) floor($c->origin->z) + $dz);
						return $biome !== null && in_array($biome, $biomes, true);
					}
					return false;
				};
			case "time_check":
				$range = $this->intRange($json["value"] ?? []);
				$period = is_int($json["period"] ?? null) ? $json["period"] : null;
				return function(LootContext $c) use ($range, $period) : bool{
					if($c->world === null){
						return false;
					}
					$time = $c->world->getTime();
					if($period !== null && $period > 0){
						$time %= $period;
					}
					return $range[0]($c, $time);
				};
			case "value_check":
				$value = NumberProvider::parse($json["value"] ?? 0, $this);
				$range = $this->intRange($json["range"] ?? []);
				return fn(LootContext $c) : bool => $range[0]($c, $value->getInt($c));
			default:
				$this->warn("condition:$type", "Unsupported loot condition $type (it always fails)");
				return fn(LootContext $c) : bool => false;
		}
	}

	/**
	 * @phpstan-param list<\Closure(LootContext) : bool> $terms
	 * @phpstan-return \Closure(LootContext) : bool
	 */
	private static function allOf(array $terms) : \Closure{
		if(count($terms) === 1){
			return $terms[0];
		}
		return function(LootContext $c) use ($terms) : bool{
			foreach($terms as $term){
				if(!$term($c)){
					return false;
				}
			}
			return true;
		};
	}

	/**
	 * A predicate file reference (data/minecraft/predicate), e.g. "minecraft:tool/can_silk_touch".
	 * @phpstan-return \Closure(LootContext) : bool
	 */
	private function reference(string $id) : \Closure{
		$id = LootData::namespaced($id);
		$compiled = null;
		return function(LootContext $c) use ($id, &$compiled) : bool{
			if($compiled === null){
				$json = $this->data->predicateJson($id);
				if($json === null){
					$this->warn("predicate:$id", "Unknown predicate $id (it always fails)");
					$compiled = fn(LootContext $c) : bool => false;
				}else{
					$compiled = $this->condition($json);
				}
			}
			$key = "predicate:" . $id;
			if(isset($c->visited[$key])){
				return false;
			}
			$c->visited[$key] = true;
			try{
				return $compiled($c);
			}finally{
				unset($c->visited[$key]);
			}
		};
	}

	private static function number(mixed $value) : float{
		return is_int($value) || is_float($value) ? (float) $value : 0.0;
	}

	/**
	 * LevelBasedValue: a number, or {type: constant|linear|clamped|fraction|levels_squared|lookup}.
	 * @phpstan-return \Closure(int) : float
	 */
	private function levelBasedValue(mixed $json) : \Closure{
		if(is_int($json) || is_float($json)){
			$value = FloatMath::fround((float) $json);
			return fn(int $level) : float => $value;
		}
		$type = self::type($json);
		if(!is_array($json)){
			return fn(int $level) : float => 0.0;
		}
		switch($type){
			case "constant":
				$value = FloatMath::fround(self::number($json["value"] ?? 0));
				return fn(int $level) : float => $value;
			case "linear":
				$base = FloatMath::fround(self::number($json["base"] ?? 0));
				$perLevel = FloatMath::fround(self::number($json["per_level_above_first"] ?? 0));
				return fn(int $level) : float => FloatMath::fround($base + FloatMath::fround($perLevel * ($level - 1)));
			case "levels_squared":
				$added = FloatMath::fround(self::number($json["added"] ?? 0));
				return fn(int $level) : float => FloatMath::fround(($level * $level) + $added);
			case "clamped":
				$value = $this->levelBasedValue($json["value"] ?? 0);
				$min = FloatMath::fround(self::number($json["min"] ?? 0));
				$max = FloatMath::fround(self::number($json["max"] ?? 0));
				return fn(int $level) : float => max($min, min($max, $value($level)));
			case "fraction":
				$numerator = $this->levelBasedValue($json["numerator"] ?? 0);
				$denominator = $this->levelBasedValue($json["denominator"] ?? 1);
				return function(int $level) use ($numerator, $denominator) : float{
					$d = $denominator($level);
					return $d === 0.0 ? 0.0 : FloatMath::fround($numerator($level) / $d);
				};
			case "lookup":
				$values = [];
				foreach(is_array($json["values"] ?? null) ? $json["values"] : [] as $value){
					$values[] = FloatMath::fround(self::number($value));
				}
				$fallback = $this->levelBasedValue($json["fallback"] ?? 0);
				return fn(int $level) : float => $values[$level - 1] ?? $fallback($level);
			default:
				$this->warn("level_based:$type", "Unsupported level based value $type (using 0)");
				return fn(int $level) : float => 0.0;
		}
	}

	/**
	 * IntRange: a number or {min, max} of number providers. Returns [test, clamp].
	 * @phpstan-return array{\Closure(LootContext, int) : bool, \Closure(LootContext, int) : int}
	 */
	private function intRange(mixed $json) : array{
		if(is_int($json) || is_float($json)){
			$exact = (int) $json;
			return [fn(LootContext $c, int $v) : bool => $v === $exact, fn(LootContext $c, int $v) : int => $exact];
		}
		$min = is_array($json) && isset($json["min"]) ? NumberProvider::parse($json["min"], $this) : null;
		$max = is_array($json) && isset($json["max"]) ? NumberProvider::parse($json["max"], $this) : null;
		return [
			fn(LootContext $c, int $v) : bool => ($min === null || $min->getInt($c) <= $v) && ($max === null || $v <= $max->getInt($c)),
			function(LootContext $c, int $v) use ($min, $max) : int{
				if($min !== null){
					$v = max($min->getInt($c), $v);
				}
				if($max !== null){
					$v = min($max->getInt($c), $v);
				}
				return $v;
			}
		];
	}

	/**
	 * Item predicate: {items, count, predicates: {"minecraft:enchantments": [...], ...}}.
	 * @phpstan-return \Closure(LootStack, LootContext) : bool
	 */
	private function itemPredicate(mixed $json) : \Closure{
		if(!is_array($json)){
			return fn(LootStack $s, LootContext $c) : bool => true;
		}
		$items = isset($json["items"]) ? $this->data->holderSet("item", $json["items"]) : null;
		$count = isset($json["count"]) ? $this->intRange($json["count"]) : null;
		$tests = [];
		foreach(Utils::promoteKeys(is_array($json["predicates"] ?? null) ? $json["predicates"] : []) as $component => $spec){
			$component = LootData::stripNamespace((string) $component);
			switch($component){
				case "enchantments":
				case "stored_enchantments":
					foreach(is_array($spec) ? $spec : [] as $enchantmentSpec){
						if(!is_array($enchantmentSpec)){
							continue;
						}
						$enchantments = isset($enchantmentSpec["enchantments"]) ? $this->data->holderSet("enchantment", $enchantmentSpec["enchantments"]) : null;
						$levels = isset($enchantmentSpec["levels"]) ? $this->intRange($enchantmentSpec["levels"]) : null;
						$tests[] = function(LootStack $s, LootContext $c) use ($enchantments, $levels) : bool{
							foreach(Utils::stringifyKeys($s->enchantments) as $id => $level){
								if(($enchantments === null || in_array($id, $enchantments, true)) && ($levels === null || $levels[0]($c, $level))){
									return true;
								}
							}
							return false;
						};
					}
					break;
				case "map_id":
					//loot stacks never carry a map id (exploration maps stay empty)
					$tests[] = fn(LootStack $s, LootContext $c) : bool => false;
					break;
				default:
					$this->warn("item_predicate:$component", "Unsupported item sub-predicate $component (it always fails)");
					$tests[] = fn(LootStack $s, LootContext $c) : bool => false;
			}
		}
		if(isset($json["components"])){
			$this->warn("item_predicate_components", "Item predicates on exact components are not supported (they always fail)");
			$tests[] = fn(LootStack $s, LootContext $c) : bool => false;
		}
		return function(LootStack $s, LootContext $c) use ($items, $count, $tests) : bool{
			if($items !== null && !in_array($s->id, $items, true)){
				return false;
			}
			if($count !== null && !$count[0]($c, $s->count)){
				return false;
			}
			foreach($tests as $test){
				if(!$test($s, $c)){
					return false;
				}
			}
			return true;
		};
	}

	// ---------------------------------------------------------------- functions

	/**
	 * The functions of an object ("modifier" in 26.x, "functions" before), applied in order.
	 * @param mixed[] $json
	 * @phpstan-return (\Closure(LootStack, LootContext) : LootStack)|null
	 */
	public function functionsOf(array $json) : ?\Closure{
		$value = $json["modifier"] ?? ($json["functions"] ?? null);
		return $value === null ? null : $this->function($value);
	}

	/**
	 * @phpstan-return \Closure(LootStack, LootContext) : LootStack
	 */
	public function function(mixed $json) : \Closure{
		if(is_array($json) && array_is_list($json)){
			$functions = [];
			foreach($json as $function){
				$functions[] = $this->function($function);
			}
			if(count($functions) === 1){
				return $functions[0];
			}
			return function(LootStack $s, LootContext $c) use ($functions) : LootStack{
				foreach($functions as $function){
					$s = $function($s, $c);
				}
				return $s;
			};
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid loot function");
		}
		$type = self::type($json);
		if($type === ""){
			$type = self::type($json, "function");
		}
		$run = $this->functionBody($type, $json);
		$condition = $this->conditionsOf($json);
		if($condition === null){
			return $run;
		}
		return fn(LootStack $s, LootContext $c) : LootStack => $condition($c) ? $run($s, $c) : $s;
	}

	/**
	 * @param mixed[] $json
	 * @phpstan-return \Closure(LootStack, LootContext) : LootStack
	 */
	private function functionBody(string $type, array $json) : \Closure{
		$data = $this->data;
		switch($type){
			case "set_count":
				$count = NumberProvider::parse($json["count"] ?? 1, $this);
				$add = LootData::bool($json["add"] ?? null, false);
				return function(LootStack $s, LootContext $c) use ($count, $add) : LootStack{
					$s->count = ($add ? $s->count : 0) + $count->getInt($c);
					return $s;
				};
			case "set_damage":
				$damage = NumberProvider::parse($json["damage"] ?? 0, $this);
				$add = LootData::bool($json["add"] ?? null, false);
				return function(LootStack $s, LootContext $c) use ($damage, $add, $data) : LootStack{
					$maxDamage = $data->itemInfo($s->id)[1];
					if($maxDamage > 0){
						$current = $add ? FloatMath::fround(1.0 - FloatMath::fround($s->damage / $maxDamage)) : 0.0;
						$value = FloatMath::fround($damage->getFloat($c) + $current);
						$ratio = FloatMath::fround(1.0 - max(0.0, min(1.0, $value)));
						$s->damage = (int) floor(FloatMath::fround($ratio * $maxDamage));
					}
					return $s;
				};
			case "enchant_randomly":
				$options = isset($json["options"]) ? $data->holderSet("enchantment", $json["options"]) : null;
				$onlyCompatible = LootData::bool($json["only_compatible"] ?? null, true);
				return fn(LootStack $s, LootContext $c) : LootStack => LootEnchanting::enchantRandomly($data, $s, $c, $options, $onlyCompatible);
			case "enchant_with_levels":
				$levels = NumberProvider::parse($json["levels"] ?? 0, $this);
				$options = isset($json["options"]) ? $data->holderSet("enchantment", $json["options"]) : null;
				return fn(LootStack $s, LootContext $c) : LootStack => LootEnchanting::enchantItem($data, $c, $s, $levels->getInt($c), $options ?? $data->enchantmentIds());
			case "set_enchantments":
				$enchantments = [];
				foreach(Utils::promoteKeys(is_array($json["enchantments"] ?? null) ? $json["enchantments"] : []) as $id => $level){
					$enchantments[LootData::namespaced((string) $id)] = NumberProvider::parse($level, $this);
				}
				$add = LootData::bool($json["add"] ?? null, false);
				return function(LootStack $s, LootContext $c) use ($enchantments, $add) : LootStack{
					if($s->id === "minecraft:book"){
						$s = $s->transmute("minecraft:enchanted_book");
					}
					foreach(Utils::stringifyKeys($enchantments) as $id => $level){
						$value = max(0, min(255, ($add ? $s->getEnchantmentLevel($id) : 0) + $level->getInt($c)));
						if($value === 0){
							unset($s->enchantments[$id]);
						}else{
							$s->enchantments[$id] = $value;
						}
					}
					return $s;
				};
			case "set_potion":
				$potion = LootData::namespaced(is_string($json["id"] ?? null) ? $json["id"] : "minecraft:water");
				return function(LootStack $s, LootContext $c) use ($potion) : LootStack{
					$s->potion = $potion;
					return $s;
				};
			case "set_stew_effect":
				$effects = [];
				foreach(is_array($json["effects"] ?? null) ? $json["effects"] : [] as $effect){
					if(is_array($effect)){
						$effects[] = [LootData::namespaced(is_string($effect["type"] ?? null) ? $effect["type"] : ""), NumberProvider::parse($effect["duration"] ?? 160, $this)];
					}
				}
				return function(LootStack $s, LootContext $c) use ($effects) : LootStack{
					if($s->id === "minecraft:suspicious_stew" && count($effects) > 0){
						[$effect, $duration] = $effects[$c->random->nextBoundedInt(count($effects))];
						$ticks = $duration->getInt($c);
						if(!in_array($effect, self::INSTANT_EFFECTS, true)){
							$ticks *= 20;
						}
						$s->stewEffects[] = [$effect, $ticks];
					}
					return $s;
				};
			case "set_name":
				$name = $json["name"] ?? null;
				$text = is_string($name) ? $name : (is_array($name) && is_string($name["text"] ?? null) ? $name["text"] : null);
				$target = is_string($json["target"] ?? null) ? $json["target"] : "custom_name";
				return function(LootStack $s, LootContext $c) use ($text, $target) : LootStack{
					//item_name only changes the default name (translated by the client); not representable on Bedrock
					if($text !== null && $target === "custom_name"){
						$s->customName = $text;
					}
					return $s;
				};
			case "explosion_decay":
				return function(LootStack $s, LootContext $c) : LootStack{
					if($c->explosionRadius !== null){
						$chance = FloatMath::fround(1.0 / FloatMath::fround($c->explosionRadius));
						$count = 0;
						for($i = 0, $n = $s->count; $i < $n; ++$i){
							if($c->random->nextFloat() <= $chance){
								++$count;
							}
						}
						$s->count = $count;
					}
					return $s;
				};
			case "apply_bonus":
				$enchantment = LootData::namespaced(is_string($json["enchantment"] ?? null) ? $json["enchantment"] : "");
				$formula = LootData::stripNamespace(is_string($json["formula"] ?? null) ? $json["formula"] : "");
				$parameters = is_array($json["parameters"] ?? null) ? $json["parameters"] : [];
				$extra = is_int($parameters["extra"] ?? null) ? $parameters["extra"] : 0;
				$probability = FloatMath::fround(self::number($parameters["probability"] ?? 0));
				$multiplier = is_int($parameters["bonusMultiplier"] ?? null) ? $parameters["bonusMultiplier"] : 1;
				if(!in_array($formula, ["binomial_with_bonus_count", "uniform_bonus_count", "ore_drops"], true)){
					$this->warn("formula:$formula", "Unsupported apply_bonus formula $formula");
				}
				return function(LootStack $s, LootContext $c) use ($enchantment, $formula, $extra, $probability, $multiplier) : LootStack{
					if($c->tool === null){
						return $s;
					}
					$level = $c->tool->getEnchantmentLevel($enchantment);
					$random = $c->random;
					switch($formula){
						case "binomial_with_bonus_count":
							for($i = 0; $i < $level + $extra; ++$i){
								if($random->nextFloat() < $probability){
									++$s->count;
								}
							}
							break;
						case "uniform_bonus_count":
							$s->count += $random->nextBoundedInt($multiplier * $level + 1);
							break;
						case "ore_drops":
							if($level > 0){
								$bonus = max(0, $random->nextBoundedInt($level + 2) - 1);
								$s->count *= $bonus + 1;
							}
							break;
					}
					return $s;
				};
			case "furnace_smelt":
				return function(LootStack $s, LootContext $c) : LootStack{
					$result = self::SMELTED[$s->id] ?? null;
					return $result !== null ? $s->transmute($result) : $s;
				};
			case "enchanted_count_increase":
			case "looting_enchant":
				$count = NumberProvider::parse($json["count"] ?? 0, $this);
				$enchantment = $type === "looting_enchant" ? "minecraft:looting" : LootData::namespaced(is_string($json["enchantment"] ?? null) ? $json["enchantment"] : "");
				$limit = is_int($json["limit"] ?? null) ? $json["limit"] : 0;
				return function(LootStack $s, LootContext $c) use ($count, $enchantment, $limit) : LootStack{
					$level = $c->getEntityEnchantmentLevel($c->attackingEntity, $enchantment);
					if($level === 0){
						return $s;
					}
					$s->count += NumberProvider::round(FloatMath::fround($level * $count->getFloat($c)));
					if($limit > 0 && $s->count > $limit){
						$s->count = $limit;
					}
					return $s;
				};
			case "limit_count":
				$range = $this->intRange($json["limit"] ?? []);
				return function(LootStack $s, LootContext $c) use ($range) : LootStack{
					$s->count = $range[1]($c, $s->count);
					return $s;
				};
			case "set_ominous_bottle_amplifier":
				$amplifier = NumberProvider::parse($json["amplifier"] ?? 0, $this);
				return function(LootStack $s, LootContext $c) use ($amplifier) : LootStack{
					$s->ominousAmplifier = max(0, min(4, $amplifier->getInt($c)));
					return $s;
				};
			case "set_instrument":
				$options = $data->holderSet("instrument", $json["options"] ?? []);
				return function(LootStack $s, LootContext $c) use ($options) : LootStack{
					if(count($options) > 0){
						$s->instrument = $options[$c->random->nextBoundedInt(count($options))];
					}
					return $s;
				};
			case "filtered":
				$filter = $this->itemPredicate($json["item_filter"] ?? []);
				$onPass = isset($json["on_pass"]) ? $this->function($json["on_pass"]) : (isset($json["modifier"]) ? $this->function($json["modifier"]) : null);
				$onFail = isset($json["on_fail"]) ? $this->function($json["on_fail"]) : null;
				return function(LootStack $s, LootContext $c) use ($filter, $onPass, $onFail) : LootStack{
					$function = $filter($s, $c) ? $onPass : $onFail;
					return $function !== null ? $function($s, $c) : $s;
				};
			case "discard":
				return fn(LootStack $s, LootContext $c) : LootStack => LootStack::empty();
			case "sequence":
				return $this->function(is_array($json["functions"] ?? null) ? $json["functions"] : []);
			case "set_components":
				//only the components that have a PocketMine equivalent; the others are ignored
				$trim = null;
				foreach(Utils::promoteKeys(is_array($json["components"] ?? null) ? $json["components"] : []) as $component => $value){
					$component = LootData::stripNamespace((string) $component);
					if($component === "trim" && is_array($value) && is_string($value["material"] ?? null) && is_string($value["pattern"] ?? null)){
						$trim = [LootData::namespaced($value["material"]), LootData::namespaced($value["pattern"])];
					}else{
						$this->warn("component:$component", "Unsupported item component $component in set_components (ignored)");
					}
				}
				return function(LootStack $s, LootContext $c) use ($trim) : LootStack{
					if($trim !== null){
						$s->trim = $trim;
					}
					return $s;
				};
			case "set_item":
				$item = LootData::namespaced(is_string($json["item"] ?? null) ? $json["item"] : "minecraft:air");
				return fn(LootStack $s, LootContext $c) : LootStack => $s->transmute($item);
			default:
				if(!in_array($type, self::NO_OP_FUNCTIONS, true)){
					$this->warn("function:$type", "Unsupported loot function $type (ignored)");
				}
				return fn(LootStack $s, LootContext $c) : LootStack => $s;
		}
	}
}
