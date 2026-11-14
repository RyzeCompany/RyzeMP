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

use pocketmine\block\Block;
use pocketmine\block\Fire;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;
use function is_array;
use function max;
use function mt_rand;
use function mt_srand;

/**
 * Fire::onRandomTick() (also its scheduled update) skips spreadFire() when the subchunk palettes show that nothing within
 * its reach encourages flames (nether fires on netherrack): the result must be the same as running it, including the
 * numbers drawn from mt_rand().
 */
final class FireReachEquivalenceTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 3; //x/z -48..63 loaded, the chunks at 64 and -64 are not
	}

	/**
	 * @return Block[]
	 */
	private static function netherPalette() : array{
		return [
			VanillaBlocks::NETHERRACK(), VanillaBlocks::NETHERRACK(), VanillaBlocks::NETHERRACK(), VanillaBlocks::NETHERRACK(),
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(),
			VanillaBlocks::SOUL_SAND(), VanillaBlocks::GLOWSTONE(), VanillaBlocks::MAGMA(), VanillaBlocks::NETHER_BRICKS(),
			VanillaBlocks::NETHER_BRICK_FENCE(), VanillaBlocks::CRIMSON_STEM(), VanillaBlocks::CRIMSON_PLANKS(),
			VanillaBlocks::BLACKSTONE(), VanillaBlocks::NETHER_WART_BLOCK(), VanillaBlocks::WEEPING_VINES(),
			VanillaBlocks::NETHER_QUARTZ_ORE(), VanillaBlocks::GRAVEL(), VanillaBlocks::CHEST(), VanillaBlocks::LAVA(),
		];
	}

	/**
	 * @return Block[]
	 */
	private static function flammablePalette() : array{
		return [
			VanillaBlocks::OAK_PLANKS(), VanillaBlocks::OAK_LOG(), VanillaBlocks::WOOL(), VanillaBlocks::OAK_LEAVES(),
			VanillaBlocks::HAY_BALE(), VanillaBlocks::BOOKSHELF(), VanillaBlocks::TALL_GRASS(), VanillaBlocks::COAL(),
			VanillaBlocks::LECTERN(),
		];
	}

	/**
	 * Replaces every loaded chunk by an empty one (fresh palettes), then fills the box with a random mix of the palette,
	 * with fires of random ages in some of the air cells (on netherrack and magma they burn forever, on air they go out).
	 *
	 * @param Block[] $palette
	 * @param Block[] $flammables placed with a 1/$flammableRarity chance per block, only where $flammableAt says so
	 * @phpstan-param \Closure(int, int, int) : bool $flammableAt
	 */
	private function build(int $seed, int $minX, int $maxX, int $minY, int $maxY, int $minZ, int $maxZ, array $palette, array $flammables, int $flammableRarity, \Closure $flammableAt, int $fireRarity) : void{
		$radius = $this->getChunkRadius();
		for($chunkX = -$radius; $chunkX <= $radius; ++$chunkX){
			for($chunkZ = -$radius; $chunkZ <= $radius; ++$chunkZ){
				$this->world->setChunk($chunkX, $chunkZ, new Chunk([], true));
			}
		}
		$random = new Random($seed);
		$fire = VanillaBlocks::FIRE();
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					if(count($flammables) > 0 && $flammableAt($x, $y, $z) && $random->nextBoundedInt($flammableRarity) === 0){
						$block = $flammables[$random->nextBoundedInt(count($flammables))];
					}else{
						$block = $palette[$random->nextBoundedInt(count($palette))];
					}
					if($block->getTypeId() === VanillaBlocks::AIR()->getTypeId() && $y > $minY && $random->nextBoundedInt($fireRarity) === 0){
						$block = $fire->setAge($random->nextBoundedInt(Fire::MAX_AGE + 1));
					}
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	/**
	 * Fire::onRandomTick() without the reach check, always running spreadFire() after burnBlocksAround().
	 */
	private function referenceRandomTick(Fire $fire) : void{
		$isNearRain = new \ReflectionMethod(Fire::class, "isNearRain");
		$hasAdjacentFlammableBlocks = new \ReflectionMethod(Fire::class, "hasAdjacentFlammableBlocks");
		$burnBlocksAround = new \ReflectionMethod(Fire::class, "burnBlocksAround");
		$spreadFire = new \ReflectionMethod(Fire::class, "spreadFire");
		$recalculateConnections = new \ReflectionMethod(Fire::class, "recalculateConnections");
		$position = $fire->getPosition();
		$world = $position->getWorld();

		$down = $fire->getSide(Facing::DOWN);
		if(!$down->burnsForever() && Utils::getRandomFloat() < 0.2 + $fire->getAge() * 0.03 && $isNearRain->invoke(null, $world, $position->getFloorX(), $position->getFloorY(), $position->getFloorZ()) === true){
			$world->setBlock($position, VanillaBlocks::AIR());
			return;
		}

		$result = null;
		//1.26.60 shapes: the fires of build() have none (placed without updates), the update gives them theirs
		if($recalculateConnections->invoke($fire, $down) === true){
			$result = $fire;
		}
		if($fire->getAge() < Fire::MAX_AGE && mt_rand(0, 2) === 0){
			$fire->setAge($fire->getAge() + 1);
			$result = $fire;
		}
		$canSpread = true;

		if(!$down->burnsForever()){
			if($fire->getAge() === Fire::MAX_AGE){
				if(!$down->isFlammable() && mt_rand(0, 3) === 3){
					$canSpread = false;
					$result = VanillaBlocks::AIR();
				}
			}elseif($hasAdjacentFlammableBlocks->invoke($fire) === false){
				$canSpread = false;
				if($down->isTransparent() || $fire->getAge() > 3){
					$result = VanillaBlocks::AIR();
				}
			}
		}

		if($result !== null){
			$world->setBlock($position, $result);
		}

		$world->scheduleDelayedBlockUpdate($position, mt_rand(30, 40));

		if($canSpread){
			//the test world is not humid
			$burnBlocksAround->invoke($fire, false);
			$spreadFire->invoke($fire, false);
		}
	}

	/**
	 * Updates every fire of the box (in a fixed order) $rounds times, with the old or the new code.
	 *
	 * @return int[][] the final block state IDs of the box (one block around it included), and the next numbers of mt_rand()
	 * @phpstan-return array{list<int>, list<int>}
	 */
	private function updateFires(bool $useReference, int $seed, int $rounds, int $minX, int $maxX, int $minY, int $maxY, int $minZ, int $maxZ, int &$skips, int &$updates) : array{
		$mayEncourageFlamesInReach = new \ReflectionMethod(Fire::class, "mayEncourageFlamesInReach");
		mt_srand($seed);
		for($round = 0; $round < $rounds; ++$round){
			$fires = [];
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					for($y = $minY; $y <= $maxY; ++$y){
						$block = $this->world->getBlockAt($x, $y, $z);
						if($block instanceof Fire){
							$fires[] = $block;
						}
					}
				}
			}
			foreach($fires as $fire){
				$position = $fire->getPosition();
				$fire = $this->world->getBlockAt($position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
				if(!$fire instanceof Fire){
					continue; //burnt out or replaced by a fire update earlier in the round
				}
				++$updates;
				if($useReference){
					$this->referenceRandomTick($fire);
				}else{
					if($mayEncourageFlamesInReach->invoke($fire) === false){
						++$skips; //before burnBlocksAround(), which may still change it: only to see that both paths run
					}
					$fire->onRandomTick();
				}
			}
		}

		$states = [];
		for($x = $minX - 1; $x <= $maxX + 1; ++$x){
			for($z = $minZ - 1; $z <= $maxZ + 1; ++$z){
				for($y = $minY - 1; $y <= $maxY + 1; ++$y){
					$states[] = $this->world->getBlockAt($x, $y, $z)->getStateId();
				}
			}
		}
		$random = [];
		for($i = 0; $i < 8; ++$i){
			$random[] = mt_rand();
		}
		return [$states, $random];
	}

	/**
	 * Builds the same box twice and checks that the old and the new code end with the same blocks and the same mt_rand()
	 * state.
	 *
	 * @param Block[] $flammables
	 * @phpstan-param \Closure(int, int, int) : bool $flammableAt
	 *
	 * @return int[] skipped updates, updates (of the new code)
	 */
	private function compare(string $name, int $seed, int $rounds, int $minX, int $maxX, int $minY, int $maxY, int $minZ, int $maxZ, array $flammables, int $flammableRarity, \Closure $flammableAt, int $fireRarity) : array{
		$skips = 0;
		$referenceUpdates = 0;
		$updates = 0;
		$this->build($seed, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, self::netherPalette(), $flammables, $flammableRarity, $flammableAt, $fireRarity);
		[$before, ] = $this->updateFires(true, $seed, 0, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, $skips, $referenceUpdates);
		[$expectedBlocks, $expectedRandom] = $this->updateFires(true, $seed, $rounds, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, $skips, $referenceUpdates);
		$this->build($seed, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, self::netherPalette(), $flammables, $flammableRarity, $flammableAt, $fireRarity);
		[$actualBlocks, $actualRandom] = $this->updateFires(false, $seed, $rounds, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, $skips, $updates);
		self::assertSame($referenceUpdates, $updates, "$name: same number of fire updates");
		self::assertSame($expectedBlocks, $actualBlocks, "$name: same blocks");
		self::assertSame($expectedRandom, $actualRandom, "$name: same mt_rand() state");
		self::assertGreaterThan(50, $updates, "$name: the box should have fires");
		self::assertNotSame($before, $expectedBlocks, "$name: the fires should change blocks");
		return [$skips, $updates];
	}

	public function testNetherFiresMatchFullUpdate() : void{
		$nowhere = fn(int $x, int $y, int $z) : bool => false;
		$totalSkips = 0;
		$totalUpdates = 0;
		for($seed = 1; $seed <= 2; ++$seed){
			//across chunk (x/z 0) and subchunk (y 0) borders
			[$skips, $updates] = $this->compare("nether $seed", $seed, 20, -10, 9, -6, 9, -10, 9, [], 1, $nowhere, 6);
			$totalSkips += $skips;
			$totalUpdates += $updates;
		}
		//only nether blocks: every fire that can spread skips spreadFire()
		self::assertGreaterThan(0.5, $totalSkips / $totalUpdates, "most nether fires should skip spreadFire()");
	}

	public function testMixedFiresMatchFullUpdate() : void{
		$skips = 0;
		$updates = 0;
		$flammables = self::flammablePalette();
		$cases = [
			//flammable blocks only in the subchunk y 0..15 of the chunk x 0..15, z 0..15: fires elsewhere skip
			"one flammable subchunk" => [fn(int $x, int $y, int $z) : bool => $x >= 0 && $z >= 0 && $y >= 0, 3],
			//a few flammable blocks everywhere: fires burn and spread
			"sparse flammables" => [fn(int $x, int $y, int $z) : bool => true, 25],
			//many: most fires near something that burns, wood burning out
			"dense flammables" => [fn(int $x, int $y, int $z) : bool => true, 3],
		];
		foreach($cases as $name => [$flammableAt, $rarity]){
			for($seed = 10; $seed <= 11; ++$seed){
				[$s, $u] = $this->compare("$name $seed", $seed, 15, -12, 11, -5, 10, -12, 11, $flammables, $rarity, $flammableAt, 6);
				$skips += $s;
				$updates += $u;
			}
		}
		self::assertGreaterThan(0, $skips, "some fires should skip spreadFire()");
		self::assertLessThan($updates, $skips, "some fires should run spreadFire()");
	}

	public function testFiresAtTheEdgesMatchFullUpdate() : void{
		$flammables = self::flammablePalette();
		$anywhere = fn(int $x, int $y, int $z) : bool => true;
		$nowhere = fn(int $x, int $y, int $z) : bool => false;
		foreach([
			//next to the unloaded chunks from x 64 and z -49
			"unloaded chunks" => [56, 63, 0, 8, -48, -41],
			//bottom and top of the world (-64..319)
			"world bottom" => [-6, 6, -64, -56, -6, 6],
			"world top" => [-6, 6, 311, 319, -6, 6],
		] as $name => [$minX, $maxX, $minY, $maxY, $minZ, $maxZ]){
			foreach([[$nowhere, 1], [$anywhere, 20]] as $i => [$flammableAt, $rarity]){
				$this->compare("$name $i", 20 + $i, 20, $minX, $maxX, $minY, $maxY, $minZ, $maxZ, $flammables, $rarity, $flammableAt, 5);
			}
		}
	}

	/**
	 * Whenever the palette check says nothing encourages flames, no block of the spread grid (x/z -2..+2, y -2..+5) has a
	 * flame encouragement (the grid matches World::getBlockAt(), see PerformanceEquivalenceTest).
	 */
	public function testReachCheckIsConservative() : void{
		$mayEncourageFlamesInReach = new \ReflectionMethod(Fire::class, "mayEncourageFlamesInReach");
		$flammables = self::flammablePalette();
		$yes = 0;
		$no = 0;
		$wrong = [];
		foreach([
			[30, fn(int $x, int $y, int $z) : bool => $x >= 0 && $z >= 0 && $y >= 0 && $y < 16],
			[300, fn(int $x, int $y, int $z) : bool => true],
		] as $i => [$rarity, $flammableAt]){
			$this->build(40 + $i, -20, 19, -6, 21, -20, 19, self::netherPalette(), $flammables, $rarity, $flammableAt, 4);
			for($x = -20; $x <= 19; ++$x){
				for($z = -20; $z <= 19; ++$z){
					for($y = -6; $y <= 21; ++$y){
						$fire = $this->world->getBlockAt($x, $y, $z);
						if(!$fire instanceof Fire){
							continue;
						}
						if($mayEncourageFlamesInReach->invoke($fire) !== false){
							++$no;
							continue;
						}
						++$yes;
						for($dx = -2; $dx <= 2; ++$dx){
							for($dz = -2; $dz <= 2; ++$dz){
								for($dy = -2; $dy <= 5; ++$dy){
									$block = $this->world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
									if($block->getFlameEncouragement() > 0){
										$wrong[] = $block->getName() . " at " . ($x + $dx) . " " . ($y + $dy) . " " . ($z + $dz) . " for the fire at $x $y $z";
									}
								}
							}
						}
					}
				}
			}
		}
		self::assertSame([], $wrong, "flame encouraging blocks within reach of a fire that skips spreadFire()");
		self::assertGreaterThan(100, $yes, "spreadFire() should be skipped for many fires");
		self::assertGreaterThan(100, $no, "spreadFire() should run for many fires");
	}

	public function testEncouragementCacheStaysSmall() : void{
		$encouragesFlames = new \ReflectionMethod(Fire::class, "encouragesFlames");
		$cache = new \ReflectionProperty(Fire::class, "encouragesFlamesByStateId");
		$encouraging = 0;
		$maxSize = 0;
		$wrong = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $block){
			$expected = $block->getFlameEncouragement() > 0;
			if($encouragesFlames->invoke(null, $stateId) !== $expected){
				$wrong[] = $block->getName() . " ($stateId)";
			}
			$cached = $cache->getValue();
			$maxSize = max($maxSize, is_array($cached) ? count($cached) : -1);
			unset($cached);
			$encouraging += $expected ? 1 : 0;
		}
		self::assertSame([], $wrong, "states that encourage flames");
		self::assertGreaterThan(100, $encouraging);
		self::assertSame(1024, $maxSize, "the cache is emptied when full");
	}
}
