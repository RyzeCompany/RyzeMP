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

use pocketmine\block\CopperBulb;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\Shelf;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\utils\ChiseledBookshelfSlot;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;

class ComparatorReadingTest extends RedstoneTestCase{

	public function testBlockReadings() : void{
		self::assertSame(0, VanillaBlocks::COPPER_BULB()->setLit(false)->getComparatorOutput());
		self::assertSame(15, VanillaBlocks::COPPER_BULB()->setLit(true)->getComparatorOutput());

		self::assertSame(0, VanillaBlocks::ITEM_FRAME()->getComparatorOutput());
		self::assertSame(1, VanillaBlocks::ITEM_FRAME()->setFramedItem(VanillaItems::STICK())->getComparatorOutput());
		self::assertSame(8, VanillaBlocks::GLOWING_ITEM_FRAME()->setFramedItem(VanillaItems::STICK())->setItemRotation(7)->getComparatorOutput());

		self::assertSame(0, VanillaBlocks::CAULDRON()->getComparatorOutput());
		for($level = 1; $level <= 6; ++$level){
			self::assertSame($level, VanillaBlocks::WATER_CAULDRON()->setFillLevel($level)->getComparatorOutput());
		}
		self::assertSame(6, VanillaBlocks::LAVA_CAULDRON()->setFillLevel(6)->getComparatorOutput());
		self::assertSame(3, VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(3)->getComparatorOutput());

		for($honey = 0; $honey <= 5; ++$honey){
			self::assertSame($honey, VanillaBlocks::BEEHIVE()->setHoneyLevel($honey)->getComparatorOutput());
			self::assertSame($honey, VanillaBlocks::BEE_NEST()->setHoneyLevel($honey)->getComparatorOutput());
		}

		self::assertSame(0, VanillaBlocks::END_PORTAL_FRAME()->getComparatorOutput());
		self::assertSame(15, VanillaBlocks::END_PORTAL_FRAME()->setEye(true)->getComparatorOutput());

		foreach([0 => 0, 1 => 3, 2 => 7, 3 => 11, 4 => 15] as $charges => $output){
			self::assertSame($output, VanillaBlocks::RESPAWN_ANCHOR()->setCharges($charges)->getComparatorOutput());
		}

		self::assertSame(0, VanillaBlocks::CHISELED_BOOKSHELF()->getComparatorOutput());
		self::assertSame(1, VanillaBlocks::CHISELED_BOOKSHELF()->setLastInteractedSlot(ChiseledBookshelfSlot::TOP_LEFT)->getComparatorOutput());
		self::assertSame(6, VanillaBlocks::CHISELED_BOOKSHELF()->setLastInteractedSlot(ChiseledBookshelfSlot::BOTTOM_RIGHT)->getComparatorOutput());
	}

	public function testReadableStateFlags() : void{
		self::assertTrue(RedstoneEngine::isComparatorReadableState(VanillaBlocks::CAKE()->getStateId()));
		self::assertTrue(RedstoneEngine::isComparatorReadableState(VanillaBlocks::RESPAWN_ANCHOR()->getStateId()));
		self::assertTrue(RedstoneEngine::isComparatorReadableState(VanillaBlocks::CHEST()->getStateId()), "containers are read through their tile");
		self::assertTrue(RedstoneEngine::isComparatorReadableState(VanillaBlocks::HOPPER()->getStateId()));
		self::assertFalse(RedstoneEngine::isComparatorReadableState(VanillaBlocks::STONE()->getStateId()));
		self::assertFalse(RedstoneEngine::isComparatorReadableState(VanillaBlocks::REDSTONE_WIRE()->getStateId()));
	}

	/**
	 * Comparator at x=2 facing west reads x=1 (or x=0 through a conductor at x=1); wire at x=3 shows its output.
	 */
	private function buildReader() : void{
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
	}

