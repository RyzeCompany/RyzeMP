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

use pocketmine\block\Composter;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\tile\Container;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\Inventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function hrtime;
use function printf;

class HopperTest extends RedstoneTestCase{

	protected function inventoryAt(int $x, int $y, int $z) : Inventory{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(Container::class, $tile);
		return $tile->getInventory();
	}

	protected static function countItems(Inventory $inventory) : int{
		$count = 0;
		foreach($inventory->getContents() as $item){
			$count += $item->getCount();
		}
		return $count;
	}

	private function hopper(int $facing) : \pocketmine\block\Hopper{
		return VanillaBlocks::HOPPER()->setFacing($facing);
	}

	/**
	 * Chest -> hopper -> chest, one item per 8 ticks.
	 */
	public function testTransferTiming() : void{
		$this->set(0, 3, 0, VanillaBlocks::CHEST());
		$this->set(0, 2, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$this->runTicks(2);

		$source = $this->inventoryAt(0, 3, 0);
		$hopper = $this->inventoryAt(0, 2, 0);
		$target = $this->inventoryAt(0, 1, 0);
		$source->addItem(VanillaItems::DIAMOND()->setCount(3));

		$this->runTicks(1);
		self::assertSame(2, self::countItems($source), "the hopper pulls as soon as it is woken up");
		self::assertSame(1, self::countItems($hopper));

		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS - 1);
		self::assertSame(2, self::countItems($source), "nothing moves during the cooldown");
		self::assertSame(0, self::countItems($target));

		$this->runTicks(1);
		self::assertSame(1, self::countItems($target), "push and pull happen in the same tick");
		self::assertSame(1, self::countItems($hopper));
		self::assertSame(1, self::countItems($source));

		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 2);
		self::assertSame(3, self::countItems($target));
		self::assertSame(0, self::countItems($hopper));
		self::assertSame(0, self::countItems($source));

