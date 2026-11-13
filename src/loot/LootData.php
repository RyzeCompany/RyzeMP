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

use pocketmine\item\Book;
use pocketmine\item\Durable;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function array_key_exists;
use function array_keys;
use function basename;
use function glob;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function sort;
use function str_contains;
use function str_starts_with;
use function substr;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

/**
 * Read access to the vanilla data used by loot tables, in the vanilla data cache prepared by
 * {@link \pocketmine\world\generator\vanilla\VanillaDataFetcher} (Mojang's data generator output, never shipped):
 *   <root>/data/minecraft/{loot_table,predicate,enchantment,tags/item,tags/enchantment,...}
 *   <root>/java_items.json (max stack size, max damage and enchantability of every Java item)
 *   <root>/geyser/items.json (GeyserMC's Java -> Bedrock item mappings)
 */
final class LootData{
	public const JAVA_ITEMS_FILE = "java_items.json";
	public const GEYSER_ITEMS_FILE = "geyser/items.json";
	/** Damageable Java items without the enchantable component (an enchanting table refuses them). */
	private const NOT_ENCHANTABLE_DURABLE = [
		"minecraft:shears" => true,
		"minecraft:flint_and_steel" => true,
		"minecraft:shield" => true,
		"minecraft:elytra" => true,
		"minecraft:brush" => true,
		"minecraft:carrot_on_a_stick" => true,
		"minecraft:warped_fungus_on_a_stick" => true,
	];

	/**
	 * @var mixed[]
	 * @phpstan-var array<string, mixed>
	 */
	private array $jsonCache = [];
	/**
	 * @var int[][]|null
	 * @phpstan-var array<string, array{int, int, int}>|null
	 */
	private ?array $items = null;
	/** No java_items.json: {@link $items} is filled from PocketMine items on demand. */
	private bool $itemsFromPocketMine = false;
	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $missingItems = [];
	/**
	 * @var string[][]
	 * @phpstan-var array<string, list<string>>
	 */
	private array $tags = [];
	/**
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	private ?array $enchantmentIds = null;
	/**
	 * @var JavaEnchantment[]
	 * @phpstan-var array<string, JavaEnchantment|null>
	 */
	private array $enchantments = [];

	public function __construct(private string $root){}

	public function getRoot() : string{ return $this->root; }

	/**
	 * Whether $root holds the loot table data (vanilla data format 3 or later, or the loot-only data).
	 */
	public static function isAvailable(string $root) : bool{
		return is_dir($root . "/data/minecraft/loot_table") && (is_file($root . "/" . self::JAVA_ITEMS_FILE));
	}

	/**
	 * Adds the "minecraft:" namespace to an id that has none.
	 */
	public static function namespaced(string $id) : string{
		return str_contains($id, ":") ? $id : "minecraft:" . $id;
	}

	public static function stripNamespace(string $id) : string{
		return str_starts_with($id, "minecraft:") ? substr($id, 10) : $id;
	}

	/**
	 * Reads a JSON file of the cache, or returns null if it does not exist.
	 */
	public function readJson(string $relativePath) : mixed{
		if(array_key_exists($relativePath, $this->jsonCache)){
			return $this->jsonCache[$relativePath];
		}
		$path = $this->root . "/" . $relativePath;
		$value = is_file($path) ? json_decode(Filesystem::fileGetContents($path), true, 512, JSON_THROW_ON_ERROR) : null;
		return $this->jsonCache[$relativePath] = $value;
	}

	/**
	 * @return mixed[]|null
	 */
	public function lootTableJson(string $id) : ?array{
		$json = $this->readJson("data/minecraft/loot_table/" . self::stripNamespace($id) . ".json");
		return is_array($json) ? $json : null;
	}

	public function predicateJson(string $id) : mixed{
		return $this->readJson("data/minecraft/predicate/" . self::stripNamespace($id) . ".json");
	}

