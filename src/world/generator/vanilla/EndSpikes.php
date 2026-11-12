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

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use function cos;
use function floor;
use function intdiv;
use function sin;
use const M_PI;

/**
 * The ten obsidian spikes around the End's main island, as vanilla places them for a world seed
 * (SpikeFeature.getSpikesForLevel()): on a circle of radius 42, with heights 76..103 and radii 2..5 in a
 * seed-dependent order; the two smallest are guarded by iron bars.
 */
final class EndSpikes{
	public const COUNT = 10;
	public const RING_RADIUS = 42;

	/** @phpstan-var array<int, list<EndSpike>> */
	private static array $cache = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * @return EndSpike[]
	 * @phpstan-return list<EndSpike>
	 */
	public static function forSeed(int $seed) : array{
		$key = (new LegacyRandom($seed))->nextLong() & 0xffff;
		return self::$cache[$key] ??= self::create($key);
	}

	/**
	 * @return EndSpike[]
	 * @phpstan-return list<EndSpike>
	 */
	private static function create(int $key) : array{
		//Util.toShuffledList(IntStream.range(0, 10), RandomSource.create(key))
		$sizes = [];
		for($i = 0; $i < self::COUNT; ++$i){
			$sizes[] = $i;
		}
		$random = new LegacyRandom($key);
		for($i = self::COUNT; $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			[$sizes[$i - 1], $sizes[$swapTo]] = [$sizes[$swapTo], $sizes[$i - 1]];
		}
		$spikes = [];
		for($i = 0; $i < self::COUNT; ++$i){
			$angle = 2.0 * (-M_PI + (M_PI / 10.0) * $i);
			$size = $sizes[$i];
			$spikes[] = new EndSpike(
				(int) floor(42.0 * cos($angle)),
				(int) floor(42.0 * sin($angle)),
				2 + intdiv($size, 3),
				76 + $size * 3,
				$size === 1 || $size === 2
			);
		}
		return $spikes;
	}
}
