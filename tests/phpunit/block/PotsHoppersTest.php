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

namespace pocketmine\block;

use pocketmine\block\tile\ChiseledBookshelf as TileChiseledBookshelf;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\block\tile\Jukebox as TileJukebox;
use pocketmine\block\tile\Shelf as TileShelf;
use pocketmine\block\utils\ChiseledBookshelfSlot;
use pocketmine\block\utils\CrafterOrientation;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\data\bedrock\block\BlockLegacyMetadata;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\vehicle\MinecartChest;
use pocketmine\entity\vehicle\MinecartType;
use pocketmine\inventory\Inventory;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Record;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_map;

/**
 * Hoppers, droppers and crafters with decorated pots, jukeboxes, chiseled bookshelves and container minecarts, the
 * decorated pot shatter list and the comparator readings of pots, copper golem statues and shelves.
 */
class PotsHoppersTest extends RedstoneTestCase{

	private function hopperInventory(int $x, int $y, int $z) : Inventory{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(Container::class, $tile);
		return $tile->getInventory();
	}

	private function potTile(int $x, int $y, int $z) : TileDecoratedPot{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(TileDecoratedPot::class, $tile);
		return $tile;
	}

	private static function countItems(Inventory $inventory) : int{
		$count = 0;
		foreach($inventory->getContents() as $item){
			$count += $item->getCount();
		}
		return $count;
	}

