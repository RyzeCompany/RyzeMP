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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\block\tile\Container;
use pocketmine\block\tile\Tile;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function array_search;
use function in_array;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Saved data of the tiles that features give to their blocks (Bedrock tile format, see the tiles in
 * pocketmine\block\tile), built from what vanilla's features put in their block entities.
 */
final class BlockEntityData{
	/** Java blocks whose block entity is a RandomizableContainer (lazy loot table), with the Bedrock tile id. */
	/**
	 * Bedrock path differences, from VanillaMine's original LootTables map.
	 * These are NBT format identifiers, not PocketMine runtime loot engines.
	 */
	private const BEDROCK_LOOT_NAMES = [
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
		"minecraft:archaeology/trail_ruins_rare" => "loot_tables/entities/trail_ruins_brushable_block_rare.json"
	];

	private const CONTAINERS = [
		"chest" => "Chest",
		"trapped_chest" => "Chest",
		"barrel" => "Barrel",
		"dispenser" => "Dispenser",
		"dropper" => "Dropper",
	];


	private static function toBedrockPath(string $javaId) : string{
		$id = "minecraft:" . WorldgenData::stripNamespace($javaId);
		return self::BEDROCK_LOOT_NAMES[$id] ?? "loot_tables/" . WorldgenData::stripNamespace($id) . ".json";
	}

	public static function toJavaLootId(string $name) : string{
		if(str_starts_with($name, "minecraft:")){
			return $name;
		}
		$javaId = array_search($name, self::BEDROCK_LOOT_NAMES, true);
		if($javaId !== false){
			return $javaId;
		}
		if(str_starts_with($name, "loot_tables/") && str_ends_with($name, ".json")){
			$name = substr($name, 12, -5);
		}
		return "minecraft:" . $name;
	}

	private function __construct(){
		//NOOP
	}

	/**
	 * Bedrock's 32-bit LootTableSeed: the low 32 bits of the Java seed.
	 */
	private static function int32(int $seed) : int{
		return ($seed & 0xffffffff) - (($seed & 0x80000000) << 1);
	}

	/**
	 * A container that fills itself from a loot table when it is first opened (RandomizableContainer).
	 */
	public static function lootContainer(string $tileId, string $javaLootTable, int $seed) : CompoundTag{
		return CompoundTag::create()
			->setString(Tile::TAG_ID, $tileId)
			->setString(Container::TAG_LOOT_TABLE, self::toBedrockPath($javaLootTable))
			->setInt(Container::TAG_LOOT_TABLE_SEED, self::int32($seed))
			->setLong(Container::TAG_LOOT_TABLE_SEED_LONG, $seed);
	}

	/**
	 * A monster spawner of the given mob ("minecraft:zombie").
	 */
	public static function spawner(string $entityId) : CompoundTag{
		return CompoundTag::create()
			->setString(Tile::TAG_ID, "MobSpawner")
			->setString("EntityIdentifier", $entityId);
	}

	/**
	 * A bee nest or beehive holding bees that have been inside for the given numbers of ticks (vanilla's occupants with
	 * ticks_in_hive, which leave after more than 600 ticks inside).
	 *
	 * @param int[] $ticksInHive
	 * @phpstan-param list<int> $ticksInHive
	 */
	public static function beehive(array $ticksInHive) : CompoundTag{
		$occupants = [];
		foreach($ticksInHive as $ticks){
			$occupants[] = CompoundTag::create()
				->setString("ActorIdentifier", "minecraft:bee")
				->setTag("SaveData", CompoundTag::create()->setString("identifier", "minecraft:bee"))
				->setInt("TicksLeftToStay", 600 - $ticks + 1);
		}
		return CompoundTag::create()
			->setString(Tile::TAG_ID, "Beehive")
			->setTag("Occupants", new ListTag($occupants, NBT::TAG_Compound))
			->setByte("ShouldSpawnBees", 0);
	}

