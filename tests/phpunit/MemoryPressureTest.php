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

namespace pocketmine;

use PHPUnit\Framework\TestCase;

final class MemoryPressureTest extends TestCase{

	public function testNoCapWithoutLowMemory() : void{
		$p = new MemoryPressure(4);
		self::assertSame(0, $p->getCap());
		self::assertSame(8, $p->capViewDistance(8));
		self::assertFalse($p->onMemoryCheck(true, 8));
		self::assertSame(0, $p->getCap());
	}

	public function testStepsDownToFloor() : void{
		$p = new MemoryPressure(4);
		self::assertTrue($p->onLowMemory(10));
		self::assertSame(8, $p->getCap());
		self::assertSame(8, $p->capViewDistance(12));
		self::assertSame(5, $p->capViewDistance(5));
		self::assertTrue($p->onLowMemory(10));
		self::assertSame(6, $p->getCap());
		self::assertTrue($p->onLowMemory(10));
		self::assertSame(4, $p->getCap());
		self::assertFalse($p->onLowMemory(10), "already at the floor");
		self::assertSame(4, $p->getCap());
	}

	public function testFloorDisabled() : void{
		$p = new MemoryPressure(0);
		self::assertFalse($p->onLowMemory(8));
		self::assertSame(0, $p->getCap());
		self::assertSame(8, $p->capViewDistance(8));
	}

	public function testNothingToLowerAtOrBelowFloor() : void{
		$p = new MemoryPressure(4);
		self::assertFalse($p->onLowMemory(4));
		self::assertFalse($p->onLowMemory(3));
		self::assertSame(0, $p->getCap());
		$p = new MemoryPressure(4);
		self::assertTrue($p->onLowMemory(5));
		self::assertSame(4, $p->getCap());
	}

	public function testRecoveryIsGradualAndNeedsCalmChecks() : void{
		$p = new MemoryPressure(4);
		$p->onLowMemory(8);
		$p->onLowMemory(8);
		self::assertSame(4, $p->getCap());

		//under the limit but not under the recovery level: the cap stays
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS * 2; ++$i){
			self::assertFalse($p->onMemoryCheck(false, 8));
		}
		self::assertSame(4, $p->getCap());

		//one check over the recovery level restarts the count
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS - 1; ++$i){
			self::assertFalse($p->onMemoryCheck(true, 8));
		}
		self::assertFalse($p->onMemoryCheck(false, 8));
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS - 1; ++$i){
			self::assertFalse($p->onMemoryCheck(true, 8));
		}
		self::assertTrue($p->onMemoryCheck(true, 8));
		self::assertSame(5, $p->getCap());

		for($cap = 6; $cap <= 7; ++$cap){
			for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS - 1; ++$i){
				self::assertFalse($p->onMemoryCheck(true, 8));
			}
			self::assertTrue($p->onMemoryCheck(true, 8));
			self::assertSame($cap, $p->getCap());
		}
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS - 1; ++$i){
			$p->onMemoryCheck(true, 8);
		}
		self::assertTrue($p->onMemoryCheck(true, 8));
		self::assertSame(0, $p->getCap(), "cap removed once it reaches the view distance");
	}

	public function testLowMemoryDuringRecoveryLowersAgain() : void{
		$p = new MemoryPressure(4);
		$p->onLowMemory(8);
		$p->onLowMemory(8);
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS; ++$i){
			$p->onMemoryCheck(true, 8);
		}
		self::assertSame(5, $p->getCap());
		for($i = 0; $i < MemoryPressure::RECOVERY_CHECKS - 1; ++$i){
			$p->onMemoryCheck(true, 8);
		}
		self::assertTrue($p->onLowMemory(8));
		self::assertSame(4, $p->getCap());
		//the calm count restarted
		self::assertFalse($p->onMemoryCheck(true, 8));
		self::assertSame(4, $p->getCap());
	}
}
