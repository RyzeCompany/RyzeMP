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

namespace pocketmine\entity\aquatic;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\Squid;
use pocketmine\timings\TimingsHandler;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of squids and guardians in a pool, with cod as the unchanged control. Small counts run as normal tests;
 * with MOB_BENCH=1, 40 of each for 400 ticks, printed to stderr.
 *
 * @group benchmark
 */
class SquidGuardianBenchmarkTest extends MobTestCase{

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	protected function setUp() : void{
		parent::setUp();
		//a pool walled with stone up to the surface (y 7)
		for($x = -13; $x <= 13; ++$x){
			for($z = -13; $z <= 13; ++$z){
				$wall = $x === -13 || $x === 13 || $z === -13 || $z === 13;
				for($y = 1; $y <= 7; ++$y){
					$this->world->setBlockAt($x, $y, $z, $wall ? VanillaBlocks::STONE() : ($y <= 6 ? VanillaBlocks::WATER() : VanillaBlocks::AIR()), false);
				}
			}
		}
	}

	/**
	 * @phpstan-param \Closure(Location) : Entity $create
	 */
	private function measure(\Closure $create) : float{
		TimingsHandler::setEnabled(false);
		mt_srand(777);
		$count = $this->isFullBenchmark() ? 40 : 4;
		$ticks = $this->isFullBenchmark() ? 400 : 20;
		$entities = [];
		for($i = 0; $i < $count; ++$i){
			$entity = $create(new Location(mt_rand(-10, 10) + 0.5, mt_rand(2, 5), mt_rand(-10, 10) + 0.5, $this->world, 0, 0));
			$entity->spawnToAll();
			$entities[] = $entity;
		}
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$perEntity = (hrtime(true) - $start) / $ticks / $count / 1e3;
		foreach($entities as $entity){
			$entity->close();
		}
		return $perEntity;
	}

	public function testSquidsGuardiansAndCod() : void{
		$squid = $this->measure(fn(Location $l) => new Squid($l));
		$guardian = $this->measure(fn(Location $l) => new Guardian($l));
		$cod = $this->measure(fn(Location $l) => new Cod($l));
		self::assertGreaterThan(0, $squid);
		if($this->isFullBenchmark()){
			fwrite(STDERR, sprintf("[mob bench] us per tick: squid %.2f, guardian %.2f, cod (control) %.2f\n", $squid, $guardian, $cod));
		}
	}
}
