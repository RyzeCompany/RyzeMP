<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\SimpleChunkManager;
use Symfony\Component\Filesystem\Filesystem;
use const JSON_THROW_ON_ERROR;
use function array_map;
use function file_get_contents;
use function json_decode;

/**
 * Isolated synthetic data for both secondary dimensions. As with the
 * Overworld core fixture, these are not Minecraft's original JSON assets.
 */
final class VanillaDimensionCoreTest extends TestCase{
	private string $dir;

	protected function setUp() : void{
		$this->dir = sys_get_temp_dir() . '/ryzemp-dim-core-' . bin2hex(random_bytes(12));
		$this->put('version.json', ['minecraft' => '26.3', 'format' => 5]);
		foreach(['surface', 'surface_secondary', 'badlands_pillar', 'badlands_pillar_roof', 'badlands_surface', 'iceberg_pillar', 'iceberg_pillar_roof', 'iceberg_surface'] as $noise){
			$this->put('data/minecraft/worldgen/noise/' . $noise . '.json', []);
		}
		$density = [
			'type' => 'minecraft:y_clamped_gradient',
			'from_y' => 0,
			'to_y' => 128,
			'from_value' => 1.0,
			'to_value' => -1.0
		];
		foreach(['nether', 'end'] as $dimension){
			$block = $dimension === 'end' ? 'end_stone' : 'netherrack';
			$this->put('data/minecraft/worldgen/noise_settings/' . $dimension . '.json', [
				'legacy_random_source' => true,
				'sea_level' => $dimension === 'end' ? 0 : 32,
				'default_block' => 'minecraft:' . $block,
				'default_fluid' => $dimension === 'end' ? 'minecraft:air' : 'minecraft:lava',
				'material_rule' => ['type' => 'minecraft:block', 'result_state' => 'minecraft:' . $block],
				'noise' => ['min_y' => 0, 'height' => 128],
				'noise_router' => [
					'temperature' => 0,
					'vegetation' => 0,
					'continents' => 0,
					'erosion' => $dimension === 'end' ? 1 : 0,
					'depth' => 0,
					'ridges' => 0,
					'chunk_surface_level' => 64,
					'final_density' => $density
				]
			]);
		}
		$zeroParams = array_fill_keys(['temperature','humidity','continentalness','erosion','depth','weirdness','offset'], 0);
		$this->put('reports/biome_parameters/minecraft/nether.json', [
			'biomes' => [['biome' => 'minecraft:nether_wastes', 'parameters' => $zeroParams]]
		]);
	}

	/** @param mixed[] $json */
	private function put(string $path, array $json) : void{
		$file = $this->dir . '/' . $path;
		if(!is_dir(dirname($file))){
			mkdir(dirname($file), 0777, true);
		}
		file_put_contents($file, json_encode($json, JSON_THROW_ON_ERROR));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->dir);
	}

	public function testEndSpikeSeedMatchesVanillaMineReferenceAndPlacesObsidian() : void{
		$referenceRaw = file_get_contents(__DIR__ . '/dimension-fixtures.json');
		self::assertNotFalse($referenceRaw);
		$reference = json_decode($referenceRaw, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($reference);
		self::assertIsArray($reference['spikes'] ?? null);
		foreach($reference['spikes'] as $seed => $expected){
			$spikes = EndSpikes::forSeed((int) $seed);
			self::assertCount(10, $spikes);
			$actual = array_map(static fn(EndSpike $spike) : array => [
				$spike->centerX, $spike->centerZ, $spike->radius, $spike->height, $spike->guarded
			], $spikes);
			self::assertSame($expected, $actual, "End spike seed $seed");
		}

		$seed = 12345;
		$spike = EndSpikes::forSeed($seed)[0];
		$chunkX = $spike->centerX >> 4;
		$chunkZ = $spike->centerZ >> 4;
		$world = new SimpleChunkManager(-64, 320);
		$chunk = new Chunk([], false);
		$world->setChunk($chunkX, $chunkZ, $chunk);
		VanillaEndSpikePopulator::populate($world, $chunkX, $chunkZ, $seed);
		$x = $spike->centerX & Chunk::COORD_MASK;
		$z = $spike->centerZ & Chunk::COORD_MASK;
		self::assertSame(VanillaBlocks::OBSIDIAN()->getStateId(), $chunk->getBlockStateId($x, $spike->height - 1, $z));
		self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $chunk->getBlockStateId($x, $spike->height, $z));
		self::assertSame(VanillaBlocks::FIRE()->getStateId(), $chunk->getBlockStateId($x, $spike->height + 1, $z));
	}

	public function testOptInRegistryAndDimensionIsolation() : void{
		VanillaGenerators::register();
		$manager = GeneratorManager::getInstance();
		self::assertSame(RyzeVanillaNetherCoreGenerator::class, $manager->getGenerator(VanillaGenerators::NETHER_CORE)?->getGeneratorClass());
		self::assertSame(RyzeVanillaEndCoreGenerator::class, $manager->getGenerator(VanillaGenerators::END_CORE)?->getGeneratorClass());
		self::assertNotSame($manager->getGenerator(VanillaGenerators::NETHER_CORE), $manager->getGenerator(VanillaGenerators::NETHER));
		self::assertNotSame($manager->getGenerator(VanillaGenerators::END_CORE), $manager->getGenerator(VanillaGenerators::END));
		$nether = new SimpleChunkManager(-64, 320);
		$end = new SimpleChunkManager(-64, 320);
		$netherChunk = new Chunk([], false);
		$endChunk = new Chunk([], false);
		$nether->setChunk(-1, 0, $netherChunk);
		$end->setChunk(-1, 0, $endChunk);
		$preset = 'core-v1;data=' . $this->dir;
		(new RyzeVanillaNetherCoreGenerator(7755, $preset))->generateChunk($nether, -1, 0);
		(new RyzeVanillaEndCoreGenerator(7755, $preset))->generateChunk($end, -1, 0);
		self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $netherChunk->getBlockStateId(4, 0, 4));
		self::assertSame(VanillaBlocks::NETHERRACK()->getStateId(), $netherChunk->getBlockStateId(4, 24, 4));
		self::assertSame(BiomeIds::HELL, $netherChunk->getBiomeId(4, 24, 4));
		self::assertSame(VanillaBlocks::END_STONE()->getStateId(), $endChunk->getBlockStateId(4, 24, 4));
		self::assertSame(BiomeIds::THE_END, $endChunk->getBiomeId(4, 24, 4));
		self::assertNotSame($netherChunk->getBlockStateId(4, 24, 4), $endChunk->getBlockStateId(4, 24, 4));
	}
}
