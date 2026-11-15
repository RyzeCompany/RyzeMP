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

namespace pocketmine\world\redstone;

use pocketmine\block\Crafter;
use pocketmine\block\inventory\CrafterInventory;
use pocketmine\block\tile\Crafter as TileCrafter;
use pocketmine\block\utils\CrafterOrientation;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\BookCloningRecipe;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\MapCraftingRecipe;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\FilledMap;
use pocketmine\item\VanillaItems;
use pocketmine\item\WrittenBook;
use pocketmine\math\Facing;
use pocketmine\world\map\MapDataStore;
use pocketmine\world\map\MapManager;
use Symfony\Component\Filesystem\Filesystem;
use function array_filter;
use function array_values;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

class CrafterTest extends RedstoneTestCase{

	protected function setUp() : void{
		parent::setUp();
		$manager = $this->getCraftingManager();
		$manager->registerShapedRecipe(new ShapedRecipe(
			["AAA", "AAA", "AAA"],
			["A" => new ExactRecipeIngredient(VanillaItems::IRON_INGOT())],
			[VanillaBlocks::IRON()->asItem()]
		));
		$manager->registerShapedRecipe(new ShapedRecipe(
			["A", "A"],
			["A" => new ExactRecipeIngredient(VanillaBlocks::OAK_PLANKS()->asItem())],
			[VanillaItems::STICK()->setCount(4)]
		));
		$manager->registerShapelessRecipe(new ShapelessRecipe(
			[new ExactRecipeIngredient(VanillaItems::MILK_BUCKET()), new ExactRecipeIngredient(VanillaItems::SUGAR())],
			[VanillaItems::DIAMOND()],
			ShapelessRecipeType::CRAFTING
		));
	}

