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

namespace pocketmine\block;

use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\generator\hell\Nether;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function getenv;
use function hrtime;
use function min;
use function mt_rand;
use function mt_srand;
use function printf;
use const PHP_INT_MAX;

/**
 * Cost of a random tick hitting lava in a Nether lava sea (lava up to y=31 over netherrack), the way World::tickChunk()
 * does it: block from the state ID, positioned, onRandomTick(). Set NETHER_BENCH=1 to print the numbers.
 */
class LavaRandomTickBenchmarkTest extends RedstoneTestCase{

	protected function getDimension() : Dimension{
		return Dimension::NETHER;
	}

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false]];
	}

	public function testLavaSeaRandomTicks() : void{
		$bench = getenv("NETHER_BENCH") === "1";
		$lava = VanillaBlocks::LAVA()->getStillForm();
		$netherrack = VanillaBlocks::NETHERRACK();
		for($x = -16; $x < 32; ++$x){
			for($z = -16; $z < 32; ++$z){
				for($y = 0; $y < 32; ++$y){
					$this->world->setBlockAt($x, $y, $z, $y < 8 ? $netherrack : $lava, false);
				}
			}
		}
		$registry = RuntimeBlockStateRegistry::getInstance();
		$hits = $bench ? 500_000 : 2_000;
		$best = PHP_INT_MAX;
		$control = PHP_INT_MAX;
		for($run = 0; $run < ($bench ? 5 : 1); ++$run){
			mt_srand(42);
			$start = hrtime(true);
			for($i = 0; $i < $hits; ++$i){
				$x = mt_rand(-16, 31);
				$y = mt_rand(8, 31);
				$z = mt_rand(-16, 31);
				$block = $registry->fromStateId($this->world->getChunk($x >> 4, $z >> 4)?->getBlockStateId($x & 0xf, $y, $z & 0xf) ?? 0);
				$block->position($this->world, $x, $y, $z);
				$block->onRandomTick();
			}
			$best = min($best, hrtime(true) - $start);

			//the same picks without the tick itself
			mt_srand(42);
			$start = hrtime(true);
			for($i = 0; $i < $hits; ++$i){
				$x = mt_rand(-16, 31);
				$y = mt_rand(8, 31);
				$z = mt_rand(-16, 31);
				$block = $registry->fromStateId($this->world->getChunk($x >> 4, $z >> 4)?->getBlockStateId($x & 0xf, $y, $z & 0xf) ?? 0);
				$block->position($this->world, $x, $y, $z);
			}
			$control = min($control, hrtime(true) - $start);
		}
		if($bench){
			printf("\nlava random tick: %.3f us per hit, %.3f us of it picking the block (best of 5, %d hits)\n", $best / 1000 / $hits, $control / 1000 / $hits, $hits);
		}
		//a lava sea has nothing to burn
		self::assertNotInstanceOf(Fire::class, $this->get(0, 32, 0));
	}

	/**
	 * Random ticks of whole chunks made by the Nether generator (World::tickChunk()), in interleaved modes: lava
	 * random-ticked (doFireTick on), doFireTick off, lava not random-ticked at all (as before lava started fires), and
	 * lava random-ticked with a flammable block in reach of all of it.
	 */
	public function testNetherChunkTicks() : void{
		$bench = getenv("NETHER_BENCH") === "1";
		$generator = new Nether(1234, "");
		$manager = new SimpleChunkManager(0, 128);
		$radius = $this->getChunkRadius();
		for($cx = -$radius; $cx <= $radius; ++$cx){
			for($cz = -$radius; $cz <= $radius; ++$cz){
				$manager->setChunk($cx, $cz, new Chunk([], false));
				$generator->generateChunk($manager, $cx, $cz);
				$chunk = $manager->getChunk($cx, $cz);
				self::assertNotNull($chunk);
				$this->world->setChunk($cx, $cz, $chunk);
			}
		}
		$tickChunk = \Closure::bind(function(int $chunkX, int $chunkZ) : void{
			$this->tickChunk($chunkX, $chunkZ);
		}, $this->world, World::class);
		//the world age doesn't advance here: the cached answers of World::canLavaIgnite() are dropped by hand
		$forgetLavaIgnition = \Closure::bind(function() : void{
			$this->lavaIgnitionSubChunkCache = new \WeakMap();
			$this->randomTickSubChunkCache = new \WeakMap();
		}, $this->world, World::class);
		$lavaStates = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state){
			if($state instanceof Lava){
				$lavaStates[] = $state;
			}
		}
		//a wool block in the bottom two subchunks of every chunk (walled in, it never burns) puts every lava block of the
		//sea in reach of something flammable: the worst case, where every lava random tick runs
		$netherrack = VanillaBlocks::NETHERRACK();
		$woolSpots = [];
		for($cx = -$radius; $cx <= $radius; ++$cx){
			for($cz = -$radius; $cz <= $radius; ++$cz){
				foreach([2, 18] as $y){
					$x = $cx * 16 + 8;
					$z = $cz * 16 + 8;
					for($dx = -1; $dx <= 1; ++$dx){
						for($dy = -1; $dy <= 1; ++$dy){
							for($dz = -1; $dz <= 1; ++$dz){
								$this->world->setBlockAt($x + $dx, $y + $dy, $z + $dz, $netherrack, false);
							}
						}
					}
					$woolSpots[] = [$x, $y, $z];
				}
			}
		}

		$ticks = $bench ? 400 : 5;
		$modes = ["lava ticked", "doFireTick off", "lava not ticked", "lava ticked, wool in reach"];
		$best = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
		for($run = 0; $run < ($bench ? 7 : 1); ++$run){
			foreach($modes as $mode => $_){
				foreach($woolSpots as [$x, $y, $z]){
					$this->world->setBlockAt($x, $y, $z, $mode === 3 ? VanillaBlocks::WOOL() : $netherrack, false);
				}
				$forgetLavaIgnition();
				$this->world->setGameRule(GameRules::DO_FIRE_TICK, true);
				foreach($lavaStates as $state){
					$mode === 2 ? $this->world->removeRandomTickedBlock($state) : $this->world->addRandomTickedBlock($state);
				}
				if($mode === 1){
					$this->world->setGameRule(GameRules::DO_FIRE_TICK, false);
				}
				mt_srand(42);
				$start = hrtime(true);
				for($i = 0; $i < $ticks; ++$i){
					for($cx = -$radius; $cx <= $radius; ++$cx){
						for($cz = -$radius; $cz <= $radius; ++$cz){
							$tickChunk($cx, $cz);
						}
					}
				}
				$best[$mode] = min($best[$mode], hrtime(true) - $start);
			}
		}
		$chunkTicks = $ticks * (2 * $radius + 1) ** 2;
		if($bench){
			echo "\n";
			foreach($modes as $mode => $name){
				printf("nether chunk tick, %s: %.3f us per chunk (best of 7, %d chunk ticks)\n", $name, $best[$mode] / 1000 / $chunkTicks, $chunkTicks);
			}
		}
		self::assertTrue($this->world->getGameRule(GameRules::DO_FIRE_TICK));
		foreach($woolSpots as [$x, $y, $z]){
			self::assertNotInstanceOf(Fire::class, $this->get($x, $y + 1, $z));
		}
	}

	public function testFireTickRuleTogglesLavaRandomTicks() : void{
		$lava = VanillaBlocks::LAVA()->getStillForm();
		self::assertArrayHasKey($lava->getStateId(), $this->world->getRandomTickedBlocks());
		$this->world->setGameRule(GameRules::DO_FIRE_TICK, false);
		self::assertArrayNotHasKey($lava->getStateId(), $this->world->getRandomTickedBlocks());
		$this->world->setGameRule(GameRules::DO_FIRE_TICK, true);
		self::assertArrayHasKey($lava->getStateId(), $this->world->getRandomTickedBlocks());
	}
}
