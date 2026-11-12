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
