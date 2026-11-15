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

namespace pocketmine\inventory;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\inventory\json\CreativeGroupData;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Translatable;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function count;

class CreativeInventoryTest extends TestCase{

	/**
	 * @return string[] the Bedrock item ID of every entry
	 * @phpstan-return list<string>
	 */
	private static function entryIds(CreativeInventory $inventory) : array{
		$serializer = GlobalItemDataHandlers::getSerializer();
		$ids = [];
		foreach($inventory->getAllEntries() as $entry){
			$ids[] = $serializer->serializeType($entry->getItem())->getName();
		}
		return $ids;
	}

	/**
	 * Counts the entries of the vanilla creative data: all of them, and those that need the Education Edition option.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	private static function countDataEntries() : array{
		$total = 0;
		$education = 0;
		foreach(["construction", "nature", "equipment", "items"] as $category){
			$groups = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(Path::join(BedrockDataFiles::CREATIVE, $category . ".json"), CreativeGroupData::class);
			foreach($groups as $group){
				$total += count($group->items);
				foreach($group->items as $itemStack){
					if(EducationCreativeFilter::isEducationGroup($group->group_name) || EducationCreativeFilter::isEducationItem($itemStack->name)){
						$education++;
					}
				}
			}
		}
		return [$total, $education];
	}

	public function testEveryVanillaEntryLoads() : void{
		[$total, ] = self::countDataEntries();
		self::assertCount($total, CreativeInventory::createVanilla(true)->getAllEntries(), "no creative entry should be skipped");
	}

	public function testEducationItemsAreHiddenByDefault() : void{
		[$total, $education] = self::countDataEntries();
		self::assertGreaterThan(200, $education);

		$inventory = CreativeInventory::createVanilla(false);
		self::assertCount($total - $education, $inventory->getAllEntries());
		foreach(self::entryIds($inventory) as $id){
			self::assertFalse(EducationCreativeFilter::isEducationItem($id), "$id is Education Edition content");
		}
		foreach($inventory->getAllEntries() as $entry){
			$group = $entry->getGroup();
			if($group !== null){
				$name = $group->getName();
				self::assertFalse(EducationCreativeFilter::isEducationGroup($name instanceof Translatable ? $name->getText() : $name));
			}
		}

		self::assertFalse($inventory->contains(VanillaItems::BALLOON()));
		self::assertFalse($inventory->contains(VanillaItems::CHEMICAL_SALT()));
		self::assertFalse($inventory->contains(VanillaItems::BLEACH()));
		//vanilla content that looks similar stays
		self::assertTrue($inventory->contains(VanillaItems::BONE_MEAL()));
		self::assertTrue($inventory->contains(VanillaItems::ARROW()));
	}

	public function testEducationItemsCanBeEnabled() : void{
		$inventory = CreativeInventory::createVanilla(true);
		self::assertTrue($inventory->contains(VanillaItems::BALLOON()));
		self::assertTrue($inventory->contains(VanillaItems::CHEMICAL_SALT()));
		self::assertTrue($inventory->contains(VanillaItems::RAPID_FERTILIZER()));
	}

	/**
	 * The default instance (used when the server doesn't enable the option) is the vanilla one without education items.
	 */
	public function testDefaultInstanceHidesEducationItems() : void{
		CreativeInventory::reset();
		try{
			self::assertFalse(CreativeInventory::getInstance()->contains(VanillaItems::BALLOON()));
		}finally{
			CreativeInventory::reset();
		}
	}

	public function testEducationItemIds() : void{
		self::assertTrue(EducationCreativeFilter::isEducationItem("minecraft:element_118"));
		self::assertTrue(EducationCreativeFilter::isEducationItem("minecraft:hard_light_blue_stained_glass_pane"));
		self::assertTrue(EducationCreativeFilter::isEducationItem("minecraft:colored_torch_red"));
		self::assertTrue(EducationCreativeFilter::isEducationItem("minecraft:underwater_tnt"));
		self::assertFalse(EducationCreativeFilter::isEducationItem("minecraft:hardened_clay"));
		self::assertFalse(EducationCreativeFilter::isEducationItem("minecraft:torch"));
		self::assertFalse(EducationCreativeFilter::isEducationItem("minecraft:tnt"));
	}

	public function testOrderIsVanilla() : void{
		$ids = self::entryIds(CreativeInventory::createVanilla(false));
		self::assertSame("minecraft:oak_planks", $ids[0]);
		$items = array_map(fn(CreativeInventoryEntry $e) : Item => $e->getItem(), CreativeInventory::createVanilla(false)->getAllEntries());
		self::assertTrue($items[0]->equals(VanillaBlocks::OAK_PLANKS()->asItem()));
	}
}