		//one last look after the cooldown of the last transfer, then the hopper sleeps
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS);
		self::assertFalse($this->world->isBlockUpdateScheduled(0, 2, 0), "an idle hopper is not ticked");
	}

	public function testHopperChainWaitsAfterReceivingIntoEmptyHopper() : void{
		$this->set(0, 3, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 2, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$this->runTicks(2);

		$this->inventoryAt(0, 3, 0)->addItem(VanillaItems::DIAMOND());
		$this->runTicks(1);
		//first hopper pushes into the empty second one
		self::assertSame(1, self::countItems($this->inventoryAt(0, 2, 0)));
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS - 1);
		self::assertSame(0, self::countItems($this->inventoryAt(0, 1, 0)), "an item pushed into an empty hopper waits a full cooldown");
		$this->runTicks(1);
		self::assertSame(1, self::countItems($this->inventoryAt(0, 1, 0)));
	}

	public function testPoweredHopperIsLocked() : void{
		$this->set(0, 3, 0, VanillaBlocks::CHEST());
		$this->set(0, 2, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$this->set(1, 2, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);

		$source = $this->inventoryAt(0, 3, 0);
		$source->addItem(VanillaItems::DIAMOND()->setCount(2));
		$this->runTicks(20);
		self::assertSame(2, self::countItems($source), "a powered hopper does not pull");

		$this->set(1, 2, 0, VanillaBlocks::AIR());
		$this->runTicks(2);
		self::assertSame(1, self::countItems($source), "the hopper wakes up when unpowered");
	}

	public function testHopperPushesSideways() : void{
		$this->set(0, 1, 0, $this->hopper(Facing::EAST));
		$this->set(1, 1, 0, VanillaBlocks::BARREL());
		$this->runTicks(2);
		$this->inventoryAt(0, 1, 0)->addItem(VanillaItems::STICK());
		$this->runTicks(2);
		self::assertSame(1, self::countItems($this->inventoryAt(1, 1, 0)));
	}

	public function testHopperWakesUpWhenTargetGetsSpace() : void{
		$this->set(0, 1, 0, $this->hopper(Facing::EAST));
		$this->set(1, 1, 0, $this->hopper(Facing::DOWN)); //target hopper, pushing into nothing
		$this->runTicks(2);
		$target = $this->inventoryAt(1, 1, 0);
		for($slot = 0; $slot < $target->getSize(); ++$slot){
			$target->setItem($slot, VanillaItems::DIAMOND()->setCount(64));
		}
		$this->inventoryAt(0, 1, 0)->addItem(VanillaItems::STICK());
		$this->runTicks(20);
		self::assertSame(1, self::countItems($this->inventoryAt(0, 1, 0)), "target is full");
		self::assertFalse($this->world->isBlockUpdateScheduled(0, 1, 0), "a blocked hopper sleeps");

		$target->clear(0);
		$this->runTicks(2);
		self::assertSame(0, self::countItems($this->inventoryAt(0, 1, 0)));
	}

	public function testFurnaceSlots() : void{
		//hopper above the furnace fills the input, hopper at the side fills the fuel
		$this->set(0, 1, 0, VanillaBlocks::FURNACE());
		$this->set(0, 2, 0, $this->hopper(Facing::DOWN));
		$this->set(1, 1, 0, $this->hopper(Facing::WEST));
		$this->set(0, 0, 0, $this->hopper(Facing::DOWN));
		$this->runTicks(2);

		$this->inventoryAt(0, 2, 0)->addItem(VanillaItems::RAW_IRON());
		$side = $this->inventoryAt(1, 1, 0);
		$side->addItem(VanillaItems::DIAMOND(), VanillaItems::COAL());
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 3);

		$furnace = $this->inventoryAt(0, 1, 0);
		self::assertInstanceOf(FurnaceInventory::class, $furnace);
		self::assertTrue($furnace->getSmelting()->equals(VanillaItems::RAW_IRON()));
		self::assertTrue($furnace->getFuel()->equals(VanillaItems::COAL()), "coal goes into the fuel slot");
		self::assertSame(1, self::countItems($side), "items which are not fuel can't go in from the side");

		//a hopper below takes the result
		$furnace->setResult(VanillaItems::IRON_INGOT());
		$this->runTicks(2);
		self::assertSame(1, self::countItems($this->inventoryAt(0, 0, 0)));
		self::assertTrue($furnace->getResult()->isNull());
	}

	public function testShulkerBoxesDoNotGoIntoShulkerBoxes() : void{
		$this->set(0, 2, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 1, 0, VanillaBlocks::SHULKER_BOX());
		$this->runTicks(2);
		$this->inventoryAt(0, 2, 0)->addItem(VanillaBlocks::SHULKER_BOX()->asItem());
		$this->runTicks(20);
		self::assertSame(0, self::countItems($this->inventoryAt(0, 1, 0)));
	}

	public function testComposter() : void{
		$this->set(0, 3, 0, $this->hopper(Facing::DOWN));
		$this->set(0, 2, 0, VanillaBlocks::COMPOSTER()->setLayers(Composter::MAX_COMPOST_LAYERS));
		$this->set(0, 1, 0, $this->hopper(Facing::DOWN));
		$this->runTicks(2);
		self::assertTrue($this->inventoryAt(0, 1, 0)->getItem(0)->equals(VanillaItems::BONE_MEAL()), "a hopper below takes the bone meal");
		$composter = $this->get(0, 2, 0);
		self::assertInstanceOf(Composter::class, $composter);
		self::assertSame(0, $composter->getLayers());

		$this->inventoryAt(0, 3, 0)->addItem(VanillaItems::WHEAT_SEEDS()->setCount(5));
		$this->runTicks(2);
		self::assertSame(4, self::countItems($this->inventoryAt(0, 3, 0)), "a hopper above feeds compostable items");
	}

	public function testCollectsItemEntities() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, $this->hopper(Facing::DOWN));
		$this->runTicks(2);
		$this->world->dropItem(new Vector3(0.5, 2.5, 0.5), VanillaItems::DIAMOND()->setCount(5), new Vector3(0, 0, 0));
		$this->runTicks(40);
		self::assertSame(5, self::countItems($this->inventoryAt(0, 1, 0)), "the whole item stack is collected at once");
	}

	/**
	 * 100 hoppers in a loop passing items around: the hopper cost per tick, and no cost at all once idle.
	 */
	public function testHundredHoppersBenchmark() : void{
		//10x10 ring of hoppers at y=1, each pushing into the next
		$positions = [];
		for($x = 0; $x < 25; ++$x){ $positions[] = [$x, 0, Facing::EAST]; }
		for($z = 0; $z < 25; ++$z){ $positions[] = [25, $z, Facing::SOUTH]; }
		for($x = 25; $x > 0; --$x){ $positions[] = [$x, 25, Facing::WEST]; }
		for($z = 25; $z > 0; --$z){ $positions[] = [0, $z, Facing::NORTH]; }
		self::assertCount(100, $positions);
		foreach($positions as [$x, $z, $facing]){
			$this->set($x, 1, $z, $this->hopper($facing));
		}
		$this->runTicks(2);
		$items = 0;
		foreach($positions as $i => [$x, $z, ]){
			if($i % 2 === 0){
				$this->inventoryAt($x, 1, $z)->addItem(VanillaItems::DIAMOND()->setCount(5));
				$items += 5;
			}
		}

		$ticks = 200;
		$start = hrtime(true);
		$this->runTicks($ticks);
		$busyNs = hrtime(true) - $start;

		$total = 0;
		foreach($positions as [$x, $z, ]){
			$total += self::countItems($this->inventoryAt($x, 1, $z));
		}
		self::assertSame($items, $total, "no items are lost or duplicated");

		//idle: hoppers with nothing to do are not scheduled at all
		foreach($positions as [$x, $z, ]){
			$this->inventoryAt($x, 1, $z)->clearAll();
		}
		$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS + 2);
		$scheduled = 0;
		foreach($positions as [$x, $z, ]){
			if($this->world->isBlockUpdateScheduled($x, 1, $z)){
				$scheduled++;
			}
		}
		self::assertSame(0, $scheduled);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$idleNs = hrtime(true) - $start;

		printf("\n100 hoppers moving %d items: %.3f ms/tick; idle: %.3f ms/tick (whole world tick)\n", $items, $busyNs / $ticks / 1e6, $idleNs / $ticks / 1e6);
	}
}
