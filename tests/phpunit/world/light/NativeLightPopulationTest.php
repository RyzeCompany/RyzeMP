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

namespace pocketmine\world\light;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\LightArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_keys;
use function array_map;
use function chunkutils2_set_simd_enabled;
use function class_exists;
use function count;
use function function_exists;
use function mt_rand;
use function mt_srand;

/**
 * LightPopulationTask::computeLight() with NativeLightPopulation (chunkutils2 >= 0.3.13) gives the same heightmap and
 * light arrays, byte for byte, as BlockLightUpdate + SkyLightUpdate; with the SIMD helpers of the extension on and off.
 */
final class NativeLightPopulationTest extends TestCase{

	protected function setUp() : void{
		if(!class_exists(NativeLightPopulation::class)){
			self::markTestSkipped("chunkutils2 >= 0.3.13 (NativeLightPopulation) is not loaded");
		}
	}

	protected function tearDown() : void{
		LightPopulationTask::setNativeEnabled(true);
		if(function_exists('chunkutils2_set_simd_enabled')){
			chunkutils2_set_simd_enabled(true);
		}
	}

	/**
	 * @return array{list<int>, array<int, string>, array<int, string>}
	 */
	private static function compute(Chunk $chunk, bool $native) : array{
		LightPopulationTask::setNativeEnabled($native);
		//a copy: the PHP path writes the light arrays and heightmap of the chunk it is given
		$copy = new Chunk(array_map(fn(SubChunk $s) => clone $s, $chunk->getSubChunks()), true);
		[$heightMap, $sky, $block] = LightPopulationTask::computeLight($copy);
		return [$heightMap, array_map(fn(LightArray $a) => $a->getData(), $sky), array_map(fn(LightArray $a) => $a->getData(), $block)];
	}

	private static function assertSameLight(Chunk $chunk, string $label) : void{
		$expected = self::compute($chunk, false);
		foreach([true, false] as $simd){
			if(function_exists('chunkutils2_set_simd_enabled')){
				chunkutils2_set_simd_enabled($simd);
			}
			$actual = self::compute($chunk, true);
			self::assertSame($expected[0], $actual[0], "$label: heightmap (simd " . ($simd ? "on" : "off") . ")");
			self::assertSame($expected[1], $actual[1], "$label: sky light (simd " . ($simd ? "on" : "off") . ")");
			self::assertSame($expected[2], $actual[2], "$label: block light (simd " . ($simd ? "on" : "off") . ")");
		}
	}

	private static function id(Block $block) : int{
		return $block->getStateId();
	}

	public function testEmptyAndFullChunks() : void{
		self::assertSameLight(new Chunk([], true), "empty");

		$stone = self::id(VanillaBlocks::STONE());
		$chunk = new Chunk([], true);
		for($y = World::Y_MIN; $y < World::Y_MAX; $y++){
			for($x = 0; $x < 16; $x++){
				for($z = 0; $z < 16; $z++){
					$chunk->setBlockStateId($x, $y, $z, $stone);
				}
			}
		}
		self::assertSameLight($chunk, "full stone");
	}

	public function testSingleEmittersAtTheEdges() : void{
		$glowstone = self::id(VanillaBlocks::GLOWSTONE());
		$stone = self::id(VanillaBlocks::STONE());
		foreach([[0, World::Y_MIN, 0], [15, World::Y_MAX - 1, 15], [0, World::Y_MAX - 1, 15], [15, World::Y_MIN, 0], [8, 100, 8], [3, 15, 12], [3, 16, 12]] as [$x, $y, $z]){
			$chunk = new Chunk([], true);
			$chunk->setBlockStateId($x, $y, $z, $glowstone);
			self::assertSameLight($chunk, "glowstone at $x $y $z");

			//a roof in the top block layer: every column has the heightmap at Y_MAX
			$chunk = new Chunk([], true);
			for($xx = 0; $xx < 16; $xx++){
				for($zz = 0; $zz < 16; $zz++){
					$chunk->setBlockStateId($xx, World::Y_MAX - 1, $zz, $stone);
				}
			}
			$chunk->setBlockStateId($x, $y, $z, $glowstone);
			self::assertSameLight($chunk, "roof, glowstone at $x $y $z");
		}
	}

