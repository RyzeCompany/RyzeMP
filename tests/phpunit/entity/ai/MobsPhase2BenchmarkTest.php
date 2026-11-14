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
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Parrot;
use pocketmine\entity\animal\Rabbit;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\aquatic\Pufferfish;
use pocketmine\entity\aquatic\Salmon;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\aquatic\TropicalFish;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\MobsPhase2;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Utils;
use function arsort;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of the phase 2 mobs. Small counts run as normal tests; with MOB_BENCH=1, 100 mixed mobs (fish in a
 * pool, wolves, horses, bees, villagers, rabbits, parrots, llamas, goats, cats, foxes) run for 600 ticks, then 20 of
 * each phase 2 mob alone for 200 ticks to find the heaviest one.
 *
 * @group benchmark
 */
class MobsPhase2BenchmarkTest extends MobTestCase{

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	protected function setUp() : void{
		parent::setUp();
		//pool: x 10..22, z 10..22, y 1..3
		for($x = 9; $x <= 23; ++$x){
			for($z = 9; $z <= 23; ++$z){
				for($y = 1; $y <= 4; ++$y){
					$wall = $x === 9 || $x === 23 || $z === 9 || $z === 23;
					$this->world->setBlockAt($x, $y, $z, $wall ? VanillaBlocks::STONE() : ($y <= 3 ? VanillaBlocks::WATER() : VanillaBlocks::AIR()), false);
				}
			}
		}
	}

	private function landLoc() : Location{
		return new Location(mt_rand(-20, 5) + 0.5, 1, mt_rand(-20, 5) + 0.5, $this->world, 0, 0);
	}

	private function waterLoc() : Location{
		return new Location(mt_rand(11, 21) + 0.5, 2, mt_rand(11, 21) + 0.5, $this->world, 0, 0);
	}

	/**
	 * Returns the cost in microseconds per mob tick of ticking the world with $count mobs.
	 */
	private function measure(int $count, int $ticks) : float{
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		return (hrtime(true) - $start) / $ticks / $count / 1e3;
	}

	public function testMixedMobs() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(4321);
		$scale = $this->isFullBenchmark() ? 1 : 0.1;
		$ticks = $this->isFullBenchmark() ? 600 : 40;
		/** @phpstan-var list<array{\Closure(Location) : Mob, int, bool}> $plan */
		$plan = [
			[fn(Location $l) => new Cod($l), 10, true], [fn(Location $l) => new TropicalFish($l), 8, true], [fn(Location $l) => new Pufferfish($l), 2, true],
			[fn(Location $l) => new Wolf($l), 12, false], [fn(Location $l) => new Horse($l), 10, false], [fn(Location $l) => new Bee($l), 10, false],
			[fn(Location $l) => new VillagerV2($l), 10, false], [fn(Location $l) => new Rabbit($l), 8, false], [fn(Location $l) => new Parrot($l), 5, false],
			[fn(Location $l) => new Llama($l), 5, false], [fn(Location $l) => new Goat($l), 5, false], [fn(Location $l) => new Cat($l), 5, false],
			[fn(Location $l) => new Fox($l), 10, false],
		];
		$mobs = [];
		foreach($plan as [$create, $count, $water]){
			for($i = 0; $i < (int) ($count * $scale + 0.999); ++$i){
				$mob = $create($water ? $this->waterLoc() : $this->landLoc());
				$mob->spawnToAll();
				$mobs[] = $mob;
			}
		}
		$perMob = $this->measure(count($mobs), $ticks);
		self::assertGreaterThan(0, $perMob);
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[mob bench] %d mixed phase 2 mobs: %.3f ms per world tick, %.1f us per mob tick\n", count($mobs), $perMob * count($mobs) / 1e3, $perMob));
		}
	}

	public function testEachMobAlone() : void{
		if(!$this->isFullBenchmark()){
			self::assertNotEmpty(MobsPhase2::getMobs());
			return;
		}
		TimingsHandler::setEnabled(false);
		$results = [];
		foreach(MobsPhase2::getMobs() as [$class, $name, $create]){
			mt_srand(99);
			$aquatic = $class === Cod::class || $class === TropicalFish::class || $class === Pufferfish::class ||
				$class === Salmon::class || $class === Dolphin::class ||
				$class === Tadpole::class || $class === Nautilus::class;
			$mobs = [];
			for($i = 0; $i < 20; ++$i){
				$mob = $create($aquatic ? $this->waterLoc() : $this->landLoc(), null);
				$mob->spawnToAll();
				$mobs[] = $mob;
			}
			$results[$name] = $this->measure(count($mobs), 200);
			foreach($mobs as $mob){
				if(!$mob->isClosed()){
					$mob->close();
				}
			}
			foreach($this->world->getEntities() as $entity){
				$entity->close(); //babies, items, tadpoles...
			}
		}
		self::assertCount(count(MobsPhase2::getMobs()), $results);
		arsort($results);
		foreach(Utils::stringifyKeys($results) as $name => $us){
			fwrite(STDERR, sprintf("[mob bench] %-16s %.1f us per mob tick\n", $name, $us));
		}
	}
}