	/**
	 * Members of a tag (recursively resolved, in tag order without duplicates, like vanilla's TagLoader).
	 * $registry is the tag directory: "item", "enchantment", "entity_type", "instrument"...
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function tag(string $registry, string $id, int $depth = 0) : array{
		$id = self::namespaced($id);
		$key = $registry . "#" . $id;
		if(isset($this->tags[$key])){
			return $this->tags[$key];
		}
		if($depth > 32){
			throw new \RuntimeException("Tag $id of $registry is recursive");
		}
		$json = $this->readJson("data/minecraft/tags/$registry/" . self::stripNamespace($id) . ".json");
		$set = [];
		foreach(is_array($json) && is_array($json["values"] ?? null) ? $json["values"] : [] as $value){
			if(is_array($value)){
				//{"id": ..., "required": false}
				$value = $value["id"] ?? null;
			}
			if(!is_string($value)){
				continue;
			}
			if(str_starts_with($value, "#")){
				foreach($this->tag($registry, substr($value, 1), $depth + 1) as $member){
					$set[$member] = true;
				}
			}else{
				$set[self::namespaced($value)] = true;
			}
		}
		return $this->tags[$key] = array_keys($set);
	}

	/**
	 * Resolves a holder set: "#tag", a single id or a list of ids.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function holderSet(string $registry, mixed $spec) : array{
		if(is_string($spec)){
			return str_starts_with($spec, "#") ? $this->tag($registry, substr($spec, 1)) : [self::namespaced($spec)];
		}
		$list = [];
		if(is_array($spec)){
			foreach($spec as $value){
				if(is_string($value)){
					$list[self::namespaced($value)] = true;
				}
			}
		}
		return array_keys($list);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int, int} max stack size, max damage (0: not damageable), enchantability (0: none)
	 */
	public function itemInfo(string $id) : array{
		if($this->items === null && !is_file($this->root . "/" . self::JAVA_ITEMS_FILE)){
			//loot-only data (no data generator report): the matching PocketMine items tell, one by one
			$this->items = [];
			$this->itemsFromPocketMine = true;
		}
		if($this->itemsFromPocketMine){
			if(!isset($this->items[$id]) && !isset($this->missingItems[$id])){
				$info = $this->pocketMineItemInfo($id);
				if($info !== null){
					$this->items[$id] = $info;
				}else{
					$this->missingItems[$id] = true;
				}
			}
			return $this->items[$id] ?? [64, 0, 0];
		}
		if($this->items === null){
			$json = $this->readJson(self::JAVA_ITEMS_FILE);
			$items = [];
			if(is_array($json) && is_array($json["items"] ?? null)){
				foreach(Utils::promoteKeys($json["items"]) as $itemId => $info){
					if(is_array($info) && is_int($info[0] ?? null) && is_int($info[1] ?? null) && is_int($info[2] ?? null)){
						$items[(string) $itemId] = [$info[0], $info[1], $info[2]];
					}
				}
			}
			$this->items = $items;
		}
		return $this->items[$id] ?? [64, 0, 0];
	}

	public function itemExists(string $id) : bool{
		$this->itemInfo($id);
		return isset($this->items[$id]);
	}

	/**
	 * Max stack size, max damage and enchantability of a Java item, from the PocketMine item it converts to (null if
	 * there is none). Used without java_items.json; the values are Bedrock's, which only differ from Java's for a few
	 * items (e.g. a fishing rod's durability), and set_damage and the conversion work with ratios of them anyway.
	 * Enchantability: Java only gives it to the items an enchanting table accepts.
	 * @phpstan-return array{int, int, int}|null
	 */
	private function pocketMineItemInfo(string $id) : ?array{
		$item = LootItemConverter::toItem(new LootStack($id, 1), $this->root);
		if($item === null){
			return null;
		}
		$maxDamage = $item instanceof Durable ? $item->getMaxDurability() : 0;
		$enchantable = $item instanceof Book || ($item instanceof Durable && !isset(self::NOT_ENCHANTABLE_DURABLE[$id]));
		return [$item->getMaxStackSize(), $maxDamage, $enchantable ? $item->getEnchantability() : 0];
	}

	/**
	 * Ids of the enchantment registry in registry order (vanilla loads data-driven registries sorted by id).
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function enchantmentIds() : array{
		if($this->enchantmentIds === null){
			$ids = [];
			$files = glob($this->root . "/data/minecraft/enchantment/*.json");
			foreach($files !== false ? $files : [] as $file){
				$ids[] = "minecraft:" . basename($file, ".json");
			}
			sort($ids, SORT_STRING);
			$this->enchantmentIds = $ids;
		}
		return $this->enchantmentIds;
	}

	public function enchantment(string $id) : ?JavaEnchantment{
		$id = self::namespaced($id);
		if(array_key_exists($id, $this->enchantments)){
			return $this->enchantments[$id];
		}
		$json = $this->readJson("data/minecraft/enchantment/" . self::stripNamespace($id) . ".json");
		return $this->enchantments[$id] = is_array($json) ? new JavaEnchantment($this, $id, $json) : null;
	}

	/**
	 * Whether an item matches a holder set spec ("#tag", id or list).
	 */
	public function itemIs(string $itemId, mixed $spec) : bool{
		foreach($this->holderSet("item", $spec) as $member){
			if($member === $itemId){
				return true;
			}
		}
		return false;
	}

	public static function bool(mixed $value, bool $default) : bool{
		return is_bool($value) ? $value : $default;
	}
}
