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

namespace pocketmine\block\utils;

use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\loot\LootTables;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function basename;
use function mt_rand;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Loot for suspicious sand and gravel: the Bedrock brushable block loot tables (generated into
 * {@link ArchaeologyLootTables} by tools/generate-archaeology-loot.php). Each brushing gives one item with the vanilla
 * weights.
 */
final class ArchaeologyLoot{

	/**
	 * @var Item[] "name:aux" => item prototype
	 * @phpstan-var array<string, Item>
	 */
	private static array $items = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the table key ("desert_pyramid", "trail_ruins_rare"...) of a vanilla loot table path such as
	 * "loot_tables/entities/desert_pyramid_archaeology.json" or "minecraft:archaeology/desert_pyramid".
	 */
	public static function normalizeTableName(string $lootTable) : string{
		$javaId = LootTables::toJavaId($lootTable);
		if(str_starts_with($javaId, "minecraft:archaeology/")){
			//also Bedrock's names, e.g. "loot_tables/entities/desert_well_brushable_block.json"
			return substr($javaId, 22);
		}
		$name = strtolower(basename($lootTable));
		if(str_ends_with($name, ".json")){
			$name = substr($name, 0, -5);
		}
		if(str_ends_with($name, "_archaeology")){
			$name = substr($name, 0, -12);
		}
		return $name;
	}

	/**
	 * Returns the item of a loot table entry, by Bedrock item name and aux value, or null if PocketMine doesn't know
	 * it.
	 *
	 * @internal
	 */
	public static function createItem(string $name, int $aux) : ?Item{
		$key = $name . ":" . $aux;
		if(!isset(self::$items[$key])){
			try{
				$item = GlobalItemDataHandlers::getDeserializer()->deserializeStack(GlobalItemDataHandlers::getUpgrader()->upgradeItemTypeDataString($name, $aux, 1, null));
			}catch(ItemTypeDeserializeException | SavedDataLoadingException){
				//block items with flattened names (red_candle, magenta_stained_glass_pane, sniffer_egg) have no item
				//deserializer of their own
				$item = $aux === 0 ? StringToItemParser::getInstance()->parse($name) : null;
			}
			if($item === null){
				return null;
			}
			self::$items[$key] = $item;
		}
		return clone self::$items[$key];
	}

	/**
	 * Rolls one item from the given vanilla archaeology loot table, or returns null if the table is unknown.
	 */
	public static function roll(string $lootTable) : ?Item{
		$entries = ArchaeologyLootTables::TABLES[self::normalizeTableName($lootTable)] ?? [];
		$total = 0;
		foreach($entries as [, $entryWeight, , ]){
			$total += $entryWeight;
		}
		if($total === 0){
			return null;
		}
		$pick = mt_rand(1, $total);
		foreach($entries as [$name, $weight, $auxMin, $auxMax]){
			$pick -= $weight;
			if($pick <= 0){
				return self::createItem($name, $auxMin === $auxMax ? $auxMin : mt_rand($auxMin, $auxMax));
			}
		}
		return null;
	}
}
