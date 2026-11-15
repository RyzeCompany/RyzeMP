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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\json\CreativeGroupData;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function json_decode;
use function mb_strtoupper;
use function str_ends_with;
use const JSON_THROW_ON_ERROR;

class SpawnEggTest extends TestCase{

	public function testEveryListedEggRoundTrips() : void{
		$all = VanillaItems::getAll();
		$entityIds = json_decode(Filesystem::fileGetContents(BedrockDataFiles::ENTITY_ID_MAP_JSON), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($entityIds);
		foreach(SpawnEggList::EGGS as $name => [$entityTypeId, $displayName]){
			$id = "minecraft:" . $name . "_spawn_egg";
			$egg = $all[mb_strtoupper($name . "_spawn_egg")] ?? null;
			self::assertInstanceOf(GenericSpawnEgg::class, $egg, $name);
			self::assertSame($entityTypeId, $egg->getEntityTypeId());
			self::assertSame($displayName . " Spawn Egg", $egg->getVanillaName());
			self::assertArrayHasKey($entityTypeId, $entityIds, "Unknown entity ID $entityTypeId");

			self::assertSame($id, GlobalItemDataHandlers::getSerializer()->serializeType($egg)->getName());
			$back = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($id));
			self::assertTrue($back->equals($egg), $name);
			self::assertTrue(StringToItemParser::getInstance()->parse($name . "_spawn_egg")?->equals($egg) ?? false, $name);
		}
	}

	public function testEggsWithEntitiesKnowTheirMob() : void{
		self::assertSame("minecraft:zombie", VanillaItems::ZOMBIE_SPAWN_EGG()->getEntityTypeId());
		self::assertSame("minecraft:squid", VanillaItems::SQUID_SPAWN_EGG()->getEntityTypeId());
		self::assertSame("minecraft:villager_v2", VanillaItems::VILLAGER_SPAWN_EGG()->getEntityTypeId(), "the egg spawns the current villager");
	}

	/**
	 * Every spawn egg of the vanilla creative inventory must be known (Education Edition eggs are not in it).
	 */
	public function testAllCreativeEggsAreKnown() : void{
		$groups = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(BedrockDataFiles::CREATIVE . "/nature.json", CreativeGroupData::class);
		$count = 0;
		foreach($groups as $group){
			foreach($group->items as $entry){
				if(!str_ends_with($entry->name, "_spawn_egg")){
					continue;
				}
				$count++;
				self::assertNotNull(CraftingManagerFromDataHelper::deserializeItemStack($entry), $entry->name);
			}
		}
		self::assertGreaterThan(80, $count);
	}
}
