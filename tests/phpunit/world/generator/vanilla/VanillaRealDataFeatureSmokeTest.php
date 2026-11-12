<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\Chunk;
use pocketmine\world\SimpleChunkManager;
use function getenv;
use function hrtime;
use function is_dir;
use function memory_get_peak_usage;

/**
 * This test does not use synthetic JSON. It runs the 3x3 generation region
 * and actual placed-feature path on an authentic prepared 26.3 cache. It is
 * skipped in ordinary CI without POCKETMINE_VANILLA_WORLDGEN_DATA.
 */
final class VanillaRealDataFeatureSmokeTest extends TestCase{

	public function testOverworldFeaturePopulationAgainstAuthenticCache() : void{
		$directory = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($directory === false || !is_dir($directory . "/data/minecraft/worldgen/placed_feature")){
			self::markTestSkipped("Authentic Minecraft 26.3 data cache with placed features required");
		}
		$hash = VanillaDataIdentity::fingerprint($directory);
		$preset = "core-v3;data=" . $directory . ";sha256=" . $hash;
		$generator = new RyzeOverworldGenerator(12345, $preset);
		$world = new SimpleChunkManager(-64, 320);
		$start = hrtime(true);
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$world->setChunk($dx, $dz, new Chunk([], false));
				$generator->generateChunk($world, $dx, $dz);
			}
		}
		$chunk = $world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$before = self::snapshot($chunk);
		$generator->populateChunk($world, 0, 0);
		$after = self::snapshot($chunk);
		$elapsed = (hrtime(true) - $start) / 1_000_000;
		fwrite(STDERR, sprintf(
			"\n[Worldgen real Java 26.3 feature smoke] 3x3 chunks and central decoration %.2f ms, peak %.2f MiB (not TPS)\n",
			$elapsed,
			memory_get_peak_usage(true) / 1048576
		));
		self::assertNotSame($before, $after, "VanillaMine placed features must place actual blocks");
	}

	public function testCompiledCoreV4MatchesInterpretedCoreV3AfterRealDecoration() : void{
		$directory = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($directory === false || !is_dir($directory . "/data/minecraft/worldgen/placed_feature")){
			self::markTestSkipped("Authentic Minecraft 26.3 VanillaMine data required");
		}
		$hash = VanillaDataIdentity::fingerprint($directory);
		$v3 = new RyzeOverworldGenerator(12345, "core-v3;data=" . $directory . ";sha256=" . $hash);
		$v4 = new RyzeOverworldGenerator(12345, "core-v4;data=" . $directory . ";sha256=" . $hash);
		$a = new SimpleChunkManager(-64, 320);
		$b = new SimpleChunkManager(-64, 320);
		$start = hrtime(true);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$a->setChunk($x, $z, new Chunk([], false));
				$b->setChunk($x, $z, new Chunk([], false));
				$v3->generateChunk($a, $x, $z);
				$v4->generateChunk($b, $x, $z);
			}
		}
		$v3->populateChunk($a, 0, 0);
		$v4->populateChunk($b, 0, 0);
		foreach([[-1,-1],[-1,0],[0,0],[0,1],[1,1]] as [$cx, $cz]){
			$ca = $a->getChunk($cx, $cz);
			$cb = $b->getChunk($cx, $cz);
			self::assertNotNull($ca);
			self::assertNotNull($cb);
			for($x = 0; $x < 16; ++$x){
				for($z = 0; $z < 16; ++$z){
					for($y = -64; $y < 320; ++$y){
						self::assertSame(
							$ca->getBlockStateId($x, $y, $z),
							$cb->getBlockStateId($x, $y, $z),
							"Compiled core-v4 differs from interpreted core-v3 at chunk $cx,$cz block $x,$y,$z"
						);
					}
					foreach([-48, 0, 64, 120, 256] as $by){
						self::assertSame($ca->getBiomeId($x, $by, $z), $cb->getBiomeId($x, $by, $z));
					}
				}
			}
		}
		fwrite(STDERR, sprintf(
			"\n[Worldgen REAL Java 26.3] core-v3 versus compiled core-v4: 3x3 chunks + decorations and 5 full-chunk block/biome equivalence scans %.2f ms\n",
			(hrtime(true) - $start) / 1000000.0
		));
	}

	/** @return list<int> */
	private static function snapshot(Chunk $chunk) : array{
		$states = [];
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				for($y = -64; $y < 96; ++$y){
					$states[] = $chunk->getBlockStateId($x, $y, $z);
				}
			}
		}
		return $states;
	}
}
