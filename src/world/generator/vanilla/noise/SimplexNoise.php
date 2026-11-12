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

namespace pocketmine\world\generator\vanilla\noise;

use pocketmine\world\generator\vanilla\random\RandomSource;
use function floor;
use function sqrt;

/**
 * Minecraft's 2D simplex noise (classic Gustavson simplex with a seeded permutation), used by the biome temperature
 * noises.
 */
final class SimplexNoise{
	private const GRADIENT = [
		[1, 1, 0], [-1, 1, 0], [1, -1, 0], [-1, -1, 0],
		[1, 0, 1], [-1, 0, 1], [1, 0, -1], [-1, 0, -1],
		[0, 1, 1], [0, -1, 1], [0, 1, -1], [0, -1, -1],
		[1, 1, 0], [0, -1, 1], [-1, 1, 0], [0, -1, -1],
	];

	public readonly float $xo;
	public readonly float $yo;
	public readonly float $zo;
	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $p = [];

	public function __construct(RandomSource $random){
		$this->xo = $random->nextDouble() * 256.0;
		$this->yo = $random->nextDouble() * 256.0;
		$this->zo = $random->nextDouble() * 256.0;
		for($i = 0; $i < 256; ++$i){
			$this->p[$i] = $i;
		}
		for($i = 0; $i < 256; ++$i){
			$offset = $random->nextBoundedInt(256 - $i);
			$tmp = $this->p[$i];
			$this->p[$i] = $this->p[$offset + $i];
			$this->p[$offset + $i] = $tmp;
		}
	}

	private function p(int $x) : int{
		return $this->p[$x & 0xff];
	}

	private static function corner(int $index, float $x, float $y, float $base) : float{
		$t = $base - $x * $x - $y * $y - 0.0 * 0.0;
		if($t < 0.0){
			return 0.0;
		}
		$t *= $t;
		$g = self::GRADIENT[$index];
		return $t * $t * ($g[0] * $x + $g[1] * $y + $g[2] * 0.0);
	}

	public function getValue2D(float $x, float $y) : float{
		$f2 = 0.5 * (sqrt(3.0) - 1.0);
		$g2 = (3.0 - sqrt(3.0)) / 6.0;
		$s = ($x + $y) * $f2;
		$i = (int) floor($x + $s);
		$j = (int) floor($y + $s);
		$t = ($i + $j) * $g2;
		$x0 = $x - ($i - $t);
		$y0 = $y - ($j - $t);
		if($x0 > $y0){
			$i1 = 1;
			$j1 = 0;
		}else{
			$i1 = 0;
			$j1 = 1;
		}
		$x1 = $x0 - $i1 + $g2;
		$y1 = $y0 - $j1 + $g2;
		$x2 = $x0 - 1.0 + 2.0 * $g2;
		$y2 = $y0 - 1.0 + 2.0 * $g2;
		$ii = $i & 0xff;
		$jj = $j & 0xff;
		$gi0 = $this->p($ii + $this->p($jj)) % 12;
		$gi1 = $this->p($ii + $i1 + $this->p($jj + $j1)) % 12;
		$gi2 = $this->p($ii + 1 + $this->p($jj + 1)) % 12;
		return 70.0 * (self::corner($gi0, $x0, $y0, 0.5) + self::corner($gi1, $x1, $y1, 0.5) + self::corner($gi2, $x2, $y2, 0.5));
	}
}
