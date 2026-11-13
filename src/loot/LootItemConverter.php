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

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\bedrock\item\UnsupportedItemTypeException;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\GoatHorn;
use pocketmine\item\GoatHornType;
use pocketmine\item\Item;
use pocketmine\item\Potion;
use pocketmine\item\PotionType;
use pocketmine\item\SplashPotion;
use pocketmine\item\StringToItemParser;
use pocketmine\item\SuspiciousStew;
use pocketmine\item\SuspiciousStewType;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_key_exists;
use function constant;
use function count;
use function defined;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function min;
use function str_ends_with;
use function strtoupper;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Converts loot stacks (Java item ids and components) to PocketMine items, using GeyserMC's Java -> Bedrock item
 * mappings from the vanilla data cache. Stacks of items PocketMine does not have are dropped (null).
 */
final class LootItemConverter{
	/**
	 * Java enchantment ids whose Bedrock name differs.
	 */
	private const ENCHANTMENT_ALIASES = [
		"binding_curse" => "binding",
		"vanishing_curse" => "vanishing",
	];
	/**
	 * Bedrock suspicious stew type giving each effect.
	 */
	private const STEW_TYPES = [
		"minecraft:night_vision" => "POPPY",
		"minecraft:jump_boost" => "CORNFLOWER",
		"minecraft:weakness" => "TULIP",
		"minecraft:blindness" => "AZURE_BLUET",
		"minecraft:poison" => "LILY_OF_THE_VALLEY",
		"minecraft:saturation" => "DANDELION",
		"minecraft:fire_resistance" => "ALLIUM",
		"minecraft:regeneration" => "OXEYE_DAISY",
		"minecraft:wither" => "WITHER_ROSE",
		"minecraft:nausea" => "CLOSED_EYEBLOSSOM",
	];

	/**
	 * @var mixed[][]|null
	 * @phpstan-var array<string, array{string, int}>|null
	 */
	private static ?array $mappings = null;
	private static ?string $mappingsRoot = null;
	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private static array $missing = [];
	/**
	 * @var Enchantment[]|null[]
	 * @phpstan-var array<string, Enchantment|null>
	 */
	private static array $enchantments = [];
	/**
	 * @var Item[]|null
	 * @phpstan-var array<string, Item>|null
	 */
	private static ?array $knownItems = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Bedrock item id and meta of a Java item.
	 * @phpstan-return array{string, int}
	 */
	public static function bedrockItem(string $javaId, string $root) : array{
		if(self::$mappings === null || self::$mappingsRoot !== $root){
			self::$mappingsRoot = $root;
			self::$mappings = [];
			$path = $root . "/" . LootData::GEYSER_ITEMS_FILE;
			if(is_file($path)){
				$json = json_decode(Filesystem::fileGetContents($path), true, 8, JSON_THROW_ON_ERROR);
				if(is_array($json)){
					foreach(Utils::promoteKeys($json) as $java => $entry){
						if(is_array($entry) && is_string($entry["bedrock_identifier"] ?? null)){
							self::$mappings[(string) $java] = [$entry["bedrock_identifier"], is_int($entry["bedrock_data"] ?? null) ? $entry["bedrock_data"] : 0];
						}
					}
				}
			}
		}
		return self::$mappings[$javaId] ?? [$javaId, 0];
	}

	/**
	 * The PocketMine enchantment of a Java enchantment id, or null if PocketMine does not have it.
	 */
	public static function enchantment(string $javaId) : ?Enchantment{
		if(array_key_exists($javaId, self::$enchantments)){
			return self::$enchantments[$javaId];
		}
		$name = LootData::stripNamespace($javaId);
		$name = self::ENCHANTMENT_ALIASES[$name] ?? $name;
		$constant = "pocketmine\\data\\bedrock\\EnchantmentIds::" . strtoupper($name);
		$id = defined($constant) ? constant($constant) : null;
		$enchantment = is_int($id) ? EnchantmentIdMap::getInstance()->fromId($id) : null;
		return self::$enchantments[$javaId] = $enchantment ?? StringToEnchantmentParser::getInstance()->parse($name);
	}

	/**
	 * Level of a Java enchantment on a PocketMine item.
	 */
	public static function enchantmentLevel(Item $item, string $javaEnchantmentId) : int{
		$enchantment = self::enchantment($javaEnchantmentId);
		return $enchantment !== null ? $item->getEnchantmentLevel($enchantment) : 0;
	}

