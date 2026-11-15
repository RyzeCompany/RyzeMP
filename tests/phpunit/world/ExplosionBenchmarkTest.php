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

namespace pocketmine\world;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\timings\TimingsHandler;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Cost of a big TNT blast: the explosions themselves and the dropped items ticking afterwards. Small counts run as
 * normal tests; set MOB_BENCH=1 to blow up 64 TNT in solid stone and tick 200 more ticks.
 *
 * @group benchmark
 */
class ExplosionBenchmarkTest extends MobTestCase{

	/**
	 * @return bool[][]
	 * @phpstan-return array<string, array{bool}>
	 */
	public static function decayProvider() : array{
		return [
			"drop decay on (1 in 4 blocks drops)" => [true],
			"drop decay off (every block drops)" => [false]
		];
	}

	/**
	 * @dataProvider decayProvider
	 */
	public function testTntField(bool $dropDecay) : void{
		TimingsHandler::setEnabled(false);
		mt_srand(777);
		$full = getenv("MOB_BENCH") === "1";
		$side = $full ? 8 : 2;
		$ticks = $full ? 200 : 20;
		$this->world->setGameRule(GameRules::TNT_EXPLOSION_DROP_DECAY, $dropDecay);

		$stone = VanillaBlocks::STONE();
		$half = $side * 5 / 2 + 3;
		for($x = -$half; $x <= $half; ++$x){
			for($z = -$half; $z <= $half; ++$z){
				for($y = 1; $y <= 8; ++$y){
					$this->world->setBlockAt($x, $y, $z, $stone, false);
				}
			}
		}
		for($i = 0; $i < $side; ++$i){
			for($j = 0; $j < $side; ++$j){
				$x = -$half + 3 + $i * 5;
				$z = -$half + 3 + $j * 5;
				$this->world->setBlockAt($x, 5, $z, VanillaBlocks::AIR(), false);
				$tnt = new PrimedTNT(new Location($x + 0.5, 5, $z + 0.5, $this->world, 0, 0));
				$tnt->setFuse(2);
				$tnt->spawnToAll();
			}
		}

		$start = hrtime(true);
		$this->runTicks(5);
		$blast = hrtime(true) - $start;

		$items = 0;
		$stacks = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				++$items;
				$stacks += $entity->getItem()->getCount();
			}
		}
		self::assertGreaterThan(0, $stacks);

		$start = hrtime(true);
		$this->runTicks($ticks);
		$after = hrtime(true) - $start;

		$itemsAfter = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				++$itemsAfter;
			}
		}
		if($full){
			fwrite(STDERR, sprintf("[explosion bench] decay %s: %d TNT, blast %.2f ms, %d item entities (%d items), then %.3f ms per tick over %d ticks, %d item entities left\n",
				$dropDecay ? "on " : "off", $side * $side, $blast / 1e6, $items, $stacks, $after / $ticks / 1e6, $ticks, $itemsAfter));
		}
	}
}