	public function testHopperFillsPotWithOneItemType() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::DECORATED_POT());
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(2);
		$hopper = $this->hopperInventory(0, 2, 0);
		$hopper->setItem(0, VanillaItems::DIAMOND()->setCount(2));
		$hopper->setItem(1, VanillaItems::STICK());
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 4);

		$stored = $this->potTile(0, 1, 0)->getItem();
		self::assertNotNull($stored);
		self::assertSame(ItemTypeIds::DIAMOND, $stored->getTypeId());
		self::assertSame(2, $stored->getCount());
		self::assertSame(1, self::countItems($hopper), "the stick does not go into a pot holding diamonds");
		self::assertSame(ItemTypeIds::STICK, $hopper->getItem(1)->getTypeId());

		$pot = $this->get(0, 1, 0);
		self::assertInstanceOf(DecoratedPot::class, $pot);
		self::assertSame(1, $pot->getComparatorOutput(), "2 of 64: 1 + floor(14 * 2 / 64)");
	}

	public function testPotStackLimit() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::DECORATED_POT());
		$this->runTicks(1);
		$this->potTile(0, 1, 0)->setItem(VanillaItems::EGG()->setCount(16));
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(1);
		$hopper = $this->hopperInventory(0, 2, 0);
		$hopper->setItem(0, VanillaItems::EGG());
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 2);
		self::assertSame(1, self::countItems($hopper), "a full pot (16 eggs) takes no more");
		self::assertSame(16, $this->potTile(0, 1, 0)->getItem()?->getCount());
		$pot = $this->get(0, 1, 0);
		self::assertInstanceOf(DecoratedPot::class, $pot);
		self::assertSame(15, $pot->getComparatorOutput());
	}

	public function testHopperPullsFromPot() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::NORTH));
		$this->set(0, 2, 0, VanillaBlocks::DECORATED_POT());
		$this->runTicks(2);
		//a player puts an item in: the idle hopper below wakes up
		$item = VanillaItems::DIAMOND()->setCount(3);
		$this->get(0, 2, 0)->onInteract($item, Facing::UP, new Vector3(0.5, 1, 0.5));
		$this->runTicks(2);
		self::assertSame(1, self::countItems($this->hopperInventory(0, 1, 0)));
		self::assertNull($this->potTile(0, 2, 0)->getItem());
	}

	public function testHopperPutsDiscIntoJukebox() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::JUKEBOX());
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(2);
		$hopper = $this->hopperInventory(0, 2, 0);
		$hopper->setItem(0, VanillaItems::RECORD_13());
		$hopper->setItem(1, VanillaItems::RECORD_CAT());
		$hopper->setItem(2, VanillaItems::DIAMOND());
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 3);

		$jukebox = $this->get(0, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		self::assertNotNull($jukebox->getRecord());
		self::assertTrue($jukebox->isPlaying(), "a disc put in by a hopper plays");
		self::assertSame(2, self::countItems($hopper), "the second disc and the diamond stay in the hopper");
		self::assertGreaterThan(0, $jukebox->getComparatorOutput());
	}

	public function testHopperTakesDiscOutOfSilentJukebox() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 2, 0, VanillaBlocks::JUKEBOX());
		$this->runTicks(1);
		$tile = $this->world->getTileAt(0, 2, 0);
		self::assertInstanceOf(TileJukebox::class, $tile);
		$record = VanillaItems::RECORD_13();
		$tile->setRecord($record); //the track is over (not playing): the jukebox doesn't power the hopper
		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::NORTH));
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS + 2);

		$hopper = $this->hopperInventory(0, 1, 0);
		self::assertSame(1, self::countItems($hopper));
		self::assertInstanceOf(Record::class, $hopper->getItem(0));
		$jukebox = $this->get(0, 2, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		self::assertNull($jukebox->getRecord());
	}

	public function testPlayingJukeboxLocksHopperBelow() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 2, 0, VanillaBlocks::JUKEBOX());
		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::NORTH));
		$this->runTicks(1);
		$jukebox = $this->get(0, 2, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		$jukebox->insertRecord(VanillaItems::RECORD_13());
		$this->world->setBlock($jukebox->getPosition(), $jukebox);
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 2);
		self::assertSame(0, self::countItems($this->hopperInventory(0, 1, 0)), "the playing jukebox powers (locks) the hopper");
	}

	public function testHopperMovesBooksIntoAndOutOfChiseledBookshelf() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 2, 0, VanillaBlocks::CHISELED_BOOKSHELF());
		$this->set(0, 3, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(2);
		$top = $this->hopperInventory(0, 3, 0);
		$top->setItem(0, VanillaItems::STICK());
		$top->setItem(1, VanillaItems::BOOK()->setCount(2));
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 3);

		$tile = $this->world->getTileAt(0, 2, 0);
		self::assertInstanceOf(TileChiseledBookshelf::class, $tile);
		self::assertSame(1, $tile->getInventory()->getItem(0)->getCount(), "one book per slot");
		self::assertSame(1, $tile->getInventory()->getItem(1)->getCount());
		self::assertSame(1, self::countItems($top), "the stick is not a book");
		$bookshelf = $this->get(0, 2, 0);
		self::assertInstanceOf(ChiseledBookshelf::class, $bookshelf);
		self::assertTrue($bookshelf->hasSlot(ChiseledBookshelfSlot::TOP_LEFT));
		self::assertTrue($bookshelf->hasSlot(ChiseledBookshelfSlot::TOP_MIDDLE));
		self::assertSame(ChiseledBookshelfSlot::TOP_MIDDLE, $bookshelf->getLastInteractedSlot());
		self::assertSame(2, $bookshelf->getComparatorOutput());

		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::NORTH));
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 3);
		self::assertSame(2, self::countItems($this->hopperInventory(0, 1, 0)));
		$bookshelf = $this->get(0, 2, 0);
		self::assertInstanceOf(ChiseledBookshelf::class, $bookshelf);
		self::assertCount(0, $bookshelf->getSlots(), "the shown books follow the hopper");
	}

	private function chestCartAt(int $x, int $z) : MinecartChest{
		$this->set($x, 1, $z, VanillaBlocks::RAIL()->setShape(BlockLegacyMetadata::RAIL_STRAIGHT_EAST_WEST));
		$cart = MinecartType::CHEST->createEntity(Location::fromObject(new Vector3($x + 0.5, 1.0625, $z + 0.5), $this->world));
		self::assertInstanceOf(MinecartChest::class, $cart);
		$cart->spawnToAll();
		return $cart;
	}

	public function testDropperFillsChestMinecart() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::DROPPER()->setFacing(Facing::EAST));
		$cart = $this->chestCartAt(1, 0);
		$this->runTicks(1);
		$dropper = $this->hopperInventory(0, 1, 0);
		$dropper->addItem(VanillaItems::DIAMOND()->setCount(3));

		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(1);

		self::assertSame(2, self::countItems($dropper));
		self::assertSame(1, self::countItems($cart->getInventory()));
		self::assertCount(0, array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn()));
	}

	public function testCrafterFillsChestMinecart() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::CRAFTER()->setOrientation(CrafterOrientation::EAST_UP));
		$cart = $this->chestCartAt(1, 0);
		$this->runTicks(1);
		$crafter = $this->hopperInventory(0, 1, 0);
		$this->getCraftingManager()->registerShapedRecipe(new ShapedRecipe(
			["AAA", "AAA", "AAA"],
			["A" => new ExactRecipeIngredient(VanillaItems::IRON_INGOT())],
			[VanillaBlocks::IRON()->asItem()]
		));
		for($slot = 0; $slot < 9; ++$slot){
			$crafter->setItem($slot, VanillaItems::IRON_INGOT());
		}
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Crafter::CRAFT_DELAY_TICKS + 1);
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(1);

		self::assertTrue($cart->getInventory()->getItem(0)->equals(VanillaBlocks::IRON()->asItem()));
		self::assertCount(0, array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn()));
	}

	public function testPotShatterList() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::DECORATED_POT());
		$this->runTicks(1);
		$pot = $this->get(0, 1, 0);
		self::assertInstanceOf(DecoratedPot::class, $pot);
		$ids = fn(array $drops) => array_map(fn($item) => $item->getTypeId(), $drops);

		self::assertSame([ItemTypeIds::BRICK, ItemTypeIds::BRICK, ItemTypeIds::BRICK, ItemTypeIds::BRICK], $ids($pot->getDropsForCompatibleTool(VanillaItems::MACE())), "the mace shatters the pot");
		self::assertCount(4, $pot->getDropsForCompatibleTool(VanillaItems::DIAMOND_PICKAXE()));
		self::assertCount(4, $pot->getDropsForCompatibleTool(VanillaItems::TRIDENT()));
		$spearDrops = $pot->getDropsForCompatibleTool(VanillaItems::IRON_SPEAR());
		self::assertCount(1, $spearDrops, "a spear does not shatter the pot");
		self::assertSame(VanillaBlocks::DECORATED_POT()->asItem()->getTypeId(), $spearDrops[0]->getTypeId());
	}

	public function testStatueAndShelfComparatorOutput() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::COPPER_GOLEM_STATUE());
		$statue = $this->get(0, 1, 0);
		self::assertSame(1, RedstoneComparator::readComparatorOutput($statue, Facing::NORTH), "standing pose");

		$this->set(1, 1, 0, VanillaBlocks::OAK_SHELF()->setFacing(Facing::SOUTH));
		$this->runTicks(1);
		$tile = $this->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(TileShelf::class, $tile);
		$tile->getInventory()->setItem(0, VanillaItems::STICK());
		$tile->getInventory()->setItem(2, VanillaItems::DIAMOND());
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(Shelf::class, $shelf);
		self::assertSame(5, $shelf->getComparatorOutput(), "left (1) + right (4)");
		//a comparator north of a south-facing shelf (behind it) faces south
		self::assertSame(5, RedstoneComparator::readComparatorOutput($shelf, Facing::SOUTH));
		self::assertSame(0, RedstoneComparator::readComparatorOutput($shelf, Facing::NORTH), "not read from the front");
		self::assertSame(0, RedstoneComparator::readComparatorOutput($shelf, Facing::EAST), "nor from the sides");
	}

	public function testComparatorReadsPot() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::DECORATED_POT());
		//comparator east of the pot, reading it from the west
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(2);
		self::assertSame(0, $this->wirePower(2, 1, 0));
		$item = VanillaItems::DIAMOND()->setCount(64);
		for($i = 0; $i < 32; ++$i){
			$this->get(0, 1, 0)->onInteract($item, Facing::UP, new Vector3(0.5, 1, 0.5));
		}
		$this->runTicks(4);
		self::assertSame(8, $this->wirePower(2, 1, 0), "32 of 64: 1 + floor(14 * 32 / 64)");
	}
}