	/**
	 * Converts a loot stack to a PocketMine item, or returns null if PocketMine has no such item (or the stack is
	 * empty).
	 */
	public static function toItem(LootStack $stack, string $dataRoot) : ?Item{
		if($stack->isEmpty()){
			return null;
		}
		[$bedrockId, $meta] = self::bedrockItem($stack->id, $dataRoot);
		$item = self::bedrockToItem($bedrockId, $meta) ?? StringToItemParser::getInstance()->parse(LootData::stripNamespace($stack->id));
		if($item === null || $item->isNull()){
			if(!isset(self::$missing[$stack->id])){
				self::$missing[$stack->id] = true;
				\GlobalLogger::get()->debug("[Loot] No PocketMine item for $stack->id ($bedrockId:$meta), dropped");
			}
			return null;
		}
		$item->setCount($stack->count);
		if($item instanceof Durable && $stack->damage > 0){
			$item->setDamage(min($stack->damage, $item->getMaxDurability()));
		}
		foreach(Utils::stringifyKeys($stack->enchantments) as $id => $level){
			$enchantment = self::enchantment($id);
			if($enchantment !== null){
				$item->addEnchantment(new EnchantmentInstance($enchantment, $level));
			}
		}
		if($stack->customName !== null){
			$item->setCustomName($stack->customName);
		}
		if($stack->potion !== null && ($item instanceof Potion || $item instanceof SplashPotion)){
			$type = self::potionType($stack->potion);
			if($type !== null){
				$item->setType($type);
			}
		}
		if($item instanceof SuspiciousStew && isset($stack->stewEffects[0])){
			$name = self::STEW_TYPES[$stack->stewEffects[0][0]] ?? null;
			$type = $name !== null && defined(SuspiciousStewType::class . "::" . $name) ? constant(SuspiciousStewType::class . "::" . $name) : null;
			if($type instanceof SuspiciousStewType){
				$item->setType($type);
			}
		}
		if($item instanceof GoatHorn && $stack->instrument !== null){
			$name = strtoupper(LootData::stripNamespace($stack->instrument));
			if(str_ends_with($name, "_GOAT_HORN")){
				$name = substr($name, 0, -10);
			}
			$type = defined(GoatHornType::class . "::" . $name) ? constant(GoatHornType::class . "::" . $name) : null;
			if($type instanceof GoatHornType){
				$item->setHornType($type);
			}
		}
		return $item;
	}

	/**
	 * A PocketMine item from a Bedrock item id and meta, or null if PocketMine has no such item.
	 *
	 * Ordinary items are deserialized directly. Block items cannot be: on disk and on the network they carry their
	 * block state, which the mappings do not have (and GeyserMC's meta of a block item is its legacy one). Those are
	 * looked up in a table of the item ids PocketMine serializes its own blocks and items to.
	 */
	public static function bedrockToItem(string $bedrockId, int $meta) : ?Item{
		//current ids first: the upgrader loads every item upgrade schema (~50 MB peak, ~20 MB kept), too much for a
		//generator worker that only converts a dispenser's water bucket
		try{
			$item = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($bedrockId, $meta, null));
			if(!$item->isNull()){
				return $item;
			}
		}catch(ItemTypeDeserializeException|UnsupportedItemTypeException|BlockStateDeserializeException|\InvalidArgumentException){
			//legacy id/meta, block item or unknown item
		}
		try{
			$data = GlobalItemDataHandlers::getUpgrader()->upgradeItemTypeDataString($bedrockId, $meta, 1, null);
			$item = GlobalItemDataHandlers::getDeserializer()->deserializeStack($data);
			if(!$item->isNull()){
				return $item;
			}
		}catch(ItemTypeDeserializeException|UnsupportedItemTypeException|BlockStateDeserializeException|\InvalidArgumentException){
			//block item or unknown item
		}
		$known = self::knownItems();
		$item = $known[$bedrockId . ":" . $meta] ?? $known[$bedrockId] ?? null;
		return $item !== null ? clone $item : null;
	}

	/**
	 * Every block and item PocketMine has, by the Bedrock item id (and "id:meta") it serializes to.
	 * @return Item[]
	 * @phpstan-return array<string, Item>
	 */
	private static function knownItems() : array{
		if(self::$knownItems !== null){
			return self::$knownItems;
		}
		$serializer = GlobalItemDataHandlers::getSerializer();
		$known = [];
		$add = function(Item $item) use ($serializer, &$known) : void{
			if($item->isNull()){
				return;
			}
			try{
				$data = $serializer->serializeType($item);
			}catch(ItemTypeSerializeException){
				return;
			}
			$known[$data->getName() . ":" . $data->getMeta()] ??= $item;
			$known[$data->getName()] ??= $item;
		};
		//items first: e.g. a sign item over the wall sign block that has the same item
		foreach(VanillaItems::getAll() as $item){
			$add($item);
		}
		foreach(VanillaBlocks::getAll() as $block){
			$add($block->asItem());
		}
		//blocks whose item depends on the state (colors, wood types, copper oxidation...)
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $block){
			$add($block->asItem());
		}
		return self::$knownItems = $known;
	}

	public static function potionType(string $javaPotion) : ?PotionType{
		$constant = PotionType::class . "::" . strtoupper(LootData::stripNamespace($javaPotion));
		$type = defined($constant) ? constant($constant) : null;
		return $type instanceof PotionType ? $type : null;
	}
}
