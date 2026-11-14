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

namespace pocketmine\entity\ai;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\Zombie;
use pocketmine\timings\TimingsHandler;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of many mobs. Small counts run as normal tests; set MOB_BENCH=1 to run 100 cows + 50 zombies for 600
 * ticks and print the cost per mob tick.
 *
 * @group benchmark
 */
class MobBenchmarkTest extends MobTestCase{

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	public function testCowsAndZombies() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(1234);
		$cows = $this->isFullBenchmark() ? 100 : 10;
		$zombies = $this->isFullBenchmark() ? 50 : 5;
		$ticks = $this->isFullBenchmark() ? 600 : 60;

		/** @var Mob[] $mobs */
		$mobs = [];
		$cowList = [];
		for($i = 0; $i < $cows; ++$i){
			$cowList[] = $mobs[] = new Cow(new Location(mt_rand(-20, 20) + 0.5, 1, mt_rand(-20, 20) + 0.5, $this->world, 0, 0));
		}
		for($i = 0; $i < $zombies; ++$i){
			$zombie = new Zombie(new Location(mt_rand(-20, 20) + 0.5, 1, mt_rand(-20, 20) + 0.5, $this->world, 0, 0));
			//half of them chase a cow (pathfinding + attacks), the others wander
			if($i % 2 === 0){
				$target = $cowList[mt_rand(0, count($cowList) - 1)];
				$target->setMaxHealth(100000);
				$target->setHealth(100000);
				$zombie->setAttackTarget($target);
			}
			$mobs[] = $zombie;
		}
		if(getenv("MOB_BENCH_IDLE") === "1"){
			//AI runs but no goal may move the mobs: measures the idle AI overhead without walking physics
			foreach($mobs as $m){
				$m->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
			}
		}
		$this->runTicks(20); //warm up (first update, initial goal selection)

		$start = hrtime(true);
		$this->runTicks($ticks);
		$elapsed = hrtime(true) - $start;

		$alive = 0;
		foreach($mobs as $mob){
			if(!$mob->isClosed()){
				++$alive;
			}
		}
		self::assertGreaterThan(0, $alive);
		$perTickMs = $elapsed / $ticks / 1e6;
		$perMobUs = $elapsed / $ticks / count($mobs) / 1e3;
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[mob bench] %d cows + %d zombies: %.3f ms per world tick, %.1f us per mob tick (%d ticks)\n", $cows, $zombies, $perTickMs, $perMobUs, $ticks));
		}

		//same mobs with AI disabled: cost of the entity base tick and physics alone
		AiBudget::configure(false, 100000, 100000, 400, requireNearbyPlayer: false);
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$idle = hrtime(true) - $start;
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[mob bench] same mobs without AI: %.3f ms per world tick, %.1f us per mob tick\n", $idle / $ticks / 1e6, $idle / $ticks / count($mobs) / 1e3));
		}
	}

	/**
	 * A pen packed with cows (one per block): they bump into each other all the time (entity pushing).
	 */
	public function testPackedPen() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(4321);
		$side = $this->isFullBenchmark() ? 10 : 4;
		$ticks = $this->isFullBenchmark() ? 600 : 60;
		$stone = VanillaBlocks::STONE();
		for($i = -1; $i <= $side; ++$i){
			foreach([[$i, -1], [$i, $side], [-1, $i], [$side, $i]] as [$x, $z]){
				for($y = 1; $y <= 2; ++$y){
					$this->world->setBlockAt($x, $y, $z, $stone, false);
				}
			}
		}
		/** @var Mob[] $cows */
		$cows = [];
		for($x = 0; $x < $side; ++$x){
			for($z = 0; $z < $side; ++$z){
				$cows[] = new Cow(new Location($x + 0.5, 1, $z + 0.5, $this->world, mt_rand(0, 359), 0));
			}
		}
		$this->runTicks(20);

		$start = hrtime(true);
		$this->runTicks($ticks);
		$elapsed = hrtime(true) - $start;

		foreach($cows as $cow){
			$loc = $cow->getLocation();
			self::assertTrue($loc->x > 0 && $loc->x < $side && $loc->z > 0 && $loc->z < $side, "the cows stay in the pen");
		}
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[mob bench] packed pen, %d cows in %dx%d: %.3f ms per world tick, %.1f us per mob tick (%d ticks)\n", count($cows), $side, $side, $elapsed / $ticks / 1e6, $elapsed / $ticks / count($cows) / 1e3, $ticks));
		}
	}
}
