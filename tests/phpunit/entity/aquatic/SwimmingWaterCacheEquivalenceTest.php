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

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\NeverSavedWithChunkEntity;
use function count;
use function floor;
use function implode;
use function intdiv;
use function mt_rand;
use function mt_srand;
use function sprintf;

/**
 * The water kept by swimming mobs (see {@link \pocketmine\entity\ai\SwimmingMobTrait}) moves them exactly like the
 * lookups after every movement it replaces: the same mobs, the same seed and the same block changes (for nautiluses,
 * dolphins and tadpoles also without neighbour updates, so without the mobs being told) give the same positions,
 * motions and ground and collision flags on every tick, and the same random draws. Fish only forget the water on block
 * changes that notify them (see AbstractFish::initEntity()).
 */
final class SwimmingWaterCacheEquivalenceTest extends MobTestCase{
	private const TICKS = 360;
	private const MOBS = 6;

	/**
	 * Entity IDs of both runs start at a multiple of this (ID-based timings: every 20 or 40 ticks, push directions).
	 */
	private const ID_ALIGNMENT = 3840;

	/**
	 * @return \Closure[][]
	 * @phpstan-return array<string, array{\Closure(Location) : Mob, \Closure(Location) : Mob, bool}>
	 */
	public static function kinds() : array{
		return [
			"cod" => [
				fn(Location $l) : Mob => new class($l) extends Cod implements NeverSavedWithChunkEntity{},
				fn(Location $l) : Mob => new class($l) extends Cod implements NeverSavedWithChunkEntity{
					use SwimmingLookupsEveryMovement;
				},
				false
			],
			"tropical fish" => [
				fn(Location $l) : Mob => new class($l) extends TropicalFish implements NeverSavedWithChunkEntity{},
				fn(Location $l) : Mob => new class($l) extends TropicalFish implements NeverSavedWithChunkEntity{
					use SwimmingLookupsEveryMovement;
				},
				false
			],
			"nautilus" => [
				fn(Location $l) : Mob => new class($l) extends Nautilus implements NeverSavedWithChunkEntity{},
				fn(Location $l) : Mob => new class($l) extends Nautilus implements NeverSavedWithChunkEntity{
					use SwimmingLookupsEveryMovement;
				},
				true
			],
			"dolphin" => [
				fn(Location $l) : Mob => new class($l) extends Dolphin implements NeverSavedWithChunkEntity{},
				fn(Location $l) : Mob => new class($l) extends Dolphin implements NeverSavedWithChunkEntity{
					use SwimmingLookupsEveryMovement;
				},
				true
			],
			"tadpole" => [
				fn(Location $l) : Mob => new class($l) extends Tadpole implements NeverSavedWithChunkEntity{},
				fn(Location $l) : Mob => new class($l) extends Tadpole implements NeverSavedWithChunkEntity{
					use SwimmingLookupsEveryMovement;
				},
				true
			],
		];
	}

	/**
	 * @dataProvider kinds
	 * @phpstan-param \Closure(Location) : Mob $cached
	 * @phpstan-param \Closure(Location) : Mob $uncached
	 * @param bool $silentChanges whether some block changes are made without neighbour updates
	 */
	public function testSameMovementAsLookingUpAfterEveryMovement(\Closure $cached, \Closure $uncached, bool $silentChanges) : void{
		foreach([11, 12, 13] as $seed){
			$expected = $this->runScenario($uncached, $seed, $silentChanges);
			$actual = $this->runScenario($cached, $seed, $silentChanges);
			self::assertCount(count($expected), $actual, "seed $seed");
			foreach($expected as $tick => $line){
				self::assertSame($line, $actual[$tick], "seed $seed, tick $tick");
			}
		}
	}

