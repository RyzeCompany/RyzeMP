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

use pocketmine\block\Block;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\NavigationOptions;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathFinder;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\Random;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function min;
use function sprintf;
use const PHP_INT_MAX;
use const STDERR;

/**
 * Cost of path searches on open ground, open ground with a few dangerous blocks and uneven terrain (the terrain of
 * {@link PathFinderEquivalenceTest}), with the finder's defaults and with the navigation of a few mobs, in PHP and
 * natively when chunkutils2 has a native search (villagers, which open doors, always in PHP). Small runs are normal tests; set MOB_BENCH=1 for 5 rounds of 200 searches per case, printing the best round
 * in microseconds per search.
 *
 * @group benchmark
 */
final class PathFinderBenchmarkTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 2;
	}

	protected function tearDown() : void{
		PathFinder::setNativeEnabled(true);
		parent::tearDown();
	}

	/**
	 * @param int $dangerChance 1 in how many blocks above open ground are cactus, lit campfires, sweet berry bushes or lava
	 *                          (0: none)
	 */
	private function terrain(int $seed, bool $flat, int $dangerChance = 0) : void{
		$random = new Random($seed);
		/** @var Block[] $palette */
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_SLAB(),
			VanillaBlocks::WATER(), VanillaBlocks::LAVA(), VanillaBlocks::CACTUS(), VanillaBlocks::OAK_FENCE(),
			VanillaBlocks::GLASS(), VanillaBlocks::TALL_GRASS(), VanillaBlocks::SNOW_LAYER(), VanillaBlocks::OAK_LEAVES(),
		];
		for($x = -24; $x <= 40; ++$x){
			for($z = -24; $z <= 40; ++$z){
				$this->world->setBlockAt($x, 0, $z, $flat || $random->nextBoundedInt(12) !== 0 ? VanillaBlocks::STONE() : VanillaBlocks::AIR(), false);
				for($y = 1; $y <= 4; ++$y){
					$block = VanillaBlocks::AIR();
					if(!$flat && $random->nextBoundedInt(5) === 0){
						$block = $palette[$random->nextBoundedInt(count($palette))];
					}elseif($flat && $y === 1 && $dangerChance > 0 && $random->nextBoundedInt($dangerChance) === 0){
						$block = [VanillaBlocks::CACTUS(), VanillaBlocks::CAMPFIRE(), VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE), VanillaBlocks::LAVA()][$random->nextBoundedInt(4)];
					}elseif($flat && $y === 1 && $random->nextBoundedInt(40) === 0){
						$block = VanillaBlocks::STONE(); //a few scattered blocks
					}
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	public function testSearchCost() : void{
		$full = getenv("MOB_BENCH") === "1";
		$rounds = $full ? 5 : 1;
		$searches = $full ? 200 : 20;
		[$hasNative] = PathFinder::getNativeSupport();
		$cases = [
			//footprint, head blocks, max drop, options (null: the finder's defaults, every DANGER block avoided), opens doors
			"default" => [1, 1, 3, null, false],
			"zombie" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::ZOMBIE), false],
			"cow" => [1, 1, 3, NavigationOptions::forEntity(EntityIds::COW), false],
			"iron golem" => [2, 2, 1, NavigationOptions::forEntity(EntityIds::IRON_GOLEM), false],
			//searches in PHP: villagers open doors
			"villager" => [1, 1, 1, NavigationOptions::forEntity(EntityIds::VILLAGER_V2), true],
		];
		//open ground first: its sections have nothing dangerous in their palettes yet (the danger margin skips them, as in most
		//of a world); then a few dangerous blocks (a village with campfires and berry bushes, a desert with cactus), and the
		//uneven terrain, where lava and cactus are next to most nodes
		foreach(["open" => [true, 0], "village" => [true, 400], "desert" => [true, 100], "uneven" => [false, 0]] as $terrainName => [$flat, $dangerChance]){
			PathBlockTypes::clearCache();
			$this->terrain(4242, $flat, $dangerChance);
			foreach($hasNative ? [false, true] : [false] as $native){
				PathFinder::setNativeEnabled($native);
				foreach($cases as $caseName => [$footprint, $headBlocks, $maxDrop, $options, $opensDoors]){
					if($native && $opensDoors){
						continue;
					}
					$finder = new PathFinder($this->world, $headBlocks, $maxDrop, true, false, 400);
					if($options !== null){
						$finder->applyOptions($options);
					}
					$finder->setFootprint($footprint);
					$finder->setCanOpenDoors($opensDoors);
					$best = PHP_INT_MAX;
					$visited = 0;
					for($round = 0; $round < $rounds; ++$round){
						$random = new Random(99);
						$visited = 0;
						$start = hrtime(true);
						for($i = 0; $i < $searches; ++$i){
							$sx = $random->nextRange(-20, 36);
							$sz = $random->nextRange(-20, 36);
							$tx = $sx + $random->nextRange(-16, 16);
							$tz = $sz + $random->nextRange(-16, 16);
							$finder->findPath($sx, 1, $sz, $tx, 1, $tz, 1.0);
							$visited += $finder->getLastVisitedCount();
						}
						$best = min($best, hrtime(true) - $start);
					}
					self::assertGreaterThan(0, $visited);
					if($full){
						fwrite(STDERR, sprintf("[path bench] %s %s %s: %.1f us per search, %.1f nodes per search\n", $native ? "native" : "php", $terrainName, $caseName, $best / $searches / 1e3, $visited / $searches));
					}
				}
			}
		}
	}
}
