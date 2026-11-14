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

use pocketmine\block\VanillaBlocks;

class RedstoneWireTest extends RedstoneTestCase{

	private function wireLine(int $fromX, int $toX, int $z = 0) : void{
		for($x = $fromX; $x <= $toX; ++$x){
			$this->set($x, 1, $z, VanillaBlocks::REDSTONE_WIRE());
		}
	}

	public function testPowerDecreasesAlongLine() : void{
		$this->floor(-1, -1, 20, 1);
		$this->wireLine(1, 17);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);

		for($x = 1; $x <= 15; ++$x){
			self::assertSame(16 - $x, $this->wirePower($x, 1, 0), "wire at x=$x");
		}
		self::assertSame(0, $this->wirePower(16, 1, 0));
		self::assertSame(0, $this->wirePower(17, 1, 0));
	}

	public function testRemovingSourceUnpowersNetwork() : void{
		$this->floor(-1, -1, 10, 1);
		$this->wireLine(1, 8);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));

		$this->breakBlock(0, 1, 0);
		$this->runTicks(1);
		for($x = 1; $x <= 8; ++$x){
			self::assertSame(0, $this->wirePower($x, 1, 0), "wire at x=$x");
		}
	}

	public function testTwoSourcesTakeStrongest() : void{
		$this->floor(-1, -1, 12, 1);
		$this->wireLine(1, 9);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->set(10, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));
		self::assertSame(11, $this->wirePower(5, 1, 0));
		self::assertSame(12, $this->wirePower(6, 1, 0));
		self::assertSame(15, $this->wirePower(9, 1, 0));
	}

	public function testBreakingWireSplitsNetwork() : void{
		$this->floor(-1, -1, 10, 1);
		$this->wireLine(1, 8);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		$this->breakBlock(4, 1, 0);
		$this->runTicks(1);
		self::assertSame(13, $this->wirePower(3, 1, 0));
		self::assertSame(0, $this->wirePower(5, 1, 0));
		self::assertSame(0, $this->wirePower(8, 1, 0));
	}

	public function testWireClimbsBlocks() : void{
		$this->floor(-1, -1, 10, 1);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(2, 1, 0, VanillaBlocks::STONE());
		$this->set(2, 2, 0, VanillaBlocks::REDSTONE_WIRE()); //one block up
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE()); //and back down
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));
		self::assertSame(14, $this->wirePower(2, 2, 0));
		self::assertSame(13, $this->wirePower(3, 1, 0));
	}

	public function testBlockAboveCutsUpwardConnection() : void{
		$this->floor(-1, -1, 10, 1);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(2, 1, 0, VanillaBlocks::STONE());
		$this->set(2, 2, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(1, 2, 0, VanillaBlocks::STONE()); //conductor above the lower wire
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));
		self::assertSame(0, $this->wirePower(2, 2, 0));
	}

	public function testWireDoesNotGoDownFromGlass() : void{
		$this->floor(-1, -1, 10, 1);
		$this->set(0, 2, 0, VanillaBlocks::REDSTONE());
		$this->set(1, 1, 0, VanillaBlocks::GLASS());
		$this->set(1, 2, 0, VanillaBlocks::REDSTONE_WIRE()); //on glass
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE()); //one lower
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 2, 0));
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	public function testWireDoesNotPowerWireThroughBlock() : void{
		$this->floor(-1, -1, 10, 1);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(2, 1, 0, VanillaBlocks::STONE()); //powered by the wire pointing into it
		$this->set(2, 2, 0, VanillaBlocks::STONE()); //cuts the step-up connection
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));
		self::assertSame(0, $this->wirePower(3, 1, 0));
	}

	public function testLeverStronglyPowersAttachedBlock() : void{
		$this->floor(-1, -1, 10, 1);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		//lever on top of the stone block
		$this->set(1, 2, 0, VanillaBlocks::LEVER());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(1);
		self::assertSame(0, $this->wirePower(2, 1, 0));
		$this->interact(1, 2, 0);
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(2, 1, 0));
		$this->interact(1, 2, 0);
		$this->runTicks(1);
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}
}