	/**
	 * Runs the scenario in a fresh world and returns what the mobs did on each tick.
	 *
	 * @phpstan-param \Closure(Location) : Mob $create
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function runScenario(\Closure $create, int $seed, bool $silentChanges) : array{
		$this->tearDown();
		$this->setUp();
		$world = $this->world;
		//a pool with a pillar, a fence, a cobweb and kelp in it (not plain water: no water kept around them)
		for($x = 9; $x <= 23; ++$x){
			for($z = 9; $z <= 23; ++$z){
				for($y = 1; $y <= 5; ++$y){
					$wall = $x === 9 || $x === 23 || $z === 9 || $z === 23;
					$world->setBlockAt($x, $y, $z, $wall ? VanillaBlocks::STONE() : ($y <= 4 ? VanillaBlocks::WATER() : VanillaBlocks::AIR()), false);
				}
			}
		}
		for($y = 1; $y <= 4; ++$y){
			$world->setBlockAt(14, $y, 14, VanillaBlocks::STONE(), false);
		}
		$world->setBlockAt(18, 1, 18, VanillaBlocks::OAK_FENCE(), false);
		$world->setBlockAt(19, 3, 12, VanillaBlocks::COBWEB(), false);
		$world->setBlockAt(12, 1, 20, VanillaBlocks::KELP(), false);
		PathBlockTypes::clearCache();

		while(Entity::nextRuntimeId() % self::ID_ALIGNMENT !== self::ID_ALIGNMENT - 1){
			//same entity IDs modulo the alignment in both runs
		}
		mt_srand($seed);
		$mobs = [];
		for($i = 0; $i < self::MOBS; ++$i){
			$mob = $create(new Location(mt_rand(10, 22) + 0.3 + $i * 0.07, 2, mt_rand(10, 22) + 0.6 - $i * 0.05, $world, 0, 0));
			$mob->spawnToAll();
			$mobs[] = $mob;
		}

		$lines = [];
		for($t = 0; $t < self::TICKS; ++$t){
			if($t % 30 === 15){
				//a block next to one of the mobs (in its box sometimes), with $silentChanges without neighbour updates on every
				//other change: the mobs aren't told about those
				$mob = $mobs[intdiv($t, 30) % self::MOBS];
				if(!$mob->isClosed()){
					$pos = $mob->getPosition();
					$x = (int) floor($pos->x) + ($t % 60 === 15 ? 1 : 0);
					$y = (int) floor($pos->y);
					$z = (int) floor($pos->z);
					if($x > 9 && $x < 23 && $z > 9 && $z < 23 && $y >= 1 && $y <= 4){
						$block = $world->getBlockAt($x, $y, $z)->getTypeId() === VanillaBlocks::WATER()->getTypeId() ? self::obstacle($t) : VanillaBlocks::WATER();
						$world->setBlockAt($x, $y, $z, $block, !$silentChanges || $t % 90 === 15);
					}
				}
			}
			$this->runTicks(1);
			$line = [];
			foreach($mobs as $i => $mob){
				if($mob->isClosed()){
					$line[] = "$i closed";
					continue;
				}
				$loc = $mob->getLocation();
				$motion = $mob->getMotion();
				$line[] = sprintf("%d %.17g %.17g %.17g %.17g %.17g %.17g %d%d%d", $i, $loc->x, $loc->y, $loc->z, $motion->x, $motion->y, $motion->z,
					$mob->isOnGround() ? 1 : 0, $mob->isCollidedHorizontally ? 1 : 0, $mob->isCollidedVertically ? 1 : 0);
			}
			$lines[] = implode(" | ", $line);
		}
		$lines[] = "next draw " . mt_rand();
		foreach($mobs as $mob){
			if(!$mob->isClosed()){
				$mob->close();
			}
		}
		return $lines;
	}

	private static function obstacle(int $t) : Block{
		return match(intdiv($t, 30) % 3){
			0 => VanillaBlocks::STONE(),
			1 => VanillaBlocks::OAK_FENCE(),
			default => VanillaBlocks::OAK_SLAB()
		};
	}
}
