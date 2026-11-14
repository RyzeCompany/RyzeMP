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

use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;

class RedstoneDiodeTest extends RedstoneTestCase{

	/**
	 * @phpstan-return list<array{int}>
	 */
	public static function delayProvider() : array{
		return [[1], [2], [3], [4]];
	}

	/**
	 * @dataProvider delayProvider
	 */
	public function testRepeaterDelay(int $delay) : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(0, 1, 0, VanillaBlocks::LEVER());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::WEST)->setDelay($delay));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(2);
		self::assertSame(0, $this->wirePower(2, 1, 0));

		$this->interact(0, 1, 0);
		//the update reaches the repeater on the next tick, then it waits $delay redstone ticks
		$this->runTicks(2 * $delay);
		self::assertSame(0, $this->wirePower(2, 1, 0));
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(2, 1, 0));

		$this->interact(0, 1, 0);
		$this->runTicks(2 * $delay);
		self::assertSame(15, $this->wirePower(2, 1, 0));
		$this->runTicks(1);
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	public function testRepeaterRestoresFullPower() : void{
		$this->floor(-1, -1, 20, 1);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		for($x = 1; $x <= 14; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		}
		$this->set(15, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::WEST));
		$this->set(16, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(5);
		self::assertSame(2, $this->wirePower(14, 1, 0));
		self::assertSame(15, $this->wirePower(16, 1, 0));
	}

	public function testRepeaterLock() : void{
		$this->floor(-1, -1, 6, 4);
		//repeater under test: input from the west, output to the east
		$this->set(0, 1, 0, VanillaBlocks::LEVER());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::WEST));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		//locking repeater pointing north into its side
		$this->set(1, 1, 2, VanillaBlocks::LEVER());
		$this->set(1, 1, 1, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::SOUTH));
		$this->runTicks(2);

		$this->interact(1, 1, 2);
		$this->runTicks(4);
		$locked = $this->get(1, 1, 0);
		self::assertInstanceOf(RedstoneRepeater::class, $locked);
		self::assertTrue($locked->isLocked());

		$this->interact(0, 1, 0);
		$this->runTicks(10);
		self::assertSame(0, $this->wirePower(2, 1, 0), "locked repeater must keep its output");

		//unlocking lets it read its input again
		$this->interact(1, 1, 2);
		$this->runTicks(10);
		self::assertSame(15, $this->wirePower(2, 1, 0));
	}

	public function testComparatorCalculation() : void{
		self::assertSame(15, RedstoneComparator::calculateOutput(15, 13, false));
		self::assertSame(0, RedstoneComparator::calculateOutput(12, 13, false));
		self::assertSame(13, RedstoneComparator::calculateOutput(13, 13, false));
		self::assertSame(2, RedstoneComparator::calculateOutput(15, 13, true));
		self::assertSame(0, RedstoneComparator::calculateOutput(5, 13, true));
		self::assertSame(7, RedstoneComparator::calculateOutput(7, 0, true));
	}

	private function buildComparatorCircuit(bool $subtract) : void{
		$this->floor(-1, -1, 6, 6);
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE()); //rear: 15
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST)->setSubtractMode($subtract));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		//side: wire at 13
		$this->set(2, 1, 4, VanillaBlocks::REDSTONE());
		for($z = 1; $z <= 3; ++$z){
			$this->set(2, 1, $z, VanillaBlocks::REDSTONE_WIRE());
		}
		$this->runTicks(6);
		self::assertSame(13, $this->wirePower(2, 1, 1));
	}

	public function testComparatorCompareMode() : void{
		$this->buildComparatorCircuit(false);
		self::assertSame(15, $this->wirePower(3, 1, 0));
	}

	public function testComparatorSubtractMode() : void{
		$this->buildComparatorCircuit(true);
		self::assertSame(2, $this->wirePower(3, 1, 0));

		//switching mode takes effect one redstone tick later
		$this->interact(2, 1, 0);
		$this->runTicks(4);
		self::assertSame(15, $this->wirePower(3, 1, 0));
	}

	public function testComparatorReadsChest() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::CHEST());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));

		$chest = $this->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(TileChest::class, $chest);
		$chest->getInventory()->setItem(0, VanillaBlocks::STONE()->asItem()->setCount(64));
		$this->runTicks(4);
		self::assertSame(1, $this->wirePower(3, 1, 0));

		for($slot = 0; $slot < 27; ++$slot){
			$chest->getInventory()->setItem($slot, VanillaBlocks::STONE()->asItem()->setCount(64));
		}
		$this->runTicks(4);
		self::assertSame(15, $this->wirePower(3, 1, 0));
	}

	public function testComparatorReadsChestThroughBlock() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$chest = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileChest::class, $chest);
		$chest->getInventory()->setItem(0, VanillaBlocks::STONE()->asItem()->setCount(64));
		$this->runTicks(4);
		self::assertSame(1, $this->wirePower(3, 1, 0));
	}

	public function testComparatorReadsCake() : void{
		$this->floor(-1, -1, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::CAKE()->setBites(3));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(4);
		self::assertSame(8, $this->wirePower(3, 1, 0));
	}
}