	public function testCopperBulbFlipFlopReadByComparator() : void{
		$this->floor(-1, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::COPPER_BULB());
		$this->set(1, 1, -1, VanillaBlocks::LEVER());
		$this->buildReader();
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));

		$this->interact(1, 1, -1); //on: the bulb lights up
		$this->runTicks(6);
		$bulb = $this->get(1, 1, 0);
		self::assertInstanceOf(CopperBulb::class, $bulb);
		self::assertTrue($bulb->isLit());
		self::assertSame(15, $this->wirePower(3, 1, 0));

		$this->interact(1, 1, -1); //off: the bulb stays lit
		$this->runTicks(6);
		self::assertSame(15, $this->wirePower(3, 1, 0));

		$this->interact(1, 1, -1); //on again: the bulb turns off
		$this->runTicks(6);
		self::assertSame(0, $this->wirePower(3, 1, 0));
	}

	public function testComparatorBehindBlockUpdatesWhenCakeIsEaten() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(0, 1, 0, VanillaBlocks::CAKE()->setBites(3));
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->buildReader();
		$this->runTicks(4);
		self::assertSame(8, $this->wirePower(3, 1, 0));

		$this->set(0, 1, 0, VanillaBlocks::CAKE()->setBites(4));
		$this->runTicks(4);
		self::assertSame(6, $this->wirePower(3, 1, 0));

		$this->set(0, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));
	}

	public function testComparatorBehindBlockUpdatesWhenAnchorIsCharged() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(0, 1, 0, VanillaBlocks::RESPAWN_ANCHOR());
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->buildReader();
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));

		$this->set(0, 1, 0, VanillaBlocks::RESPAWN_ANCHOR()->setCharges(2));
		$this->runTicks(4);
		self::assertSame(7, $this->wirePower(3, 1, 0));
	}

	public function testItemFrameRotationReadThroughBlock() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		//hangs on the west face of the stone
		$this->set(0, 1, 0, VanillaBlocks::ITEM_FRAME()->setFacing(Facing::WEST)->setFramedItem(VanillaItems::STICK())->setItemRotation(2));
		$this->buildReader();
		$this->runTicks(4);
		self::assertSame(3, $this->wirePower(3, 1, 0));

		//rotating only changes the tile, not the block state
		$this->interact(0, 1, 0);
		$this->runTicks(4);
		self::assertSame(4, $this->wirePower(3, 1, 0));
	}

	public function testDoubleChestOtherHalfUpdatesComparator() : void{
		$this->floor(-1, -1, 6, 3);
		$this->set(1, 1, 0, VanillaBlocks::CHEST());
		$this->set(1, 1, 1, VanillaBlocks::CHEST());
		$this->buildReader();
		$a = $this->world->getTileAt(1, 1, 0);
		$b = $this->world->getTileAt(1, 1, 1);
		self::assertInstanceOf(TileChest::class, $a);
		self::assertInstanceOf(TileChest::class, $b);

		//27 full slots in half A: 15 alone, 8 once paired (27 of 54 slots)
		for($slot = 0; $slot < 27; ++$slot){
			$a->getRealInventory()->setItem($slot, VanillaBlocks::STONE()->asItem()->setCount(64));
		}
		$this->runTicks(4);
		self::assertSame(15, $this->wirePower(3, 1, 0));
		self::assertTrue($a->pairWith($b));
		$this->runTicks(4);
		self::assertSame(8, $this->wirePower(3, 1, 0));

		//filling half B (not read directly by the comparator) must update it
		for($slot = 0; $slot < 27; ++$slot){
			$b->getRealInventory()->setItem($slot, VanillaBlocks::STONE()->asItem()->setCount(64));
		}
		$this->runTicks(4);
		self::assertSame(15, $this->wirePower(3, 1, 0));

		//breaking half B leaves half A alone again
		$b->getRealInventory()->clearAll();
		$this->runTicks(4);
		self::assertSame(8, $this->wirePower(3, 1, 0));
		$this->breakBlock(1, 1, 1);
		$this->runTicks(4);
		self::assertSame(15, $this->wirePower(3, 1, 0));
	}

	public function testShelfPoweredByLever() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_SHELF()->setFacing(Facing::SOUTH));
		$this->set(0, 1, 0, VanillaBlocks::LEVER());
		$this->runTicks(2);
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(Shelf::class, $shelf);
		self::assertFalse($shelf->isPowered());

		$this->interact(0, 1, 0);
		$this->runTicks(2);
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(Shelf::class, $shelf);
		self::assertTrue($shelf->isPowered());

		$this->interact(0, 1, 0);
		$this->runTicks(2);
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(Shelf::class, $shelf);
		self::assertFalse($shelf->isPowered());
	}

	public function testShelfPlacedNextToSignalIsPowered() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		$item = VanillaBlocks::OAK_SHELF()->asItem();
		$this->world->useItemOn(new Vector3(1, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5));
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(Shelf::class, $shelf);
		self::assertTrue($shelf->isPowered());
	}

	public function testComparatorDirectlyReadsChiseledBookshelfAfterUse() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::CHISELED_BOOKSHELF()->setFacing(Facing::NORTH));
		$this->buildReader();
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));
		$comparator = $this->get(2, 1, 0);
		self::assertInstanceOf(RedstoneComparator::class, $comparator);

		$this->get(1, 1, 0)->onInteract(VanillaItems::BOOK(), Facing::NORTH, new Vector3(0.9, 0.9, 0));
		$this->runTicks(4);
		self::assertGreaterThan(0, $this->wirePower(3, 1, 0));
	}
}