	/**
	 * Terrain-like chunks: stone with caves, dirt and grass, water lakes, lava pockets, glowstone, leaves, glass,
	 * overhangs; and nether-like ones (netherrack, lava sea, bedrock ceiling).
	 */
	public function testTerrainLikeChunks() : void{
		$b = [
			"stone" => self::id(VanillaBlocks::STONE()),
			"dirt" => self::id(VanillaBlocks::DIRT()),
			"grass" => self::id(VanillaBlocks::GRASS()),
			"water" => self::id(VanillaBlocks::WATER()),
			"lava" => self::id(VanillaBlocks::LAVA()),
			"glowstone" => self::id(VanillaBlocks::GLOWSTONE()),
			"leaves" => self::id(VanillaBlocks::OAK_LEAVES()),
			"glass" => self::id(VanillaBlocks::GLASS()),
			"torch" => self::id(VanillaBlocks::TORCH()),
			"netherrack" => self::id(VanillaBlocks::NETHERRACK()),
			"bedrock" => self::id(VanillaBlocks::BEDROCK()),
			"ice" => self::id(VanillaBlocks::ICE()),
		];
		for($seed = 1; $seed <= 24; $seed++){
			mt_srand($seed);
			$nether = $seed % 3 === 0;
			$chunk = new Chunk([], true);
			for($x = 0; $x < 16; $x++){
				for($z = 0; $z < 16; $z++){
					$surface = $nether ? 127 : 40 + ($x * 7 + $z * 3 + $seed * 11) % 40 + mt_rand(-3, 3);
					for($y = World::Y_MIN; $y <= $surface; $y++){
						if($y === World::Y_MIN || ($nether && $y >= 123)){
							$id = $b["bedrock"];
						}elseif($nether){
							$id = $y < 32 ? $b["lava"] : (mt_rand(0, 9) === 0 ? 0 : $b["netherrack"]);
							if($id === 0) continue;
						}elseif($y === $surface){
							$id = $y < 62 ? $b["water"] : $b["grass"];
						}elseif($y > $surface - 4){
							$id = $b["dirt"];
						}else{
							$id = $b["stone"];
							if(mt_rand(0, 6) === 0) continue; //caves
						}
						if(mt_rand(0, 400) === 0) $id = $b["glowstone"];
						if(!$nether && $y < -40 && mt_rand(0, 60) === 0) $id = $b["lava"];
						$chunk->setBlockStateId($x, $y, $z, $id);
					}
					if(!$nether && mt_rand(0, 30) === 0){
						for($y = $surface + 1; $y < $surface + 6; $y++){
							$chunk->setBlockStateId($x, $y, $z, $b["leaves"]);
						}
					}
					if(!$nether && mt_rand(0, 50) === 0) $chunk->setBlockStateId($x, $surface + 1, $z, $b["torch"]);
					if(!$nether && mt_rand(0, 40) === 0) $chunk->setBlockStateId($x, $surface + 8, $z, $b["glass"]);
					if(!$nether && mt_rand(0, 60) === 0) $chunk->setBlockStateId($x, $surface + 2, $z, $b["ice"]);
				}
			}
			//waterlogged blocks: liquid layer emitters are ignored by both (only layer 0 is scanned)
			$sub = $chunk->getSubChunk(3);
			$sub->setLiquidLayerStateId(5, 5, 5, $b["lava"]);
			self::assertSameLight($chunk, "terrain seed $seed" . ($nether ? " (nether)" : ""));
		}
	}

	/**
	 * Random states of the whole registry (any light filter and emission), sparse and dense, some subchunks without
	 * layers, palettes of every size (1 to 9 bits per block).
	 */
	public function testRandomStates() : void{
		$registry = RuntimeBlockStateRegistry::getInstance();
		$states = array_keys($registry->lightFilter);
		$emitters = [];
		foreach($registry->light as $state => $level){
			if($level > 0) $emitters[] = $state;
		}
		self::assertGreaterThan(0, count($emitters));
		for($seed = 1; $seed <= 12; $seed++){
			mt_srand($seed * 1000);
			$chunk = new Chunk([], true);
			$density = [0.002, 0.02, 0.1, 0.5][$seed % 4];
			for($sy = Chunk::MIN_SUBCHUNK_INDEX; $sy <= Chunk::MAX_SUBCHUNK_INDEX; $sy++){
				if(mt_rand(0, 2) === 0){
					continue;
				}
				$paletteSize = [1, 2, 3, 5, 9, 17, 33, 70, 300][mt_rand(0, 8)];
				$palette = [];
				for($k = 0; $k < $paletteSize; $k++){
					$palette[] = mt_rand(0, 4) === 0 ? $emitters[mt_rand(0, count($emitters) - 1)] : $states[mt_rand(0, count($states) - 1)];
				}
				$subChunk = $chunk->getSubChunk($sy);
				$threshold = (int) ($density * 1000000);
				for($x = 0; $x < 16; $x++){
					for($z = 0; $z < 16; $z++){
						for($y = 0; $y < 16; $y++){
							if(mt_rand(0, 999999) < $threshold){
								$subChunk->setBlockStateId($x, $y, $z, $palette[mt_rand(0, $paletteSize - 1)]);
							}
						}
					}
				}
			}
			self::assertSameLight($chunk, "random seed $seed");
		}
	}

	/**
	 * A table value the native code does not handle (here: an emission above 15) makes it return null; computeLight()
	 * then gives the PHP result.
	 */
	public function testUnsupportedTableValueFallsBack() : void{
		$glowstone = self::id(VanillaBlocks::GLOWSTONE());
		$registry = RuntimeBlockStateRegistry::getInstance();
		$chunk = new Chunk([], true);
		$chunk->setBlockStateId(1, 2, 3, $glowstone);
		$emptyBlockIds = [];
		$layers = [];
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$emptyBlockIds[$y] = $subChunk->getEmptyBlockId();
			if(count($subChunk->getBlockLayers()) > 0){
				$layers[$y] = $subChunk->getBlockLayers()[0];
			}
		}
		$emitters = $registry->light;
		$emitters[$glowstone] = 16;
		self::assertNull(NativeLightPopulation::populate(Chunk::MIN_SUBCHUNK_INDEX, Chunk::MAX_SUBCHUNK_INDEX, $emptyBlockIds, $layers, $registry->lightFilter, $emitters, $registry->blocksDirectSkyLight));
		self::assertNotNull(NativeLightPopulation::populate(Chunk::MIN_SUBCHUNK_INDEX, Chunk::MAX_SUBCHUNK_INDEX, $emptyBlockIds, $layers, $registry->lightFilter, $registry->light, $registry->blocksDirectSkyLight));
	}
}
