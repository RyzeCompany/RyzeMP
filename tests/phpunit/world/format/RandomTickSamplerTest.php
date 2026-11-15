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

namespace pocketmine\world\format;

use PHPUnit\Framework\TestCase;
use function class_exists;
use function count;
use function method_exists;
use function mt_rand;
use function mt_srand;

/**
 * The native picks of chunkutils2 >= 0.3.6 must be exactly the picks of the PHP loop in World::tickChunk().
 */
final class RandomTickSamplerTest extends TestCase{

	protected function setUp() : void{
		if(!class_exists(RandomTickSampler::class)){
			self::markTestSkipped("chunkutils2 >= 0.3.6 is not loaded");
		}
	}

	/**
	 * @param array<int, PalettedBlockArray> $layers
	 * @param array<int, true>               $ticked
	 * @return list<int>
	 */
	private static function phpPicks(array $layers, array $ticked, int $count) : array{
		$result = [];
		foreach($layers as $Y => $layer){
			$has = false;
			foreach($layer->getPalette() as $state){
				if(isset($ticked[$state])){
					$has = true;
					break;
				}
			}
			if(!$has){
				continue;
			}
			$k = 0;
			for($i = 0; $i < $count; ++$i){
				if(($i % 5) === 0){
					$k = mt_rand(0, (1 << 60) - 1);
				}
				$x = $k & 0xf;
				$y = ($k >> 4) & 0xf;
				$z = ($k >> 8) & 0xf;
				$k >>= 12;
				$state = $layer->get($x, $y, $z);
				if(isset($ticked[$state])){
					$result[] = $Y;
					$result[] = $x | ($y << 4) | ($z << 8);
					$result[] = $state;
				}
			}
		}
		return $result;
	}

	public function testPicksMatchPhpLoop() : void{
		$ticked = [];
		for($i = 0; $i < 40; ++$i){
			$ticked[1000 + $i * 3] = true;
		}
		$sampler = new RandomTickSampler();
		foreach($ticked as $state => $_){
			$sampler->setTicked($state, true);
		}
		$sampler->setTicked(5000, true);
		$sampler->setTicked(5000, false);

		mt_srand(42);
		$layers = [];
		for($Y = -4; $Y < 20; ++$Y){
			if(mt_rand(0, 3) === 0){
				continue;
			}
			$layer = new PalettedBlockArray(1);
			for($k = 0; $k < 300; ++$k){
				$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 1) === 1 ? mt_rand(1000, 1120) : mt_rand(2, 60));
			}
			$layers[$Y] = $layer;
		}
		$layers[20] = new PalettedBlockArray(5000); //only a state which is no longer ticked

		$hits = 0;
		foreach([1, 3, 7] as $count){
			for($run = 0; $run < 300; ++$run){
				mt_srand(1000 + $run);
				$native = $sampler->sample($layers, $count);
				mt_srand(1000 + $run);
				self::assertSame(self::phpPicks($layers, $ticked, $count), $native, "count $count run $run");
				$hits += count($native);
			}
		}
		self::assertGreaterThan(100, $hits);
		self::assertTrue($sampler->hasTickedState($layers[-4] ?? $layers[-3]));
		self::assertFalse($sampler->hasTickedState($layers[20]));
	}

	/**
	 * sampleSubChunks() (chunkutils2 >= 0.3.9) reads the subchunks of a chunk itself: same picks as sample() over the
	 * layers World collected in PHP before, empty subchunks skipped.
	 */
	public function testSubChunkPicksMatchLayerPicks() : void{
		$sampler = new RandomTickSampler();
		/** @phpstan-ignore function.alreadyNarrowedType */
		if(!method_exists($sampler, "sampleSubChunks")){
			self::markTestSkipped("chunkutils2 >= 0.3.9 is not loaded");
		}
		$ticked = [];
		for($i = 0; $i < 40; ++$i){
			$ticked[1000 + $i * 3] = true;
			$sampler->setTicked(1000 + $i * 3, true);
		}

		mt_srand(7);
		$subChunks = [];
		for($Y = Chunk::MIN_SUBCHUNK_INDEX; $Y <= Chunk::MAX_SUBCHUNK_INDEX; ++$Y){
			if(mt_rand(0, 3) === 0){
				continue; //stays an empty subchunk (no layers)
			}
			$layer = new PalettedBlockArray(1);
			for($k = 0; $k < 300; ++$k){
				$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 1) === 1 ? mt_rand(1000, 1120) : mt_rand(2, 60));
			}
			$subChunks[$Y] = new SubChunk(1, [$layer], new PalettedBlockArray(0));
		}
		$chunk = new Chunk($subChunks, false);

		//what World::tickChunkNative() did before: layer 0 of every non-empty subchunk, keyed by Y
		$layers = [];
		foreach($chunk->getSubChunks() as $Y => $subChunk){
			if(!$subChunk->isEmptyFast()){
				$layers[$Y] = $subChunk->getBlockLayers()[0];
			}
		}
		self::assertNotEmpty($layers);

		$hits = 0;
		foreach([1, 3, 7] as $count){
			for($run = 0; $run < 100; ++$run){
				mt_srand(2000 + $run);
				$expected = $sampler->sample($layers, $count);
				mt_srand(2000 + $run);
				$actual = $sampler->sampleSubChunks($chunk->getSubChunkStorage(), $count, Chunk::MIN_SUBCHUNK_INDEX);
				self::assertSame($expected, $actual, "count $count run $run");
				mt_srand(2000 + $run);
				self::assertSame(self::phpPicks($layers, $ticked, $count), $actual, "php count $count run $run");
				$hits += count($actual);
			}
		}
		self::assertGreaterThan(50, $hits);
	}
}
