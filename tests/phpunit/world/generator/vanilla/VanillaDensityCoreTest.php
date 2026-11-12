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
			'material_rule' => ['type' => 'minecraft:block', 'result_state' => ['Name' => 'minecraft:grass_block']],
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
		foreach(['surface', 'surface_secondary', 'badlands_pillar', 'badlands_pillar_roof', 'badlands_surface', 'iceberg_pillar', 'iceberg_pillar_roof', 'iceberg_surface'] as $noise){
			$this->put('data/minecraft/worldgen/noise/' . $noise . '.json', []);
		}
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
		self::assertSame(VanillaBlocks::GRASS()->getStateId(), $chunk->getBlockStateId(3, 80, 4));
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

	public function testCoreV1LevelDbRoundTripAndPresetPersistence() : void{
		\pocketmine\world\dimension\generator\VanillaGenerators::register();
		$preset = 'core-v1;data=' . $this->dir;
		$path = $this->dir . '/new-world';
		\pocketmine\world\format\io\leveldb\LevelDB::generate(
			$path,
			'new-world',
			\pocketmine\world\WorldCreationOptions::create()
				->setGeneratorClass(RyzeOverworldGenerator::class)
				->setGeneratorOptions($preset)
				->setSeed(77123)
		);
		$manager = new SimpleChunkManager(-64, 320);
		$chunk = new Chunk([], false);
		$manager->setChunk(-1, 2, $chunk);
		(new RyzeOverworldGenerator(77123, $preset))->generateChunk($manager, -1, 2);
		$chunk->setPopulated();
		$provider = new \pocketmine\world\format\io\leveldb\LevelDB($path, $this->createMock(\Logger::class));
		self::assertSame($preset, $provider->getWorldData()->getGeneratorOptions());
		self::assertSame(77123, $provider->getWorldData()->getSeed());
		$provider->saveChunk(-1, 2, new \pocketmine\world\format\io\ChunkData(
			$chunk->getSubChunks(),
			true,
			[\pocketmine\nbt\tag\CompoundTag::create()->setString('marker', 'entity-original')],
			[\pocketmine\nbt\tag\CompoundTag::create()->setString('marker', 'tile-original')]
		), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
		$provider->close();

		$provider = new \pocketmine\world\format\io\leveldb\LevelDB($path, $this->createMock(\Logger::class));
		try{
			self::assertSame($preset, $provider->getWorldData()->getGeneratorOptions());
			$saved = $provider->loadChunk(-1, 2)?->getData();
			self::assertNotNull($saved);
			self::assertTrue($saved->isPopulated());
			self::assertSame('entity-original', $saved->getEntityNBT()[0]->getString('marker'));
			self::assertSame('tile-original', $saved->getTileNBT()[0]->getString('marker'));
			$loaded = new Chunk($saved->getSubChunks(), true);
			self::assertSame(VanillaBlocks::GRASS()->getStateId(), $loaded->getBlockStateId(3, 80, 4));
			self::assertSame(BiomeIds::FOREST, $loaded->getBiomeId(3, 270, 4));
			// An existing chunk must not be rewritten by a new generator invocation.
			$manager->setChunk(-1, 2, $loaded);
			(new RyzeOverworldGenerator(77123, $preset))->generateChunk($manager, -1, 2);
			self::assertSame(VanillaBlocks::GRASS()->getStateId(), $loaded->getBlockStateId(3, 80, 4));
		}finally{
			$provider->close();
		}
	}

	/**
	 * Throughput baseline for this synthetic density fixture only.
	 *
	 * The fixture is intentionally simplistic: these values are NOT a
	 * Minecraft worldgen TPS or production performance benchmark.
	 */
	public function testAquiferStageDeterministicallyRunsOnSeededNoiseData() : void{
		$settingsPath = 'data/minecraft/worldgen/noise_settings/overworld.json';
		$json = file_get_contents($this->dir . '/' . $settingsPath);
		self::assertNotFalse($json);
		$settings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($settings);
		$settings['noise_router']['final_density'] = -1.0; // open water/air cells
		$settings['aquifers'] = [
			'barrier' => 0.0,
			'fluid_level_floodedness' => 0.0,
			'fluid_level_spread' => 0.0,
			'lava' => 0.0,
			'exclusion' => 0.0,
			'surface_level' => 0.0
		];
		$this->put($settingsPath, $settings);
		$preset = 'core-v1;data=' . $this->dir;
		$leftWorld = new SimpleChunkManager(-64, 320);
		$rightWorld = new SimpleChunkManager(-64, 320);
		$left = new Chunk([], false);
		$right = new Chunk([], false);
		$leftWorld->setChunk(-2, 3, $left);
		$rightWorld->setChunk(-2, 3, $right);
		(new RyzeOverworldGenerator(8791, $preset))->generateChunk($leftWorld, -2, 3);
		(new RyzeOverworldGenerator(8791, $preset))->generateChunk($rightWorld, -2, 3);
		foreach([[-64, 8, 8], [-30, 5, 7], [0, 7, 1], [40, 4, 3], [100, 12, 12]] as [$y, $x, $z]){
			self::assertSame($left->getBlockStateId($x, $y, $z), $right->getBlockStateId($x, $y, $z));
			self::assertSame($left->getBiomeId($x, $y, $z), $right->getBiomeId($x, $y, $z));
		}
		self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $left->getBlockStateId(8, -64, 8));
	}

	public function testSyntheticDensityChunkPerformanceBaseline() : void{
		$preset = 'core-v1;data=' . $this->dir;
		$generator = new RyzeOverworldGenerator(12345, $preset);
		$world = new SimpleChunkManager(-64, 320);
		$times = [];
		$beforePeak = memory_get_peak_usage(true);
		foreach([[0, 0], [1, 0], [0, 1], [-1, -1]] as [$x, $z]){
			$world->setChunk($x, $z, new Chunk([], false));
			$start = microtime(true);
			$generator->generateChunk($world, $x, $z);
			$times[] = (microtime(true) - $start) * 1000;
			$chunk = $world->getChunk($x, $z);
			self::assertNotNull($chunk);
			self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $chunk->getBlockStateId(0, -64, 0));
		}
		sort($times, SORT_NUMERIC);
		$avg = array_sum($times) / count($times);
		$peakDelta = max(0, memory_get_peak_usage(true) - $beforePeak);
		fwrite(STDERR, sprintf(
			"\n[Worldgen synthetic density baseline] 4 chunks; avg=%.2fms; max=%.2fms; peak delta=%.2f MiB (NOT production TPS)\n",
			$avg,
			$times[3],
			$peakDelta / 1048576
		));
		self::assertCount(4, $times);
	}
}
