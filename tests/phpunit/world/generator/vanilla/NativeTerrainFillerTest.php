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
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\native\NativeTerrainFiller;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function bin2hex;
use function dirname;
use function getenv;
use function implode;
use function is_dir;
use function is_file;
use function ksort;
use function md5;
use function pack;
use function putenv;
use function sys_get_temp_dir;

/**
 * The native terrain core (native/vanillagen) must produce exactly what the PHP fillers produce, and the generator
 * must fall back to PHP when it is unavailable. Needs the worldgen data (POCKETMINE_VANILLA_WORLDGEN_DATA) and a
 * built vanillagen binary for this platform (native/vanillagen/build.ps1).
 */
class NativeTerrainFillerTest extends TestCase{
	private static ?VanillaNativeBackend $backend = null;

	private static function dataDir() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return $dir;
	}

	private static function backend() : VanillaNativeBackend{
		if(self::$backend === null){
			$binary = dirname(__DIR__, 5) . "/native/vanillagen/bin/" . VanillaNativeBackend::binaryName();
			if(!is_file($binary)){
				self::markTestSkipped("vanillagen is not built for this platform ($binary)");
			}
			self::$backend = VanillaNativeBackend::start($binary, sys_get_temp_dir() . "/pmmp-vanillagen-test.log");
		}
		return self::$backend;
	}

	public static function tearDownAfterClass() : void{
		self::$backend?->stop();
		self::$backend = null;
		putenv(VanillaNativeBackend::ENV_ENDPOINT);
	}

	/**
	 * @phpstan-param \Closure() : TerrainFiller $fallback
	 */
	private static function connect(string $dir, int $seed, NoiseRouter $router, ClimateBiomeTree $tree, \Closure $fallback, int $flags = 0) : ?NativeTerrainFiller{
		return NativeTerrainFiller::connect($dir, $seed, $tree->getBiomeNames(), $router->minY, $router->height, $fallback, $flags);
	}

	public function testMatchesPhpWithAquifers() : void{
		$dir = self::dataDir();
		self::backend()->publish();
		$data = new WorldgenData($dir);
		foreach([13579, -987654321] as $seed){
			$router = new NoiseRouter($data, $seed);
			$tree = ClimateBiomeTree::fromData($data);
			$php = new CompiledTerrainFiller($router, $tree, sys_get_temp_dir() . "/pmmp-vanilla-kernels");
			$native = self::connect($dir, $seed, $router, ClimateBiomeTree::fromData($data), fn() : TerrainFiller => throw new \RuntimeException("unexpected fallback"));
			self::assertNotNull($native);
			foreach([[-2, 15], [0, 0], [31, -7], [-4000, 2500]] as [$x, $z]){
				$a = $php->fill($x, $z);
				$b = $native->fill($x, $z);
				self::assertSame($a->materials, $b->materials, "materials of chunk $x,$z (seed $seed)");
				self::assertSame($a->biomes, $b->biomes, "biomes of chunk $x,$z (seed $seed)");
			}
		}
	}

	/**
	 * FLAG_PACKED_SECTIONS: the subchunks the core packs for PalettedBlockArray::fromData() and the raw material
	 * bytes describe exactly the materials the run-length reply describes.
	 */
	public function testPackedSectionsMatchRuns() : void{
		$dir = self::dataDir();
		self::backend()->publish();
		$data = new WorldgenData($dir);
		$seed = 1001;
		$router = new NoiseRouter($data, $seed);
		$tree = ClimateBiomeTree::fromData($data);
		$fallback = fn() : TerrainFiller => throw new \RuntimeException("unexpected fallback");
		$runs = self::connect($dir, $seed, $router, $tree, $fallback, NativeTerrainClient::FLAG_MATERIALS | NativeTerrainClient::FLAG_CARVERS);
		$packed = self::connect($dir, $seed, $router, $tree, $fallback, NativeTerrainClient::FLAG_MATERIALS | NativeTerrainClient::FLAG_CARVERS | NativeTerrainClient::FLAG_PACKED_SECTIONS);
		self::assertNotNull($runs);
		self::assertNotNull($packed);
		$height = $router->height;
		foreach([[0, 0], [-2, 15], [31, -7], [-4000, 2500], [120, 120]] as [$x, $z]){
			$a = $runs->fill($x, $z);
			$b = $packed->fill($x, $z);
			self::assertSame($a->stage, $b->stage);
			self::assertSame($a->biomes, $b->biomes, "biomes of chunk $x,$z");
			self::assertSame($a->carvingMask, $b->carvingMask, "carving mask of chunk $x,$z");
			self::assertSame([], $b->materials);
			self::assertNotNull($b->materialBytes);
			self::assertSame(pack("C*", ...$a->materials), $b->materialBytes, "material bytes of chunk $x,$z");
			self::assertNotNull($b->sections);
			self::assertCount($height >> 4, $b->sections);
			foreach($b->sections as $s => $section){
				$bottom = $s << 4;
				if($section === null){
					for($i = 0; $i < 256; ++$i){
						for($y = 0; $y < 16; ++$y){
							self::assertSame(Aquifer::MATERIAL_AIR, $a->materials[$i * $height + $bottom + $y], "air section $s of chunk $x,$z");
						}
					}
					continue;
				}
				[$bits, $words, $palette] = $section;
				$array = PalettedBlockArray::fromData($bits, $words, $palette);
				for($bx = 0; $bx < 16; ++$bx){
					for($bz = 0; $bz < 16; ++$bz){
						$column = (($bx << 4) | $bz) * $height + $bottom;
						for($y = 0; $y < 16; ++$y){
							if($a->materials[$column + $y] !== $array->get($bx, $y, $bz)){
								self::fail("section $s of chunk $x,$z differs at $bx,$y,$bz");
							}
						}
					}
				}
			}
		}
	}

	public function testMatchesDeepslateFixtures() : void{
		$dir = self::dataDir();
		self::backend()->publish();
		$data = new WorldgenData($dir);
		$chars = [".", "X", "~", "L"];
		foreach(Utils::stringifyKeys(FixtureLoader::worldgen()) as $seed => $fixture){
			$router = new NoiseRouter($data, (int) $seed);
			$tree = ClimateBiomeTree::fromData($data);
			$native = self::connect($dir, (int) $seed, $router, $tree, fn() : TerrainFiller => throw new \RuntimeException("unexpected fallback"),
				NativeTerrainClient::FLAG_NO_AQUIFERS | NativeTerrainClient::FLAG_DEEPSLATE_FLOATS);
			self::assertNotNull($native);
			foreach($fixture["chunksNoAquifer"] as $chunk){
				$out = [];
				foreach($native->fill($chunk["cx"], $chunk["cz"])->materials as $m){
					$out[] = $chars[$m];
				}
				self::assertSame($chunk["md5"], md5(implode("", $out)), "native terrain of chunk {$chunk["cx"]},{$chunk["cz"]} (seed $seed)");
			}
		}
	}

	public function testFallbackWhenUnavailable() : void{
		$dir = self::dataDir();
		$data = new WorldgenData($dir);
		$router = new NoiseRouter($data, 42);
		$tree = ClimateBiomeTree::fromData($data);

		//nothing published: no native filler
		putenv(VanillaNativeBackend::ENV_ENDPOINT);
		self::assertNull(self::connect($dir, 42, $router, $tree, fn() : TerrainFiller => new InterpretedTerrainFiller($router, $tree)));

		//published but not listening
		putenv(VanillaNativeBackend::ENV_ENDPOINT . "=127.0.0.1:1");
		putenv(VanillaNativeBackend::ENV_TOKEN . "=x");
		self::assertNull(self::connect($dir, 42, $router, $tree, fn() : TerrainFiller => new InterpretedTerrainFiller($router, $tree)));

		//helper dies after the handshake: the filler switches to the fallback
		$backend = self::backend();
		$backend->publish();
		$fallbacks = new \ArrayObject();
		$php = new CompiledTerrainFiller($router, $tree, sys_get_temp_dir() . "/pmmp-vanilla-kernels");
		$native = self::connect($dir, 42, $router, ClimateBiomeTree::fromData($data), function() use ($fallbacks, $php) : TerrainFiller{
			$fallbacks->append(true);
			return $php;
		});
		self::assertNotNull($native);
		$native->fill(3, 3);
		self::assertCount(0, $fallbacks);
		$backend->stop();
		self::$backend = null;
		$result = $native->fill(4, 4);
		self::assertCount(1, $fallbacks, "fallback filler used after the helper stopped");
		self::assertNull($native->getClient());
		self::assertSame($php->fill(4, 4)->materials, $result->materials);
	}
	/**
	 * @return int[][] block state ids per sub-chunk index
	 * @phpstan-return array<int, list<int>>
	 */
	private static function chunkBlocks(VanillaGenerator $generator, int $x, int $z) : array{
		$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		$manager->setChunk($x, $z, new Chunk([], false));
		$generator->generateChunk($manager, $x, $z);
		$chunk = $manager->getChunk($x, $z) ?? throw new \AssertionError("chunk missing");
		$out = [];
		foreach($chunk->getSubChunks() as $y => $sub){
			$states = [];
			for($bx = 0; $bx < 16; ++$bx){
				for($bz = 0; $bz < 16; ++$bz){
					for($by = 0; $by < 16; ++$by){
						$states[] = $sub->getBlockStateId($bx, $by, $bz);
					}
				}
			}
			$out[$y] = $states;
		}
		return $out;
	}

	/**
	 * Biome arrays packed by the native core (FLAG_PACKED_BIOMES) and the terrain heights it computes once it has the
	 * height classes are exactly what PHP builds: same bits, palette order and words, same packed heights.
	 */
	public function testPackedBiomesAndTerrainHeightsMatchPhp() : void{
		$dir = self::dataDir();
		self::backend()->publish();
		$seed = 13579;
		$php = new VanillaGenerator($seed, "data=$dir;backend=compiled;structures=false");
		$native = new VanillaGenerator($seed, "data=$dir;structures=false");
		//the first chunk sends the height classes, the next ones come with native heights
		foreach([[0, 0], [1, 0], [-40, 25], [300, -700], [1500, 1500]] as [$x, $z]){
			$chunks = [];
			foreach([$php, $native] as $generator){
				$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
				$manager->setChunk($x, $z, new Chunk([], false));
				$generator->generateChunk($manager, $x, $z);
				$chunks[] = $manager->getChunk($x, $z) ?? throw new \AssertionError("chunk missing");
			}
			foreach($chunks[0]->getSubChunks() as $y => $sub){
				$a = $sub->getBiomeArray();
				$b = $chunks[1]->getSubChunk($y)->getBiomeArray();
				self::assertSame([$a->getBitsPerBlock(), $a->getPalette(), $a->getWordArray()], [$b->getBitsPerBlock(), $b->getPalette(), $b->getWordArray()], "biomes of subchunk $y of chunk $x,$z");
			}
			$expected = $php->getDecorator()->getRecordedTerrainHeights($x, $z);
			self::assertNotNull($expected);
			self::assertSame(bin2hex($expected), bin2hex($native->getDecorator()->getRecordedTerrainHeights($x, $z) ?? ""), "terrain heights of chunk $x,$z");
		}
	}

	/**
	 * The whole generator (terrain, material rules, carvers) gives the same blocks with the native core as with PHP,
	 * also after switching to PHP in the middle of a session because the program died.
	 */
	public function testGeneratorMatchesPhpAndFallsBack() : void{
		$dir = self::dataDir();
		$backend = self::backend();
		$backend->publish();
		$seed = 24680;
		$php = new VanillaGenerator($seed, "data=$dir;backend=compiled");
		$native = new VanillaGenerator($seed, "data=$dir");
		foreach([[0, 0], [7, -3], [-1500, 900]] as [$x, $z]){
			self::assertSame(self::chunkBlocks($php, $x, $z), self::chunkBlocks($native, $x, $z), "chunk $x,$z");
			$a = $php->getCarvingMask($x, $z) ?? [];
			$b = $native->getCarvingMask($x, $z) ?? [];
			ksort($a);
			ksort($b);
			self::assertSame($a, $b, "carving mask of chunk $x,$z");
		}
		$backend->stop();
		self::$backend = null;
		self::assertSame(self::chunkBlocks($php, 8, -3), self::chunkBlocks($native, 8, -3), "chunk 8,-3 after the fallback");
	}
}
