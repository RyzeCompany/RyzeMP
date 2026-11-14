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
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\JavaHashSet;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use pocketmine\world\SimpleChunkManager;
use function file_exists;
use function getenv;
use function is_dir;

class FeatureTest extends TestCase{

	/**
	 * WorldgenRandom over Xoroshiro: next(bits) takes the high bits of nextLong(), everything else is
	 * java.util.Random's algorithm on top of next(bits).
	 */
	public function testFeatureRandomUsesBitRandomSemantics() : void{
		$feature = new FeatureRandom(42);
		$reference = XoroshiroRandom::create(42);
		$next = fn(int $bits) : int => ($reference->nextLong() >> (64 - $bits)) & ((1 << $bits) - 1);
		for($i = 0; $i < 100; ++$i){
			$bound = 1 + $i * 7;
			//java.util.Random.nextInt(bound) with rejection; bounds here are not powers of two except 1 and 8
			if(($bound & -$bound) === $bound){
				$expected = ($bound * $next(31)) >> 31;
			}else{
				do{
					$bits = $next(31);
					$value = $bits % $bound;
				}while($bits - $value + ($bound - 1) >= 1 << 31);
				$expected = $value;
			}
			self::assertSame($expected, $feature->nextBoundedInt($bound), "nextInt($bound) #$i");
		}
		$expectedFloat = $next(24) * (1.0 / (1 << 24));
		self::assertSame($expectedFloat, $feature->nextFloat());
		$expectedDouble = (($next(26) << 27) + $next(27)) * 1.1102230246251565E-16;
		self::assertSame($expectedDouble, $feature->nextDouble());
	}

	public function testDecorationSeedIsDeterministic() : void{
		$a = new FeatureRandom();
		$b = new FeatureRandom();
		$seedA = $a->setDecorationSeed(12345, 16, -32);
		$seedB = $b->setDecorationSeed(12345, 16, -32);
		self::assertSame($seedA, $seedB);
		$a->setFeatureSeed($seedA, 7, 6);
		$b->setFeatureSeed($seedB, 7, 6);
		self::assertSame($a->nextLong(), $b->nextLong());
		$b->setFeatureSeed($seedB, 8, 6);
		self::assertNotSame($a->nextLong(), $b->nextLong());
	}

	public function testJavaHashSetOrder() : void{
		//HashSet<BlockPos> with the default capacity (16): bucket = spread(hash) & 15, insertion order inside a bucket
		$set = [];
		foreach([[0, 0, 1], [0, 1, 0], [1, 0, 0], [0, 0, 0]] as [$x, $y, $z]){
			$set[JavaHashSet::key($x, $y, $z)] = [$x, $y, $z];
		}
		self::assertSame([[0, 0, 0], [0, 0, 1], [1, 0, 0], [0, 1, 0]], JavaHashSet::iterationOrder($set));
	}

	/**
	 * Blocks of chunk 0,0 (seed 12345) that match Java Edition 26.3 when only that chunk is decorated. The Java
	 * reference was taken without carvers, so they are off here too (a cave carves the gold ore at 4 -52 14). Needs the
	 * worldgen data with feature data (POCKETMINE_VANILLA_WORLDGEN_DATA, data format 2 or later).
	 */
	public function testDecorationMatchesJava() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir . "/data/minecraft/worldgen/feature") || !file_exists($dir . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no feature data");
		}
		$generator = new VanillaGenerator(12345, "data=$dir;backend=compiled;carvers=off");
		$world = new SimpleChunkManager(-64, 320);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$world->setChunk($x, $z, new Chunk([], false));
				$generator->generateChunk($world, $x, $z);
			}
		}
		$generator->populateChunk($world, 0, 0);
		$blocks = $generator->getDecorator()->blocks;
		$chunk = $world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$expected = [
			[5, -63, 8, "minecraft:deepslate_redstone_ore"],
			[0, -61, 4, "minecraft:deepslate_iron_ore"],
			[9, -61, 11, "minecraft:deepslate_diamond_ore"],
			[4, -52, 14, "minecraft:deepslate_gold_ore"],
			[14, -47, 9, "minecraft:deepslate_lapis_ore"],
			[9, -8, 7, "minecraft:deepslate_copper_ore"],
			[13, 6, 5, "minecraft:coal_ore"],
			[2, 8, 13, "minecraft:iron_ore"],
			[0, 19, 8, "minecraft:copper_ore"],
			[10, -53, 2, "minecraft:tuff"],
			[8, -61, 7, "minecraft:clay"],
			[15, 44, 7, "minecraft:tall_seagrass"],
			[10, 36, 14, "minecraft:seagrass"]
		];
		foreach($expected as [$x, $y, $z, $name]){
			self::assertSame($name, $blocks->blockName($blocks->fromPm($chunk->getBlockStateId($x, $y, $z))), "block at $x $y $z");
		}
	}
}
