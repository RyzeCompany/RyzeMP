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
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\Location;
use pocketmine\timings\TimingsHandler;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of fish, the most numerous mobs (ocean chunks): with MOB_BENCH=1, 200 fish (cod, tropical fish, salmon)
 * swim in a 41x41x6 pool for 600 ticks (schools form, leaders wander, followers follow); a few fish for 40 ticks
 * otherwise. Variants: an axolotl in a chunk far from the pool, and a pool with pillars and a rough floor.
 *
 * @group benchmark
 */
final class FishBenchmarkTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		for($x = -20; $x <= 20; ++$x){
			for($z = -20; $z <= 20; ++$z){
				for($y = 1; $y <= 6; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	public function testManyFish() : void{
		$this->runFish("open pool");
	}

	public function testManyFishWithAxolotlElsewhere() : void{
		//in a chunk the fish never come near, in the same world
		for($y = 1; $y <= 3; ++$y){
			$this->world->setBlockAt(45, $y, 45, VanillaBlocks::WATER(), false);
		}
		(new Axolotl(new Location(45.5, 1.2, 45.5, $this->world, 0, 0)))->spawnToAll();
		$this->runFish("axolotl elsewhere");
	}

	public function testManyFishAroundPillars() : void{
		mt_srand(77);
		for($x = -20; $x <= 20; ++$x){
			for($z = -20; $z <= 20; ++$z){
				if($x % 6 === 0 && $z % 6 === 0){
					for($y = 1; $y <= 6; ++$y){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
					}
				}elseif(mt_rand(0, 3) === 0){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
		$this->runFish("pillars");
	}

	private function runFish(string $name) : void{
		$full = getenv("MOB_BENCH") === "1";
		TimingsHandler::setEnabled(false);
		mt_srand(2024);
		$count = $full ? 200 : 20;
		$ticks = $full ? 600 : 40;
		for($i = 0; $i < $count; ++$i){
			$loc = new Location(mt_rand(-19, 19) + 0.5, mt_rand(2, 5) + 0.2, mt_rand(-19, 19) + 0.5, $this->world, mt_rand(0, 359), 0);
			$fish = match($i % 5){
				0, 1 => new Cod($loc),
				2, 3 => new TropicalFish($loc),
				default => new Salmon($loc)
			};
			if($fish instanceof TropicalFish && $i % 10 < 6){
				$fish->setAppearance(0, 1, 1, 7); //a few shared looks, like natural herds
			}
			$fish->spawnToAll();
		}
		$this->runTicks(20);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$perMob = (hrtime(true) - $start) / $ticks / $count / 1e3;
		self::assertGreaterThan(0, $perMob);
		if($full){
			//share of the mob ticks with a movement (after the timed ticks, not to slow them down)
			$previous = [];
			$moved = 0;
			for($t = 0; $t <= 100; ++$t){
				foreach($this->world->getEntities() as $id => $entity){
					$pos = $entity->getPosition();
					if($t > 0 && isset($previous[$id]) && !$pos->equals($previous[$id])){
						++$moved;
					}
					$previous[$id] = $pos;
				}
				$this->runTicks(1);
			}
			fwrite(STDERR, sprintf("[mob bench] %s, %d fish: %.3f ms per world tick, %.1f us per mob tick, %.0f%% of the mob ticks moving\n", $name, $count, $perMob * $count / 1e3, $perMob, $moved / $count));
		}
	}
}
