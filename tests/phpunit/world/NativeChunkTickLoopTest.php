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
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\RandomTickSampler;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_keys;
use function array_map;
use function class_exists;
use function count;
use function hash_final;
use function hash_init;
use function hash_update;
use function implode;
use function method_exists;
use function mt_rand;
use function mt_srand;

/**
 * The loop over the ticking chunks walked by chunkutils2 >= 0.3.14 (World::tickChunksNative()) must tick exactly like
 * the PHP loop over World::tickChunk(): same world after every tick, same entities scheduled in the same order, same
 * mt_rand() draws (the generator ends in the same state). The scene has every kind of chunk the native loop handles
 * differently: empty chunks, chunks without random-ticked blocks, chunks with picks (crops, grass, leaves, copper,
 * snow, ice), lava next to something flammable (lava fast path), chunks with entities, and rain with cold biomes
 * (precipitation draws, ice and snow forming), lava seas with nothing to burn (skipped natively).
 */
final class NativeChunkTickLoopTest extends RedstoneTestCase{

	private const RADIUS = 3;

	/**
	 * Entity runtime IDs differ between worlds: entities are compared by spawn order.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $entityIndex = [];

	protected function getChunkRadius() : int{
		return self::RADIUS;
	}

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false], "chunk-ticking" => ["tick-radius" => 4, "simulation-distance" => 0]];
	}

	protected function setUp() : void{
		//the PHPStan stub always has the method; older extensions don't
		/** @phpstan-ignore function.alreadyNarrowedType */
		if(!class_exists(RandomTickSampler::class) || !method_exists(RandomTickSampler::class, "tickChunks")){
			self::markTestSkipped("chunkutils2 >= 0.3.14 is not loaded");
		}
		parent::setUp();
	}

	private function buildScene(int $seed) : void{
		mt_srand($seed);
		$w = $this->world;
		$this->entityIndex = [];
		$blocks = [
			VanillaBlocks::GRASS(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_LEAVES()->setNoDecay(false), VanillaBlocks::COPPER(),
			VanillaBlocks::ICE(), VanillaBlocks::SNOW_LAYER(), VanillaBlocks::MYCELIUM(), VanillaBlocks::STONE(), VanillaBlocks::OAK_PLANKS(),
		];
		for($cx = -self::RADIUS; $cx <= self::RADIUS; ++$cx){
			for($cz = -self::RADIUS; $cz <= self::RADIUS; ++$cz){
				$kind = mt_rand(0, 6);
				$baseX = $cx * 16;
				$baseZ = $cz * 16;
				if(mt_rand(0, 2) === 0){
					for($x = 0; $x < 16; ++$x){
						for($z = 0; $z < 16; ++$z){
							for($y = -1; $y <= 70; $y += 16){
								$w->setBiomeId($baseX + $x, $y, $baseZ + $z, BiomeIds::ICE_PLAINS);
							}
						}
					}
				}
				if($kind === 0){
					continue; //empty chunk
				}
				for($x = 0; $x < 16; ++$x){
					for($z = 0; $z < 16; ++$z){
						$w->setBlockAt($baseX + $x, -60, $baseZ + $z, VanillaBlocks::STONE(), false);
						if($kind === 1){
							continue; //nothing random-ticked
						}
						$w->setBlockAt($baseX + $x, 0, $baseZ + $z, VanillaBlocks::DIRT(), false);
						$top = $blocks[mt_rand(0, count($blocks) - 1)];
						$w->setBlockAt($baseX + $x, 1, $baseZ + $z, $top, false);
						if(mt_rand(0, 3) === 0){
							$w->setBlockAt($baseX + $x, 40 + mt_rand(0, 30), $baseZ + $z, $blocks[mt_rand(0, count($blocks) - 1)], false);
						}
					}
				}
				if($kind === 3){
					//farmland with crops
					for($i = 0; $i < 40; ++$i){
						$x = $baseX + mt_rand(0, 15);
						$z = $baseZ + mt_rand(0, 15);
						$w->setBlockAt($x, 1, $z, VanillaBlocks::FARMLAND(), false);
						$w->setBlockAt($x, 2, $z, mt_rand(0, 1) === 0 ? VanillaBlocks::WHEAT() : VanillaBlocks::CARROTS(), false);
					}
				}elseif($kind === 4){
					//lava pool next to planks: lava random ticks may start fires
					for($x = 4; $x < 10; ++$x){
						for($z = 4; $z < 10; ++$z){
							$w->setBlockAt($baseX + $x, 1, $baseZ + $z, VanillaBlocks::LAVA(), false);
						}
					}
					for($x = 3; $x < 11; ++$x){
						$w->setBlockAt($baseX + $x, 3, $baseZ + 3, VanillaBlocks::OAK_PLANKS(), false);
						$w->setBlockAt($baseX + $x, 3, $baseZ + 10, VanillaBlocks::OAK_PLANKS(), false);
					}
				}elseif($kind === 6){
					//a lava sea with nothing to burn: the native loop skips chunks whose only picks are such lava
					for($x = 0; $x < 16; ++$x){
						for($z = 0; $z < 16; ++$z){
							for($y = -50; $y < -20; ++$y){
								$w->setBlockAt($baseX + $x, $y, $baseZ + $z, VanillaBlocks::LAVA(), false);
							}
						}
					}
				}elseif($kind === 5){
					//entities: the native loop leaves these chunks to World::tickChunk()
					for($i = 0; $i < 2; ++$i){
						$cow = new Cow(new Location($baseX + mt_rand(0, 15) + 0.5, 2, $baseZ + mt_rand(0, 15) + 0.5, $w, 0, 0));
						$cow->spawnToAll();
						$this->entityIndex[$cow->getId()] = count($this->entityIndex);
					}
				}
			}
		}
		$w->getWeather()->setRain(100000);

		$hashes = [];
		for($cx = -self::RADIUS + 1; $cx <= self::RADIUS - 1; ++$cx){
			for($cz = -self::RADIUS + 1; $cz <= self::RADIUS - 1; ++$cz){
				$hashes[World::chunkHash($cx, $cz)] = World::chunkHash($cx, $cz);
			}
		}
		$ticker = new ChunkTicker();
		\Closure::bind(function() use ($hashes, $ticker) : void{
			foreach($hashes as $hash){
				$this->registeredTickingChunks[$hash] = [1 => $ticker];
			}
			$this->validTickingChunks = $hashes;
			$this->recheckTickingChunks = [];
		}, $w, World::class)();
	}

	private function worldHash() : string{
		$ctx = hash_init("xxh128");
		for($cx = -self::RADIUS; $cx <= self::RADIUS; ++$cx){
			for($cz = -self::RADIUS; $cz <= self::RADIUS; ++$cz){
				$chunk = $this->world->getChunk($cx, $cz);
				if($chunk === null){
					hash_update($ctx, "unloaded");
					continue;
				}
				foreach($chunk->getSubChunks() as $y => $subChunk){
					hash_update($ctx, "subchunk $y:");
					foreach($subChunk->getBlockLayers() as $layer){
						hash_update($ctx, $layer->getWordArray() . implode(",", $layer->getPalette()) . ";");
					}
				}
			}
		}
		return hash_final($ctx);
	}

	/**
	 * @return string[] one entry per tick: world hash, scheduled entities, and finally the next mt_rand()
	 * @phpstan-return list<string>
	 */
	private function traceTicks(bool $native, int $seed, int $ticks, int $randomTickSpeed) : array{
		$this->buildScene($seed);
		$this->world->setGameRule(GameRules::RANDOM_TICK_SPEED, $randomTickSpeed);
		$tickChunks = \Closure::bind(function(bool $native) : void{
			$this->randomTickSamplerTicksChunks = $native;
			$this->updateEntities = [];
			$this->tickChunks();
		}, $this->world, World::class);
		mt_srand($seed + 1);
		$trace = [];
		for($tick = 0; $tick < $ticks; ++$tick){
			$tickChunks($native);
			$trace[] = $this->worldHash() . " entities " . implode(",", array_map(fn(int $id) => $this->entityIndex[$id] ?? "?$id", array_keys($this->world->updateEntities)));
		}
		$trace[] = "next mt_rand " . mt_rand();
		return $trace;
	}

	/**
	 * A new world for the next run.
	 */
	private function freshWorld() : void{
		$this->tearDown(); //the temporary config files of the current world
		parent::setUp();
	}

	public function testNativeLoopTicksLikePhpLoop() : void{
		foreach([[11, 3], [12, 1], [13, 9]] as [$seed, $speed]){
			$php = $this->traceTicks(false, $seed, 60, $speed);
			$this->freshWorld();
			$native = $this->traceTicks(true, $seed, 60, $speed);
			$this->freshWorld();
			self::assertSame($php, $native, "seed $seed, random tick speed $speed");
		}
	}

	public function testSceneChangesUnderRandomTicks() : void{
		$trace = $this->traceTicks(true, 11, 60, 3);
		self::assertNotSame($trace[0], $trace[59], "the scene should change (crops grow, ice and snow form...)");
	}
}
