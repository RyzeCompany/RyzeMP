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

namespace pocketmine\world\locate;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\biome\VanillaBiomeSource;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\SimpleChunkManager;
use function getenv;
use function hrtime;
use function in_array;
use function is_dir;
use function sort;

class BiomeLocatorTest extends TestCase{

	private static function dataDir() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return $dir;
	}

	public function testResolveBiomeName() : void{
		self::assertSame([["badlands"], [BiomeIds::MESA]], BiomeLocator::resolveBiomeName("mesa"), "Bedrock name");
		self::assertSame([["badlands"], [BiomeIds::MESA]], BiomeLocator::resolveBiomeName("minecraft:Badlands"), "Java name");
		self::assertSame([["desert"], [BiomeIds::DESERT]], BiomeLocator::resolveBiomeName("desert"));
		self::assertNull(BiomeLocator::resolveBiomeName("not_a_biome"));
		self::assertNull(BiomeLocator::resolveBiomeName(""));
		$end = BiomeLocator::resolveBiomeName("the_end");
		self::assertNotNull($end);
		$javaNames = $end[0];
		sort($javaNames);
		self::assertSame(["end_barrens", "end_highlands", "end_midlands", "small_end_islands", "the_end"], $javaNames);
		self::assertTrue(in_array("lush_caves", BiomeLocator::getKnownBiomeNames(), true));
		self::assertTrue(in_array("mesa", BiomeLocator::getKnownBiomeNames(), true));
	}

	public function testSampleHeights() : void{
		self::assertSame([64, 128, 0, 192, -64, 256, 320], BiomeSearch::sampleHeights(64, -64, 320, 64));
		self::assertSame([-64, 0], BiomeSearch::sampleHeights(-100, -64, 0, 64));
	}

	public function testSpiralOrder() : void{
		$visited = [];
		$found = BiomeSearch::find(function(int $x, int $z, array $ys) use (&$visited) : ?int{
			$visited[] = [$x, $z];
			return $x === 64 && $z === -32 ? $ys[0] : null;
		}, 0, 0, [70], 1000, 32);
		self::assertNotNull($found);
		self::assertSame([64, 70, -32], [$found->getFloorX(), $found->getFloorY(), $found->getFloorZ()]);
		self::assertSame([0, 0], $visited[0]);
		self::assertCount(1 + 8 + 7, $visited, "rings 0 and 1 are searched completely before ring 2");

		self::assertNull(BiomeSearch::find(fn(int $x, int $z, array $ys) : ?int => null, 0, 0, [0], 100, 32));
	}

	public function testSearchTimeout() : void{
		$this->expectException(LocateTimeoutException::class);
		BiomeSearch::find(fn(int $x, int $z, array $ys) : ?int => null, 0, 0, [0], 6400, 32, hrtime(true) - 1);
	}

	public function testBedrockBiomeNames() : void{
		$names = BiomeLocator::getBedrockBiomeNames();
		self::assertContains("mesa", $names);
		self::assertContains("roofed_forest", $names);
		self::assertNotContains("badlands", $names);
		foreach($names as $name){
			self::assertNotNull(BiomeLocator::resolveBiomeName($name), $name);
		}
	}

	/**
	 * /locate biome desert on a vanilla world (seed 3): searched from the climate noise without generating chunks; the
	 * generator then really places a desert there.
	 */
	public function testLocateDesertInVanillaWorld() : void{
		$dir = self::dataDir();
		$source = new VanillaBiomeSource(WorldgenData::shared($dir), 3, "overworld", $dir . "/compiled");
		$names = $source->getBiomeNames();
		self::assertSame("old_growth_birch_forest", $names[$source->getBiome(0, 64, 0)]);

		$found = LocateVanillaBiomeTask::search($source, ["desert"], 0, 64, 0, 1600);
		self::assertNotNull($found);
		self::assertSame([-864, 64, -1152], [$found->getFloorX(), $found->getFloorY(), $found->getFloorZ()]);
		self::assertSame("desert", $names[$source->getBiome($found->getFloorX(), $found->getFloorY(), $found->getFloorZ())]);

		self::assertNull(LocateVanillaBiomeTask::search($source, ["desert"], 0, 64, 0, 500), "no desert within 500 blocks");
		self::assertNull(LocateVanillaBiomeTask::search($source, ["crimson_forest"], 0, 64, 0, 6400), "Nether biomes are not in the Overworld");

		$generator = new VanillaGenerator(3, "data=$dir;backend=compiled;carvers=off;features=false");
		$world = new SimpleChunkManager(-64, 320);
		$chunkX = $found->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $found->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		$world->setChunk($chunkX, $chunkZ, new Chunk([], false));
		$generator->generateChunk($world, $chunkX, $chunkZ);
		self::assertSame(BiomeIds::DESERT, $world->getChunk($chunkX, $chunkZ)?->getBiomeId($found->getFloorX() & Chunk::COORD_MASK, 64, $found->getFloorZ() & Chunk::COORD_MASK));
	}

	public function testNetherBiomes() : void{
		$dir = self::dataDir();
		$source = new VanillaBiomeSource(WorldgenData::shared($dir), 3, "nether", $dir . "/compiled");
		$found = LocateVanillaBiomeTask::search($source, ["crimson_forest"], 0, 64, 0, 3000);
		self::assertNotNull($found);
		self::assertSame("crimson_forest", $source->getBiomeNames()[$source->getBiome($found->getFloorX(), $found->getFloorY(), $found->getFloorZ())]);
	}
}
