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

namespace pocketmine\world\generator\vanilla\surface;

use pocketmine\world\generator\vanilla\noise\NoiseStack;
use pocketmine\world\generator\vanilla\random\RandomSource;
use function array_fill;
use function count;
use function floor;

/**
 * Badlands terracotta bands: a 192-block repeating pattern generated from the seed, offset per column by a noise.
 */
final class ClayBands{
	private const SIZE = 192;

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $bands;

	public function __construct(
		RandomSource $random,
		MaterialPalette $palette,
		private readonly NoiseStack $offsetNoise
	){
		$terracotta = $palette->register("minecraft:terracotta");
		$orange = $palette->register("minecraft:orange_terracotta");
		$yellow = $palette->register("minecraft:yellow_terracotta");
		$brown = $palette->register("minecraft:brown_terracotta");
		$red = $palette->register("minecraft:red_terracotta");
		$white = $palette->register("minecraft:white_terracotta");
		$lightGray = $palette->register("minecraft:light_gray_terracotta");

		$bands = array_fill(0, self::SIZE, $terracotta);
		for($i = 0; $i < self::SIZE; ++$i){
			$i += $random->nextBoundedInt(5) + 1;
			if($i < self::SIZE){
				$bands[$i] = $orange;
			}
		}
		self::makeBands($random, $bands, 1, $yellow);
		self::makeBands($random, $bands, 2, $brown);
		self::makeBands($random, $bands, 1, $red);
		$whiteBands = self::nextIntBetweenInclusive($random, 9, 15);
		for($i = 0, $start = 0; $i < $whiteBands && $start < self::SIZE; ++$i, $start += $random->nextBoundedInt(16) + 4){
			$bands[$start] = $white;
			if($start - 1 > 0 && self::nextBoolean($random)){
				$bands[$start - 1] = $lightGray;
			}
			if($start + 1 < self::SIZE && self::nextBoolean($random)){
				$bands[$start + 1] = $lightGray;
			}
		}
		$this->bands = $bands;
	}

	/**
	 * @param int[] $bands
	 * @phpstan-param array<int, int> $bands
	 */
	private static function makeBands(RandomSource $random, array &$bands, int $baseWidth, int $material) : void{
		$count = self::nextIntBetweenInclusive($random, 6, 15);
		for($i = 0; $i < $count; ++$i){
			$width = $baseWidth + $random->nextBoundedInt(3);
			$start = $random->nextBoundedInt(self::SIZE);
			for($p = 0; $start + $p < self::SIZE && $p < $width; ++$p){
				$bands[$start + $p] = $material;
			}
		}
	}

	private static function nextIntBetweenInclusive(RandomSource $random, int $min, int $max) : int{
		return $min + $random->nextBoundedInt($max - $min + 1);
	}

	private static function nextBoolean(RandomSource $random) : bool{
		return ($random->nextLong() & 1) !== 0;
	}

	public function get(MaterialContext $c) : int{
		//Math.round()
		$offset = (int) floor($c->noise2d($this->offsetNoise) * 4.0 + 0.5);
		return $this->bands[($c->blockY + $offset + count($this->bands)) % count($this->bands)];
	}
}
