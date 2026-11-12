<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\SimpleChunkManager;
use Symfony\Component\Filesystem\Filesystem;
use function file_put_contents;
use function json_encode;
use function mkdir;
use const JSON_THROW_ON_ERROR;

/**
 * Exercises the imported VanillaMine JSON density graph and 3D biome pipeline with
 * small local fixtures. These fixtures are NOT Mojang data and prove the adapter,
 * not compatibility with a Minecraft 26.3 seed.
 */
final class VanillaDensityCoreTest extends TestCase{
	private string $dir;

	protected function setUp() : void{
		$this->dir = sys_get_temp_dir() . '/ryzemp-density-' . bin2hex(random_bytes(12));
		$this->put('version.json', ['minecraft' => '26.3', 'format' => 5]);
		$gradient = static fn(float $a, float $b) : array => [
			'type' => 'minecraft:y_clamped_gradient',
			'from_y' => -64,
			'to_y' => 320,
			'from_value' => $a,
			'to_value' => $b
		];
		$this->put('data/minecraft/worldgen/noise_settings/overworld.json', [
			'sea_level' => 63,
			'default_block' => 'minecraft:stone',
			'default_fluid' => 'minecraft:water',
			'noise' => ['min_y' => -64, 'height' => 384],
			'noise_router' => [
				'temperature' => 0,
				'vegetation' => 0,
				'continents' => 0,
				'erosion' => 0,
				'depth' => $gradient(-1.0, 1.0),
				'ridges' => 0,
				'chunk_surface_level' => 64,
				'final_density' => $gradient(1.0, -1.0)
			]
		]);
		$parameter = static fn(float $depth) : array => [
			'temperature' => 0,
			'humidity' => 0,
			'continentalness' => 0,
			'erosion' => 0,
			'depth' => $depth,
			'weirdness' => 0,
			'offset' => 0
		];
		$this->put('reports/biome_parameters/minecraft/overworld.json', [
			'biomes' => [
				['biome' => 'minecraft:plains', 'parameters' => $parameter(-0.75)],
				['biome' => 'minecraft:forest', 'parameters' => $parameter(0.75)]
			]
		]);
	}

	/** @param mixed[] $json */
	private function put(string $path, array $json) : void{
		$file = $this->dir . '/' . $path;
		$parent = dirname($file);
		if(!is_dir($parent)){
			mkdir($parent, 0777, true);
		}
		file_put_contents($file, json_encode($json, JSON_THROW_ON_ERROR));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->dir);
	}

	public function testPresetRequiresMatchingImmutableDataVersion() : void{
		$preset = 'core-v1;data=' . $this->dir;
		self::assertSame($this->dir, VanillaDensityChunkWriter::validatePreset($preset));
		$this->put('version.json', ['minecraft' => '26.4', 'format' => 5]);
		$this->expectException(InvalidGeneratorOptionsException::class);
		VanillaDensityChunkWriter::validatePreset($preset);
	}

	public function testVanillaMineDensityAndThreeDimensionalBiomes() : void{
		$preset = 'core-v1;data=' . $this->dir;
		$gen = new RyzeOverworldGenerator(12345, $preset);
		$world = new SimpleChunkManager(-64, 320);
		$chunk = new Chunk([], false);
		$world->setChunk(-1, 2, $chunk);
		$gen->generateChunk($world, -1, 2);

		self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $chunk->getBlockStateId(3, -64, 4));
		self::assertSame(VanillaBlocks::STONE()->getStateId(), $chunk->getBlockStateId(3, 80, 4));
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $chunk->getBlockStateId(3, 230, 4));
		self::assertSame(BiomeIds::PLAINS, $chunk->getBiomeId(3, -48, 4));
		self::assertSame(BiomeIds::FOREST, $chunk->getBiomeId(3, 270, 4));
	}

	public function testSeededChunkOrderIsStableForTheSameData() : void{
		$preset = 'core-v1;data=' . $this->dir;
		$a = new SimpleChunkManager(-64, 320);
		$b = new SimpleChunkManager(-64, 320);
		foreach([[0, 0], [-2, 1]] as [$x, $z]){
			$a->setChunk($x, $z, new Chunk([], false));
			$b->setChunk($x, $z, new Chunk([], false));
		}
		$genA = new RyzeOverworldGenerator(12345, $preset);
		$genB = new RyzeOverworldGenerator(12345, $preset);
		$genA->generateChunk($a, -2, 1);
		$genA->generateChunk($a, 0, 0);
		$genB->generateChunk($b, 0, 0);
		$genB->generateChunk($b, -2, 1);
		foreach([[0, 0], [-2, 1]] as [$x, $z]){
			$ca = $a->getChunk($x, $z);
			$cb = $b->getChunk($x, $z);
			self::assertNotNull($ca);
			self::assertNotNull($cb);
			foreach([[-64, 4, 4], [20, 4, 4], [120, 4, 4], [230, 4, 4]] as [$y, $lx, $lz]){
				self::assertSame($ca->getBlockStateId($lx, $y, $lz), $cb->getBlockStateId($lx, $y, $lz));
				self::assertSame($ca->getBiomeId($lx, $y, $lz), $cb->getBiomeId($lx, $y, $lz));
			}
		}
	}
}
