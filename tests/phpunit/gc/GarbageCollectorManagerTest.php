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
use pocketmine\world\redstone\NullThreadSafeLogger;
use function gc_collect_cycles;
use function gc_enable;

final class GarbageCollectorManagerTest extends TestCase{

	public function testThresholdDoublesWhenNothingIsCollected() : void{
		$manager = new GarbageCollectorManager(new \PrefixedLogger(new \SimpleLogger(), "test"), null);
		$adjust = new \ReflectionMethod(GarbageCollectorManager::class, "adjustGcThreshold");
		self::assertSame(10_001, $manager->getThreshold());
		//fruitless runs: 10k -> 20k -> 40k -> 80k -> 160k
		for($i = 0; $i < 4; ++$i){
			$adjust->invoke($manager, 0, 0);
		}
		self::assertSame(160_016, $manager->getThreshold());
		//a few cycles collected: one step up, like PHP's own collector
		$adjust->invoke($manager, 10, 0);
		self::assertSame(170_016, $manager->getThreshold());
		//plenty of garbage: one step back down
		$adjust->invoke($manager, 500, 0);
		self::assertSame(160_016, $manager->getThreshold());
		gc_enable();
	}

	public function testThresholdLimits() : void{
		$manager = new GarbageCollectorManager(new \PrefixedLogger(new \SimpleLogger(), "test"), null);
		$adjust = new \ReflectionMethod(GarbageCollectorManager::class, "adjustGcThreshold");
		$manager->setThresholdLimits(200_000, 500_000);
		self::assertSame(200_000, $manager->getThreshold());
		//plenty of garbage: never below the minimum
		$adjust->invoke($manager, 500, 0);
		self::assertSame(200_000, $manager->getThreshold());
		//fruitless runs: never above the maximum
		for($i = 0; $i < 4; ++$i){
			$adjust->invoke($manager, 0, 0);
		}
		self::assertSame(500_000, $manager->getThreshold());
		$adjust->invoke($manager, 500, 0);
		self::assertSame(490_000, $manager->getThreshold());
		//a maximum below the minimum is the minimum
		$manager->setThresholdLimits(300_000, 1);
		$adjust->invoke($manager, 0, 0);
		self::assertSame(300_000, $manager->getThreshold());
		gc_enable();
	}

	/**
	 * Leaves $count garbage cycles behind (each one a possible root).
	 */
	private static function makeCycles(int $count) : void{
		for($i = 0; $i < $count; ++$i){
			$a = new \stdClass();
			$b = new \stdClass();
			$a->other = $b;
			$b->other = $a;
		}
	}

	public function testEarlyCollectionsRunFromTheirOwnThresholdAndKeepTheThreshold() : void{
		gc_collect_cycles();
		$manager = new GarbageCollectorManager(new NullThreadSafeLogger(), null);
		try{
			$manager->setThresholdLimits(50_000, 400_000);

			self::makeCycles(10_000);
			self::assertSame(0, $manager->maybeCollectCycles(), "below the threshold");
			self::assertSame(0, $manager->maybeCollectCyclesEarly(0), "early collections disabled");
			self::assertSame(0, $manager->maybeCollectCyclesEarly(30_000), "below the early threshold too");
			self::assertGreaterThanOrEqual(10_000, $manager->getRoots());

			self::assertGreaterThanOrEqual(10_000, $manager->maybeCollectCyclesEarly(5_000), "collected early");
			self::assertSame(50_000, $manager->getThreshold(), "an early collection doesn't move the threshold");
			self::assertLessThan(5_000, $manager->getRoots());

			//at the threshold, the early variant collects like maybeCollectCycles(), and the threshold adapts as before
			self::makeCycles(30_000);
			self::assertGreaterThanOrEqual(30_000, $manager->maybeCollectCyclesEarly(200_000));
			self::assertSame(50_000, $manager->getThreshold(), "enough cycles collected: the threshold stays at its minimum");
			self::assertSame(0, $manager->maybeCollectCycles(), "nothing left");
		}finally{
			gc_enable();
		}
	}
}
