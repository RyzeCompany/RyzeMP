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

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\SuspiciousStew;
use pocketmine\item\VanillaItems;
use function array_column;
use function array_sum;
use function count;

class ArchaeologyLootTest extends TestCase{

	public function testNormalizeTableName() : void{
		self::assertSame("desert_pyramid", ArchaeologyLoot::normalizeTableName("loot_tables/entities/desert_pyramid_archaeology.json"));
		self::assertSame("trail_ruins_rare", ArchaeologyLoot::normalizeTableName("minecraft:archaeology/trail_ruins_rare"));
		self::assertSame("ocean_ruin_warm", ArchaeologyLoot::normalizeTableName("loot_tables/entities/warm_ocean_ruins_brushable_block.json"));
	}

	public function testKnownTablesRollItems() : void{
		foreach(["desert_pyramid", "desert_well", "ocean_ruin_cold", "ocean_ruin_warm", "trail_ruins_common", "trail_ruins_rare"] as $table){
			for($i = 0; $i < 20; ++$i){
				$item = ArchaeologyLoot::roll($table);
				self::assertNotNull($item, $table);
				self::assertFalse($item->isNull(), $table);
			}
		}
		self::assertNull(ArchaeologyLoot::roll("loot_tables/chests/simple_dungeon.json"));
	}

	public function testEveryEntryResolves() : void{
		foreach(ArchaeologyLootTables::TABLES as $table => $entries){
			foreach($entries as [$name, , $auxMin, $auxMax]){
				for($aux = $auxMin; $aux <= $auxMax; ++$aux){
					$item = ArchaeologyLoot::createItem($name, $aux);
					self::assertNotNull($item, "$table: $name:$aux");
					self::assertFalse($item->isNull(), "$table: $name:$aux");
				}
			}
		}
	}

	public function testBehaviorPackWeights() : void{
		$tables = ArchaeologyLootTables::TABLES;
		self::assertSame(45, array_sum(array_column($tables["trail_ruins_common"], 1)));
		self::assertSame(12, array_sum(array_column($tables["trail_ruins_rare"], 1)));
		self::assertSame(8, array_sum(array_column($tables["desert_pyramid"], 1)));
		self::assertSame(8, array_sum(array_column($tables["desert_well"], 1)));
		self::assertSame(15, array_sum(array_column($tables["ocean_ruin_cold"], 1)));
		self::assertSame(15, array_sum(array_column($tables["ocean_ruin_warm"], 1)));
		self::assertContains(["minecraft:sniffer_egg", 1, 0, 0], $tables["ocean_ruin_warm"]);
		self::assertNotContains(["minecraft:sniffer_egg", 1, 0, 0], $tables["ocean_ruin_cold"]);
	}

	public function testFlattenedNamesKeepTheirVariant() : void{
		self::assertTrue(VanillaBlocks::SNIFFER_EGG()->asItem()->equals(ArchaeologyLoot::createItem("minecraft:sniffer_egg", 0) ?? VanillaItems::AIR()));
		self::assertTrue(VanillaBlocks::DYED_CANDLE()->setColor(DyeColor::RED)->asItem()->equals(ArchaeologyLoot::createItem("minecraft:red_candle", 0) ?? VanillaItems::AIR()));
		self::assertTrue(VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::MAGENTA)->asItem()->equals(ArchaeologyLoot::createItem("minecraft:magenta_stained_glass_pane", 0) ?? VanillaItems::AIR()));
		self::assertTrue(VanillaItems::DYE()->setColor(DyeColor::LIGHT_BLUE)->equals(ArchaeologyLoot::createItem("minecraft:light_blue_dye", 0) ?? VanillaItems::AIR()));
		//Bedrock's "clay" is the block, not the clay ball
		self::assertTrue(VanillaBlocks::CLAY()->asItem()->equals(ArchaeologyLoot::createItem("minecraft:clay", 0) ?? VanillaItems::AIR()));
		self::assertTrue(VanillaItems::LEAD()->equals(ArchaeologyLoot::createItem("minecraft:lead", 0) ?? VanillaItems::AIR()));
	}

	public function testDesertWellStewHasRandomType() : void{
		$types = [];
		for($i = 0; $i < 400; ++$i){
			$item = ArchaeologyLoot::roll("desert_well");
			if($item instanceof SuspiciousStew){
				$types[$item->getType()->name] = true;
			}
		}
		self::assertGreaterThan(2, count($types), "suspicious stew types vary (random_aux_value 0-6)");
	}
}