	private function tile() : TileCrafter{
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileCrafter::class, $tile);
		return $tile;
	}

	private function placeCrafter() : CrafterInventory{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::CRAFTER()->setOrientation(CrafterOrientation::EAST_UP));
		$this->runTicks(1);
		return $this->tile()->getInventory();
	}

	private function fire() : void{
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Crafter::CRAFT_DELAY_TICKS + 1);
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(1);
	}

	/**
	 * @return ItemEntity[]
	 */
	private function droppedItems() : array{
		return array_values(array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn()));
	}

	public function testCraftsShapedRecipeIntoContainer() : void{
		$inventory = $this->placeCrafter();
		$this->set(1, 1, 0, VanillaBlocks::CHEST());
		$this->runTicks(1);
		for($slot = 0; $slot < 9; ++$slot){
			$inventory->setItem($slot, VanillaItems::IRON_INGOT()->setCount(2));
		}
		$this->fire();

		$chest = $this->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(\pocketmine\block\tile\Chest::class, $chest);
		self::assertTrue($chest->getInventory()->getItem(0)->equals(VanillaBlocks::IRON()->asItem()));
		for($slot = 0; $slot < 9; ++$slot){
			self::assertSame(1, $inventory->getItem($slot)->getCount(), "one of each ingredient is used");
		}
	}

	public function testCraftingStateAndOutputDrop() : void{
		$inventory = $this->placeCrafter();
		//planks in slots 1 and 4 (a column): 4 sticks
		$inventory->setItem(1, VanillaBlocks::OAK_PLANKS()->asItem());
		$inventory->setItem(4, VanillaBlocks::OAK_PLANKS()->asItem());
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Crafter::CRAFT_DELAY_TICKS + 1);
		$crafter = $this->get(0, 1, 0);
		self::assertInstanceOf(Crafter::class, $crafter);
		self::assertTrue($crafter->isCrafting());
		self::assertTrue($crafter->isTriggered());

		$items = $this->droppedItems();
		self::assertCount(1, $items);
		self::assertTrue($items[0]->getItem()->equals(VanillaItems::STICK()));
		self::assertSame(4, $items[0]->getItem()->getCount());
		self::assertTrue($inventory->isSlotEmpty(1));

		$this->runTicks(Crafter::CRAFTING_STATE_TICKS + 1);
		$crafter = $this->get(0, 1, 0);
		self::assertInstanceOf(Crafter::class, $crafter);
		self::assertFalse($crafter->isCrafting());
	}

	public function testNoRecipeDoesNothing() : void{
		$inventory = $this->placeCrafter();
		$inventory->setItem(0, VanillaItems::DIAMOND());
		$this->fire();
		self::assertTrue($inventory->getItem(0)->equals(VanillaItems::DIAMOND()));
		self::assertCount(0, $this->droppedItems());
	}

	public function testBucketIsLeftOver() : void{
		$inventory = $this->placeCrafter();
		$inventory->setItem(0, VanillaItems::MILK_BUCKET());
		$inventory->setItem(5, VanillaItems::SUGAR());
		$this->fire();
		$dropped = [];
		foreach($this->droppedItems() as $entity){
			$dropped[] = $entity->getItem()->getTypeId();
		}
		self::assertContains(VanillaItems::DIAMOND()->getTypeId(), $dropped);
		self::assertContains(VanillaItems::BUCKET()->getTypeId(), $dropped);
		self::assertTrue($inventory->isSlotEmpty(0));
	}

	public function testBookCloningEjectsTheOriginal() : void{
		$this->getCraftingManager()->registerSpecialCraftingRecipe(new BookCloningRecipe());
		$inventory = $this->placeCrafter();
		$inventory->setItem(0, VanillaItems::WRITTEN_BOOK()->setTitle("Story")->setAuthor("Steve"));
		$inventory->setItem(1, VanillaItems::WRITABLE_BOOK());
		$this->fire();

		//like Java CrafterBlock: the grid is emptied and the book the recipe doesn't use up comes out after the copy
		self::assertTrue($inventory->isSlotEmpty(0));
		self::assertTrue($inventory->isSlotEmpty(1));
		$generations = [];
		foreach($this->droppedItems() as $entity){
			$item = $entity->getItem();
			self::assertInstanceOf(WrittenBook::class, $item);
			self::assertSame("Story", $item->getTitle());
			$generations[] = $item->getGeneration();
		}
		sort($generations);
		self::assertSame([WrittenBook::GENERATION_ORIGINAL, WrittenBook::GENERATION_COPY], $generations);
	}

	public function testMapExtendingCreatesTheMapData() : void{
		$path = sys_get_temp_dir() . "/pm-crafter-maps-" . uniqid();
		$manager = new MapManager(new MapDataStore($path));
		MapManager::setInstance($manager);
		try{
			$this->getCraftingManager()->registerSpecialCraftingRecipe(new MapCraftingRecipe(MapCraftingRecipe::EXTENDING));
			$source = $manager->createMap($this->world, 0, 0);
			$inventory = $this->placeCrafter();
			$newIds = [];
			for($i = 0; $i < 2; ++$i){
				for($slot = 0; $slot < 9; ++$slot){
					$inventory->setItem($slot, $slot === 4 ? VanillaItems::FILLED_MAP()->setMapId($source->getId()) : VanillaItems::PAPER());
				}
				$this->fire();
				self::assertTrue($inventory->isSlotEmpty(4));
			}
			foreach($this->droppedItems() as $entity){
				$map = $entity->getItem();
				self::assertInstanceOf(FilledMap::class, $map);
				self::assertSame(1, $map->getScale());
				$data = $manager->getMap($map->getMapId());
				self::assertNotNull($data, "the zoomed-out map has data behind its ID");
				self::assertSame(1, $data->getScale());
				$newIds[] = $map->getMapId();
			}
			self::assertCount(2, $newIds);
			self::assertNotSame($newIds[0], $newIds[1], "each craft gets its own map");
		}finally{
			MapManager::reset();
			(new Filesystem())->remove($path);
		}
	}

	public function testDisabledSlots() : void{
		$inventory = $this->placeCrafter();
		$tile = $this->tile();
		self::assertTrue($tile->setSlotDisabled(0, true));
		self::assertTrue($inventory->isSlotDisabled(0));
		self::assertSame(1, $tile->getComparatorOutput(), "disabled slots count for comparators");

		//hoppers skip disabled slots and fill the least filled slot once all are occupied
		self::assertSame(1, $tile->getInsertSlotFor(VanillaItems::IRON_INGOT()));
		for($slot = 1; $slot < 9; ++$slot){
			$inventory->setItem($slot, VanillaItems::IRON_INGOT()->setCount(3));
		}
		$inventory->setItem(6, VanillaItems::IRON_INGOT()->setCount(2));
		self::assertSame(6, $tile->getInsertSlotFor(VanillaItems::IRON_INGOT()));
		self::assertSame(9, $tile->getComparatorOutput());

		self::assertFalse($tile->setSlotDisabled(1, true), "a slot with an item can't be disabled");

		$nbt = $tile->saveNBT();
		self::assertSame(1, $nbt->getShort(TileCrafter::TAG_DISABLED_SLOTS));
	}

	public function testHopperFeedsCrafter() : void{
		$inventory = $this->placeCrafter();
		$this->tile()->setSlotDisabled(0, true);
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(1);
		$hopper = $this->world->getTileAt(0, 2, 0);
		self::assertInstanceOf(\pocketmine\block\tile\Hopper::class, $hopper);
		$hopper->getInventory()->addItem(VanillaItems::IRON_INGOT()->setCount(2));
		$this->runTicks(20);
		self::assertTrue($inventory->isSlotEmpty(0));
		self::assertSame(1, $inventory->getItem(1)->getCount());
		self::assertSame(1, $inventory->getItem(2)->getCount());
	}

	public function testOrientation() : void{
		self::assertSame(CrafterOrientation::UP_NORTH, CrafterOrientation::fromFrontAndTop(Facing::UP, Facing::NORTH));
		self::assertSame(Facing::WEST, CrafterOrientation::WEST_UP->getFront());
		self::assertSame(Facing::EAST, CrafterOrientation::DOWN_EAST->getTop());
	}
}
