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

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Stray;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\World;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of idle skeletons, strays and spiders with and without iron golems, turtles, wolves and armadillos in the
 * world far from them (other targets and threats they never get near must cost nothing). Small counts run as normal
 * tests; set MOB_BENCH=1 to run 60 mobs for 600 ticks, printed to stderr.
 *
 * @group benchmark
 */
class SkeletonsBenchmarkTest extends MobTestCase{

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	private static function still(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		return $mob;
	}

	/**
	 * @return float microseconds per mob tick
	 */
	private function measure(bool $farEntities) : float{
		mt_srand(777);
		$count = $this->isFullBenchmark() ? 60 : 12;
		$ticks = $this->isFullBenchmark() ? 600 : 40;
		$mobs = [];
		for($i = 0; $i < $count; ++$i){
			$location = new Location(mt_rand(-22, -6) + 0.5, 1, mt_rand(-22, -6) + 0.5, $this->world, 0, 0);
			$mobs[] = match($i % 3){
				0 => new Skeleton($location),
				1 => new Stray($location),
				default => new Spider($location)
			};
		}
		if($farEntities){
			//28+ blocks from every mob
			self::still(new IronGolem(new Location(22.5, 1, 22.5, $this->world, 0, 0)));
			self::still(new Turtle(new Location(22.5, 1, 18.5, $this->world, 0, 0)));
			self::still(new Wolf(new Location(18.5, 1, 22.5, $this->world, 0, 0)));
			self::still(new Armadillo(new Location(18.5, 1, 18.5, $this->world, 0, 0)));
		}
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$elapsed = hrtime(true) - $start;
		foreach($this->world->getEntities() as $entity){
			$entity->flagForDespawn();
		}
		$this->runTicks(1);
		return $elapsed / $ticks / count($mobs) / 1e3;
	}

	public function testFarTargetsAndThreats() : void{
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD;
		$control = $this->measure(false);
		$far = $this->measure(true);
		self::assertGreaterThan(0, $control);
		self::assertGreaterThan(0, $far);
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[skeletons bench] idle skeletons/strays/spiders: %.2f us per mob tick alone, %.2f with a far golem, turtle, wolf and armadillo\n", $control, $far));
		}
	}
}
