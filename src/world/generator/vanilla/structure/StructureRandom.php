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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use function array_values;
use function count;

/**
 * Random helpers of the structure code: vanilla's WorldgenRandom seeding methods over a legacy (java.util.Random)
 * source, and the Util/Mth helpers that structures use with any random source.
 */
final class StructureRandom{

	private function __construct(){
		//NOOP
	}

	/**
	 * WorldgenRandom.setLargeFeatureSeed(levelSeed, chunkX, chunkZ) on a new legacy random.
	 */
	public static function largeFeature(int $levelSeed, int $chunkX, int $chunkZ) : LegacyRandom{
		$random = new LegacyRandom($levelSeed);
		$a = $random->nextLong();
		$b = $random->nextLong();
		$random->setSeed(Int64::mul($chunkX, $a) ^ Int64::mul($chunkZ, $b) ^ $levelSeed);
		return $random;
	}

	/**
	 * WorldgenRandom.setLargeFeatureWithSalt(levelSeed, regionX, regionZ, salt) on a new legacy random.
	 */
	public static function largeFeatureWithSalt(int $levelSeed, int $regionX, int $regionZ, int $salt) : LegacyRandom{
		return new LegacyRandom(Int64::add(Int64::add(Int64::add(Int64::mul($regionX, 341873128712), Int64::mul($regionZ, 132897987541)), $levelSeed), $salt));
	}

	/**
	 * Util.shuffle(list, random)
	 * @phpstan-template T
	 * @phpstan-param list<T> $list
	 * @phpstan-param-out list<T> $list
	 */
	public static function shuffle(array &$list, LegacyRandom|FeatureRandom $random) : void{
		$list = self::shuffled($list, $random);
	}

	/**
	 * Util.shuffledCopy(list, random)
	 * @phpstan-template T
	 * @phpstan-param list<T> $list
	 * @phpstan-return list<T>
	 */
	public static function shuffled(array $list, LegacyRandom|FeatureRandom $random) : array{
		for($j = count($list); $j > 1; --$j){
			$k = $random->nextBoundedInt($j);
			$tmp = $list[$j - 1];
			$list[$j - 1] = $list[$k];
			$list[$k] = $tmp;
		}
		return array_values($list);
	}

	/** Mth.randomBetweenInclusive / RandomSource.nextIntBetweenInclusive */
	public static function between(LegacyRandom|FeatureRandom $random, int $min, int $max) : int{
		return $random->nextBoundedInt($max - $min + 1) + $min;
	}

	/** Mth.nextInt(random, min, max): min if min >= max */
	public static function mthNextInt(LegacyRandom|FeatureRandom $random, int $min, int $max) : int{
		return $min >= $max ? $min : $random->nextBoundedInt($max - $min + 1) + $min;
	}
}