	/**
	 * Suspicious sand or gravel that uncovers an item of a loot table when brushed.
	 */
	public static function brushable(string $javaLootTable, int $seed) : CompoundTag{
		return CompoundTag::create()
			->setString(Tile::TAG_ID, "BrushableBlock")
			->setString("LootTable", self::toBedrockPath($javaLootTable))
			->setInt("LootTableSeed", self::int32($seed))
			->setLong("LootTableSeedLong", $seed);
	}

	/**
	 * Whether a Java block's block entity is a RandomizableContainer (StructureTemplate.placeInWorld() gives those a
	 * new loot seed from the placement random).
	 */
	public static function isRandomizableContainer(string $javaBlock) : bool{
		$name = WorldgenData::stripNamespace($javaBlock);
		return isset(self::CONTAINERS[$name]) || $name === "hopper" || $name === "decorated_pot" || str_ends_with($name, "shulker_box");
	}

	/**
	 * A decorated pot with the given sherds (item ids, [back, left, right, front], "minecraft:brick" for a plain side)
	 * and, if any, the loot table of its item (rolled when the item is first needed, like a container's).
	 *
	 * @param string[] $sherds
	 * @phpstan-param list<string> $sherds
	 */
	public static function decoratedPot(array $sherds, ?string $javaLootTable, int $seed) : CompoundTag{
		$nbt = CompoundTag::create()->setString(Tile::TAG_ID, "DecoratedPot");
		if(count($sherds) > 0){
			$list = [];
			foreach($sherds as $sherd){
				$list[] = new StringTag($sherd);
			}
			$nbt->setTag("sherds", new ListTag($list, NBT::TAG_String));
		}
		if($javaLootTable !== null){
			$nbt->setString("LootTable", self::toBedrockPath($javaLootTable))
				->setInt("LootTableSeed", self::int32($seed))
				->setLong("LootTableSeedLong", $seed);
		}
		return $nbt;
	}

	/**
	 * Sherds of a Java decorated pot as item ids, [back, left, right, front]: Java 26 saves a compound of the sides
	 * ({"back": {"id": ...}, ...}, missing sides are plain), older versions a list of ids.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function potSherds(CompoundTag $nbt) : array{
		$tag = $nbt->getTag("sherds");
		$sherds = [];
		if($tag instanceof CompoundTag){
			foreach(["back", "left", "right", "front"] as $side){
				$sherds[] = $tag->getCompoundTag($side)?->getString("id", "minecraft:brick") ?? "minecraft:brick";
			}
		}elseif($tag instanceof ListTag){
			foreach($tag as $sherd){
				if($sherd instanceof StringTag){
					$sherds[] = $sherd->getValue();
				}
			}
		}
		return $sherds;
	}

	/**
	 * Converts the block entity data of a structure template block (Java format, after the structure processors) to
	 * Bedrock tile data, or returns null if it is not supported (only loot tables of containers, brushable blocks and
	 * decorated pots, and the sherds of decorated pots are; other data is dropped).
	 */
	public static function fromJava(string $javaBlock, CompoundTag $nbt) : ?CompoundTag{
		$name = WorldgenData::stripNamespace($javaBlock);
		$table = $nbt->getTag("LootTable");
		$seedTag = $nbt->getTag("LootTableSeed");
		$seed = $seedTag instanceof LongTag ? $seedTag->getValue() : 0;
		if($name === "decorated_pot"){
			return self::decoratedPot(self::potSherds($nbt), $table instanceof StringTag && $table->getValue() !== "" ? $table->getValue() : null, $seed);
		}
		if(!$table instanceof StringTag){
			return null;
		}
		if(isset(self::CONTAINERS[$name])){
			return self::lootContainer(self::CONTAINERS[$name], $table->getValue(), $seed);
		}
		if(in_array($name, ["suspicious_sand", "suspicious_gravel"], true)){
			return self::brushable($table->getValue(), $seed)->setString("type", "minecraft:" . $name);
		}
		return null;
	}
}
