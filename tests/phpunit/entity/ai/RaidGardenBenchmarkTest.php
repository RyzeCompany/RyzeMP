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

use pocketmine\block\Carrot;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\RaidGardenGoal;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Rabbit;
use pocketmine\entity\Location;
use function getenv;
use function hrtime;
use function printf;

/**
 * Cost of a raid_garden search (findNearest) with targets near, far and out of the searched height. Run with
 * MOB_BENCH=1.
 */
final class RaidGardenBenchmarkTest extends MobTestCase{
	private const ROUNDS = 300;

	protected function setUp() : void{
		if(getenv("MOB_BENCH") === false){
			self::markTestSkipped("MOB_BENCH not set");
		}
		parent::setUp();
	}

	private function time(RaidGardenGoal $goal, int $x, int $y, int $z) : float{
		$goal->findNearest($this->world, $x, $y, $z);
		$start = hrtime(true);
		for($i = 0; $i < self::ROUNDS; ++$i){
			$goal->findNearest($this->world, $x, $y, $z);
		}
		return (hrtime(true) - $start) / self::ROUNDS / 1000;
	}

	public function testSearchCost() : void{
		$fox = new Fox(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$bush = VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE)->getStateId();
		$foxGoal = new RaidGardenGoal($fox, fn(int $s) => $s === $bush, fn() => true, searchRange: 12, searchHeight: 2);
		//one ripe bush in the middle of each chunk, at the fox's level
		foreach([-16, 0] as $cx){
			foreach([-16, 0] as $cz){
				$this->set($cx + 8, 1, $cz + 8, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
			}
		}
		$bushes = $this->time($foxGoal, 0, 1, 0);
		//the same bushes 9 blocks up, out of the searched height (same subchunk: the palette check passes)
		foreach([-16, 0] as $cx){
			foreach([-16, 0] as $cz){
				$this->set($cx + 8, 1, $cz + 8, VanillaBlocks::AIR());
				$this->set($cx + 8, 10, $cz + 8, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
			}
		}
		$miss = $this->time($foxGoal, 0, 1, 0);

		$rabbit = new Rabbit(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$carrot = VanillaBlocks::CARROTS()->setAge(Carrot::MAX_AGE)->getStateId();
		$rabbitGoal = new RaidGardenGoal($rabbit, fn(int $s) => $s === $carrot, fn() => true, searchRange: 16, searchHeight: 1);
		for($x = 3; $x <= 9; ++$x){
			for($z = 3; $z <= 9; ++$z){
				$this->set($x, 0, $z, VanillaBlocks::FARMLAND());
				$this->set($x, 1, $z, VanillaBlocks::CARROTS()->setAge(Carrot::MAX_AGE));
			}
		}
		$farm = $this->time($rabbitGoal, 0, 1, 0);
		printf("\nraid_garden search: bushes per chunk %.1f us, out of height %.1f us, rabbit by a carrot farm %.1f us\n", $bushes, $miss, $farm);
		self::assertNotNull($rabbitGoal->findNearest($this->world, 0, 1, 0));
		self::assertNull($foxGoal->findNearest($this->world, 0, 1, 0));
	}
}
