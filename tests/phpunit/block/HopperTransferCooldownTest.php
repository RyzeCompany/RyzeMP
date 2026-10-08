<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

use PHPUnit\Framework\TestCase;

final class HopperTransferCooldownTest extends TestCase{

	public function testNeighbourUpdatesNeverShortenCooldown() : void{
		$cooldown = new HopperTransferCooldown(8);
		self::assertTrue($cooldown->canTransfer(100));
		$cooldown->markTransferred(100);

		// World block updates may arrive early, more than once per tick.
		foreach([100, 100, 101, 102, 102, 103, 104, 105, 106, 107] as $tick){
			self::assertFalse($cooldown->canTransfer($tick), "Premature transfer at tick $tick");
		}
		self::assertTrue($cooldown->canTransfer(108));
		$cooldown->markTransferred(108);
		self::assertFalse($cooldown->canTransfer(109));
		self::assertTrue($cooldown->canTransfer(116));
	}

	public function testMaximumTransferRateAt20TpsEvenWithFrequentCallbacks() : void{
		$cooldown = new HopperTransferCooldown(8);
		$transferred = 0;
		for($tick = 0; $tick < 80; ++$tick){
			for($update = 0; $update < 4; ++$update){
				if($cooldown->canTransfer($tick)){
					++$transferred;
					$cooldown->markTransferred($tick);
				}
			}
		}

		// 80 ticks at 20 TPS = 4 seconds, 10 items = 2.5 items/sec.
		self::assertSame(10, $transferred);
	}

	public function testRemainingTicksSavedAcrossRestart() : void{
		$cooldown = new HopperTransferCooldown(8);
		$cooldown->markTransferred(200);
		self::assertSame(8, $cooldown->getRemainingTicks(200));
		self::assertSame(7, $cooldown->getRemainingTicks(201));
		self::assertSame(2, $cooldown->getRemainingTicks(206));
		self::assertSame(0, $cooldown->getRemainingTicks(208));

		$loaded = new HopperTransferCooldown(8);
		$loaded->loadRemainingTicks($cooldown->getRemainingTicks(206));

		// A new server starts ticking from zero; remaining delay stays correct.
		self::assertSame(2, $loaded->getRemainingTicks(0));
		self::assertFalse($loaded->canTransfer(1));
		self::assertFalse($loaded->canTransfer(2));
		self::assertTrue($loaded->canTransfer(3));
		$loaded->markTransferred(3);
		self::assertFalse($loaded->canTransfer(10));
		self::assertTrue($loaded->canTransfer(11));
	}

	public function testLegacySavedCooldownClampedToSafeRange() : void{
		$cooldown = new HopperTransferCooldown(8);
		$cooldown->loadRemainingTicks(9999);
		self::assertSame(8, $cooldown->getRemainingTicks(100));
		self::assertFalse($cooldown->canTransfer(100));
		self::assertFalse($cooldown->canTransfer(107));
		self::assertTrue($cooldown->canTransfer(108));

		$cooldown->loadRemainingTicks(-123);
		self::assertSame(0, $cooldown->getRemainingTicks(200));
		self::assertTrue($cooldown->canTransfer(200));
	}

	public function testNoCooldownIfTransferFailed() : void{
		$cooldown = new HopperTransferCooldown(8);
		self::assertTrue($cooldown->canTransfer(40));
		// Failed transfer: markTransferred() is not called.
		self::assertTrue($cooldown->canTransfer(41));
		$cooldown->markTransferred(41);
		self::assertFalse($cooldown->canTransfer(42));
	}

	public function testCooldownIndependentPerHopper() : void{
		$a = new HopperTransferCooldown(8);
		$b = new HopperTransferCooldown(8);
		$a->markTransferred(50);
		self::assertFalse($a->canTransfer(50));
		self::assertTrue($b->canTransfer(50));
		$b->markTransferred(53);
		self::assertTrue($a->canTransfer(58));
		self::assertFalse($b->canTransfer(58));
		self::assertTrue($b->canTransfer(61));
	}

	public function testCannotConfigureZeroOrNegativeCooldown() : void{
		$this->expectException(\InvalidArgumentException::class);
		new HopperTransferCooldown(0);
	}
}
