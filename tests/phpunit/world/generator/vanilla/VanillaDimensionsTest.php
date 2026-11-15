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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\biome\TheEndBiomeSource;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use pocketmine\world\SimpleChunkManager;
use function array_map;
use function getenv;
use function implode;
use function is_dir;
use function json_decode;
use function md5;
use function sys_get_temp_dir;
use const JSON_THROW_ON_ERROR;

/**
 * The vanilla Nether and End: terrain, biomes, decoration and End spikes against fixtures (see dimension-fixtures.json).
 * The worldgen data is Mojang's and is not part of the repository: set POCKETMINE_VANILLA_WORLDGEN_DATA to a directory
 * filled by tools/fetch-vanilla-worldgen-data.php (data format 3).
 */
class VanillaDimensionsTest extends TestCase{

	private static function dataDir() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return $dir;
	}

	/**
	 * @return mixed[]
	 */
	private static function fixtures() : array{
		$json = json_decode(Filesystem::fileGetContents(__DIR__ . "/dimension-fixtures.json"), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		return $json;
	}

	public function testEndSpikes() : void{
		/** @phpstan-var array<string, list<array{int, int, int, int, bool}>> $expected */
		$expected = self::fixtures()["spikes"];
		foreach(Utils::stringifyKeys($expected) as $seed => $spikes){
			$actual = array_map(fn(EndSpike $s) : array => [$s->centerX, $s->centerZ, $s->radius, $s->height, $s->guarded], EndSpikes::forSeed((int) $seed));
			self::assertSame($spikes, $actual, "spikes of seed $seed");
		}
	}

	public function testDimensionGeneratorsAreLinked() : void{
		$manager = GeneratorManager::getInstance();
		self::assertSame("vanilla_nether", $manager->getDimensionGenerator("vanilla", Dimension::NETHER));
		self::assertSame("vanilla_end", $manager->getDimensionGenerator("VANILLA", Dimension::END));
		self::assertNull($manager->getDimensionGenerator("normal", Dimension::NETHER));
		self::assertSame(VanillaNetherGenerator::class, $manager->getGenerator("vanilla_nether")?->getGeneratorClass());
		self::assertSame(Dimension::END, $manager->getGenerator("vanilla_end")?->getDimension());
	}

	public function testTerrain() : void{
		$data = new WorldgenData(self::dataDir());
		$kernels = sys_get_temp_dir() . "/pmmp-vanilla-kernels";
		$fixtures = self::fixtures();
		foreach(["nether", "end"] as $dimension){
			/** @phpstan-var array<string, list<array{cx: int, cz: int, blocks: string, biomes: string}>> $seeds */
			$seeds = $fixtures[$dimension];
			foreach(Utils::stringifyKeys($seeds) as $seed => $chunks){
				$seed = (int) $seed;
				$router = new NoiseRouter($data, $seed, $dimension);
				$tree = $dimension === "end" ? ClimateBiomeTree::single("minecraft:the_end") : ClimateBiomeTree::fromData($data, $dimension);
				$filler = new CompiledTerrainFiller($router, $tree, $kernels);
				$materials = new MaterialSystem($data, $router, $tree, $seed, $kernels, $filler->getKernel(), $dimension);
				$carvers = CarverSystem::create($data, $router, $tree, $seed, $materials, $filler->getKernel());
				$names = $materials->palette->getNames();
				$end = $dimension === "end" ? new TheEndBiomeSource($router->erosion) : null;
				foreach($chunks as $chunk){
					$terrain = $filler->fill($chunk["cx"], $chunk["cz"]);
					$materials->apply($terrain);
					$carvers?->apply($terrain);
					$where = "$dimension chunk {$chunk["cx"]},{$chunk["cz"]} (seed $seed)";
					self::assertSame($chunk["blocks"], md5(implode(",", array_map(fn(int $m) : string => $names[$m], $terrain->materials))), "blocks of $where");
					$biomes = $end !== null ? $end->fillChunk($chunk["cx"], $chunk["cz"], $terrain->height >> 2) : $terrain->biomes;
					$biomeNames = $end !== null ? TheEndBiomeSource::NAMES : $tree->getBiomeNames();
					self::assertSame($chunk["biomes"], md5(implode(",", array_map(fn(int $b) : string => $biomeNames[$b], $biomes))), "biomes of $where");
				}
			}
		}
	}

	public function testCompiledMatchesInterpreted() : void{
		$data = new WorldgenData(self::dataDir());
		foreach(["nether" => [-6, 2], "end" => [66, 3]] as $dimension => [$cx, $cz]){
			$router = new NoiseRouter($data, 13579, $dimension);
			$tree = $dimension === "end" ? ClimateBiomeTree::single("minecraft:the_end") : ClimateBiomeTree::fromData($data, $dimension);
			$a = (new InterpretedTerrainFiller($router, $tree))->fill($cx, $cz);
			$b = (new CompiledTerrainFiller($router, $tree, sys_get_temp_dir() . "/pmmp-vanilla-kernels"))->fill($cx, $cz);
			self::assertSame($a->materials, $b->materials, $dimension);
			self::assertSame($a->biomes, $b->biomes, $dimension);
		}
	}

	public function testDecoration() : void{
		$dir = self::dataDir();
		/** @phpstan-var array<string, list<array{cx: int, cz: int, md5: string}>> $decorated */
		$decorated = self::fixtures()["decorated"];
		$registry = RuntimeBlockStateRegistry::getInstance();
		foreach(Utils::stringifyKeys($decorated) as $dimension => $chunks){
			$height = $dimension === "end" ? 256 : 128;
			foreach($chunks as ["cx" => $cx, "cz" => $cz, "md5" => $md5]){
				$generator = $dimension === "end" ? new VanillaEndGenerator(12345, "data=$dir;backend=compiled") : new VanillaNetherGenerator(12345, "data=$dir;backend=compiled");
				$world = new SimpleChunkManager(0, $height);
				for($dx = -1; $dx <= 1; ++$dx){
					for($dz = -1; $dz <= 1; ++$dz){
						$world->setChunk($cx + $dx, $cz + $dz, new Chunk([], false));
						$generator->generateChunk($world, $cx + $dx, $cz + $dz);
					}
				}
				$generator->populateChunk($world, $cx, $cz);
				$chunk = $world->getChunk($cx, $cz);
				self::assertNotNull($chunk);
				$parts = [];
				for($y = 0; $y < $height; ++$y){
					for($z = 0; $z < 16; ++$z){
						for($x = 0; $x < 16; ++$x){
							$parts[] = $registry->fromStateId($chunk->getBlockStateId($x, $y, $z))->getName();
						}
					}
				}
				self::assertSame($md5, md5(implode(",", $parts)), "decorated $dimension chunk $cx,$cz");
			}
		}
	}
}
