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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\JavaHashSet;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
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

}
