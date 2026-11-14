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
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Bogged;
use pocketmine\entity\monster\Breeze;
use pocketmine\entity\monster\CaveSpider;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Enderman;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Ghast;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Phantom;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Stray;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\Zombie;
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
 * Tick cost of many hostile mobs of different kinds. Small counts run as normal tests; set MOB_BENCH=1 to run 100
 * mixed hostiles for 600 ticks (half of them fighting) and the cost of each kind alone, printed to stderr.
 *
 * @group benchmark
 */
class HostileMobBenchmarkTest extends MobTestCase{

	/**
	 * @phpstan-return list<\Closure(Location) : Mob>
	 */
	private static function factories() : array{
		return [
			fn(Location $l) => new Husk($l),
			fn(Location $l) => new Drowned($l),
			fn(Location $l) => new ZombieVillager($l),
			fn(Location $l) => new ZombifiedPiglin($l),
			fn(Location $l) => new Stray($l),
			fn(Location $l) => new Bogged($l),
			fn(Location $l) => new WitherSkeleton($l),
			fn(Location $l) => new CaveSpider($l),
			fn(Location $l) => new Slime($l, null, 2),
			fn(Location $l) => new MagmaCube($l, null, 2),
			fn(Location $l) => new Witch($l),
			fn(Location $l) => new Pillager($l),
			fn(Location $l) => new Vindicator($l),
			fn(Location $l) => new Evoker($l),
			fn(Location $l) => new Vex($l),
			fn(Location $l) => new Ravager($l),
			fn(Location $l) => new Enderman($l),
			fn(Location $l) => new Blaze($l),
			fn(Location $l) => new Ghast(Location::fromObject($l->add(0, 6, 0), $l->getWorld())),
			fn(Location $l) => new Phantom(Location::fromObject($l->add(0, 10, 0), $l->getWorld())),
			fn(Location $l) => new Breeze($l),
			fn(Location $l) => new Hoglin($l),
			fn(Location $l) => new Zoglin($l),
			fn(Location $l) => new Piglin($l),
			fn(Location $l) => new PiglinBrute($l),
		];
	}

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	private function randomLocation() : Location{
		return new Location(mt_rand(-20, 20) + 0.5, 1, mt_rand(-20, 20) + 0.5, $this->world, 0, 0);
	}

	/**
	 * Returns targets the mobs can fight without killing them.
	 *
	 * @return Cow[]
	 */
	private function makeTargets(int $count) : array{
		$targets = [];
		for($i = 0; $i < $count; ++$i){
			$cow = new Cow($this->randomLocation());
			$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
			$cow->setMaxHealth(1000000);
			$cow->setHealth(1000000);
			$targets[] = $cow;
		}
		return $targets;
	}

	/**
	 * @param Mob[] $mobs
	 * @return float microseconds per mob tick
	 */
	private function measure(array $mobs, int $ticks) : float{
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$elapsed = hrtime(true) - $start;
		return $elapsed / $ticks / count($mobs) / 1e3;
	}

	public function testMixedHostiles() : void{
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD; //no peaceful despawning, everyone fights
		mt_srand(4321);
		$count = $this->isFullBenchmark() ? 100 : 25;
		$ticks = $this->isFullBenchmark() ? 600 : 40;
		$targets = $this->makeTargets(10);
		$factories = self::factories();
		$mobs = [];
		for($i = 0; $i < $count; ++$i){
			$mob = $factories[$i % count($factories)]($this->randomLocation());
			if($i % 2 === 0){
				$mob->setAttackTarget($targets[mt_rand(0, count($targets) - 1)]);
			}
			$mobs[] = $mob;
		}
		$perMob = $this->measure($mobs, $ticks);
		self::assertGreaterThan(0, $perMob);
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[hostile bench] %d mixed hostiles (half fighting): %.3f ms per world tick, %.1f us per mob tick\n", $count, $perMob * $count / 1000, $perMob));
		}
	}

	public function testEachKindAlone() : void{
		if(!$this->isFullBenchmark()){
			self::markTestSkipped("set MOB_BENCH=1 to measure each mob kind");
		}
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD;
		//phase 1 mobs first, as a reference
		$reference = [fn(Location $l) => new Zombie($l), fn(Location $l) => new Skeleton($l)];
		$measured = 0;
		foreach([...$reference, ...self::factories()] as $factory){
			$results = [];
			$name = "";
			foreach([false, true] as $fighting){
				mt_srand(99);
				$targets = $fighting ? $this->makeTargets(2) : [];
				$mobs = [];
				for($i = 0; $i < 10; ++$i){
					$mob = $factory($this->randomLocation());
					if($fighting){
						$mob->setAttackTarget($targets[$i % 2]);
					}
					$mobs[] = $mob;
				}
				$results[] = $this->measure($mobs, 200);
				$name = $mobs[0]->getName();
				foreach($this->world->getEntities() as $entity){
					$entity->flagForDespawn();
				}
				$this->runTicks(1);
			}
			fwrite(STDERR, sprintf("[hostile bench] %-16s %6.1f us per mob tick idle, %6.1f fighting\n", $name, $results[0], $results[1]));
			++$measured;
		}
		self::assertSame(count($reference) + count(self::factories()), $measured);
	}
}
