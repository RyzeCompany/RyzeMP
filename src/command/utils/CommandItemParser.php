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

namespace pocketmine\command\utils;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\JsonNbtParser;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\NbtException;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function sort;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strval;
use function substr;
use function trim;
use const SORT_STRING;

/**
 * Parses item arguments of commands: PocketMine's item names (StringToItemParser), the vanilla Bedrock item IDs the
 * server supports (with or without "minecraft:", e.g. white_terracotta or cut_copper, which have no item name of their
 * own) and legacy IDs (e.g. 35:14).
 */
final class CommandItemParser{
	private const ITEM_LOCK_TAG = "minecraft:item_lock";
	private const ITEM_LOCK_MODES = ["lock_in_slot" => 1, "lock_in_inventory" => 2];

	/**
	 * Vanilla item IDs (without minecraft:) mapped to an item of that type.
	 * @var Item[]|null
	 * @phpstan-var array<string, Item>|null
	 */
	private static ?array $vanillaItems = null;
	/**
	 * @var string[]|null
	 * @phpstan-var list<string>|null
	 */
	private static ?array $vanillaIds = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @throws LegacyStringToItemParserException if the item is unknown
	 */
	public static function parse(string $input) : Item{
		$item = StringToItemParser::getInstance()->parse($input);
		if($item !== null){
			return $item;
		}
		$key = strtolower(str_replace(" ", "_", trim($input)));
		if(str_starts_with($key, "minecraft:")){
			$key = substr($key, 10);
		}
		$vanilla = self::getVanillaItems()[$key] ?? null;
		if($vanilla !== null){
			return clone $vanilla;
		}
		return LegacyStringToItemParser::getInstance()->parse($input);
	}

	/**
	 * Applies a Bedrock data value (the [data] argument of /give and /clear, e.g. the potion type) to the item, like
	 * vanilla does for items whose ID takes metadata. Returns null if the item has no variant with that data value.
	 * Data 0 and block items are returned unchanged.
	 */
	public static function withData(Item $item, int $data) : ?Item{
		if($data === 0){
			return $item;
		}
		try{
			$saved = GlobalItemDataHandlers::getSerializer()->serializeType($item);
			if($saved->getBlock() !== null){
				return $item;
			}
			$result = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($saved->getName(), $data));
		}catch(ItemTypeSerializeException|ItemTypeDeserializeException){
			return null;
		}
		$result->setCount($item->getCount());
		return $result;
	}

	/**
	 * Vanilla item IDs (without minecraft:) of every item type the server can create from a name, sorted. All of them
	 * are accepted by {@link parse()}.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getVanillaIds() : array{
		if(self::$vanillaIds === null){
			//enum values must be plain names: one like "item.straw_bed" (an experimental item without a name of its own)
			//can make the client drop the commands that use the enum, with all their suggestions
			$ids = array_values(array_filter(array_map(strval(...), array_keys(self::getVanillaItems())), fn(string $id) => preg_match('/^[a-z0-9_]+$/', $id) === 1));
			sort($ids, SORT_STRING);
			self::$vanillaIds = $ids;
		}
		return self::$vanillaIds;
	}

	/**
	 * @return Item[]
	 * @phpstan-return array<string, Item>
	 */
	private static function getVanillaItems() : array{
		if(self::$vanillaItems === null){
			$parser = StringToItemParser::getInstance();
			$serializer = GlobalItemDataHandlers::getSerializer();
			$items = [];
			$metas = [];
			//item => whether it only counts if it is in the creative inventory
			$candidates = function() use ($parser) : \Generator{
				foreach($parser->getKnownAliases() as $alias){
					yield $parser->parse((string) $alias) => false;
				}
				//types without an item name of their own (mangrove boat...): every registered item
				foreach(VanillaItems::getAll() as $item){
					yield $item => false;
				}
				//block variants without one (wet sponge, dead corals...), leaving out technical blocks (moving block, end
				//portal, candle cakes...) that are not in the creative inventory
				foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $block){
					yield $block->asItem() => true;
				}
			};
			$creative = CreativeInventory::getInstance();
			foreach($candidates() as $item => $creativeOnly){
				if($item === null || $item->isNull()){
					continue;
				}
				try{
					$data = $serializer->serializeType($item);
				}catch(ItemTypeSerializeException){
					continue;
				}
				$id = $data->getName();
				if(str_starts_with($id, "minecraft:")){
					$id = substr($id, 10);
				}
				if($creativeOnly && (isset($items[$id]) || !$creative->contains($item))){
					continue;
				}
				//several types may share an ID with different metadata (e.g. potions): prefer the one without metadata
				if(!isset($items[$id]) || ($metas[$id] !== 0 && $data->getMeta() === 0)){
					$items[$id] = $item;
					$metas[$id] = $data->getMeta();
				}
			}
			self::$vanillaItems = $items;
		}
		return self::$vanillaItems;
	}

	/**
	 * Applies Bedrock item components ({"minecraft:can_destroy":{"blocks":["stone"]}}, minecraft:can_place_on,
	 * minecraft:keep_on_death, minecraft:item_lock) or PocketMine JSON NBT tags, as /give and /replaceitem accept them.
	 * Returns an error message on failure.
	 */
	public static function applyTags(Item $item, string $json) : ?string{
		$components = json_decode(trim($json), true);
		if(is_array($components) && count($components) > 0 && self::isComponentList($components)){
			return self::applyComponents($item, $components);
		}
		try{
			$item->setNamedTag(JsonNbtParser::parseJson($json));
		}catch(NbtDataException|NbtException $e){
			return $e->getMessage();
		}
		return null;
	}

	/**
	 * @param mixed[] $components
	 */
	private static function isComponentList(array $components) : bool{
		foreach(Utils::promoteKeys($components) as $name => $value){
			if(!is_string($name) || !str_starts_with($name, "minecraft:")){
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed[] $components
	 */
	private static function applyComponents(Item $item, array $components) : ?string{
		foreach(Utils::promoteKeys($components) as $name => $value){
			switch($name){
				case "minecraft:can_place_on":
				case "minecraft:can_destroy":
					$blocks = is_array($value) ? ($value["blocks"] ?? null) : null;
					if(!is_array($blocks)){
						return "$name needs a \"blocks\" list";
					}
					$names = [];
					foreach(Utils::promoteKeys($blocks) as $block){
						if(!is_string($block)){
							return "$name needs a \"blocks\" list";
						}
						$names[] = $block;
					}
					if($name === "minecraft:can_place_on"){
						$item->setCanPlaceOn($names);
					}else{
						$item->setCanDestroy($names);
					}
					break;
				case "minecraft:keep_on_death":
					$item->setKeepOnDeath(true);
					break;
				case self::ITEM_LOCK_TAG:
					$mode = is_array($value) ? ($value["mode"] ?? null) : null;
					if(!is_string($mode) || !isset(self::ITEM_LOCK_MODES[$mode])){
						return "$name needs a \"mode\" (lock_in_slot or lock_in_inventory)";
					}
					$tag = $item->getNamedTag();
					$tag->setByte(self::ITEM_LOCK_TAG, self::ITEM_LOCK_MODES[$mode]);
					$item->setNamedTag($tag);
					break;
				default:
					return "Unknown component $name";
			}
		}
		return null;
	}
}
