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

use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\RandomSource;
use function floor;

/**
 * The "old blended noise" (base 3D terrain noise): two limit noises blended by a main noise, each made of
 * y-smeared Perlin octaves (ported from misode/deepslate BlendedNoise/SmearedPerlinNoise, MIT).
 */
final class BlendedNoise{
	private const BASE_SCALE = 684.412;
	private const LIMIT_FACTOR = 0.99998474;
	private const MAIN_FACTOR = 12.75;
	private const SHIFT_UP_EPSILON = 1e-7;

	/**
	 * Flattened layers of the three stacks: [0] min limit, [1] max limit, [2] main.
	 * @var ImprovedNoise[][]
	 * @phpstan-var array{list<ImprovedNoise>, list<ImprovedNoise>, list<ImprovedNoise>}
	 */
	public array $noises;
	/**
	 * @var float[][]
	 * @phpstan-var array{list<float>, list<float>, list<float>}
	 */
	public array $frequencies;
	/**
	 * @var float[][]
	 * @phpstan-var array{list<float>, list<float>, list<float>}
	 */
	public array $amplitudes;
	/**
	 * @var float[][]
	 * @phpstan-var array{list<float>, list<float>, list<float>}
	 */
	public array $fudgeScales;

	public float $xzMultiplier;
	public float $yMultiplier;
	public float $maxValue;

	public function __construct(
		RandomSource $random,
		public readonly float $xzScale,
		public readonly float $yScale,
		public readonly float $xzFactor,
		public readonly float $yFactor,
		public readonly float $smearScaleMultiplier
	){
		$this->xzMultiplier = self::BASE_SCALE * $xzScale;
		$this->yMultiplier = self::BASE_SCALE * $yScale;
		$limitSmearScaleY = $this->yMultiplier * $smearScaleMultiplier;
		$mainSmearScaleY = $limitSmearScaleY / $yFactor;
		$min = self::createNoise($random, -15, $limitSmearScaleY, self::LIMIT_FACTOR);
		$max = self::createNoise($random, -15, $limitSmearScaleY, self::LIMIT_FACTOR);
		$main = self::createNoise($random, -7, $mainSmearScaleY, self::MAIN_FACTOR);
		$this->noises = [$min[0], $max[0], $main[0]];
		$this->frequencies = [$min[1], $max[1], $main[1]];
		$this->amplitudes = [$min[2], $max[2], $main[2]];
		$this->fudgeScales = [$min[3], $max[3], $main[3]];
		$range = 0.0;
		foreach([0, 1] as $s){
			$r = 0.0;
			foreach($this->amplitudes[$s] as $i => $a){
				$r += ($this->fudgeScales[$s][$i] + 2) * $a;
			}
			$range = $r > $range ? $r : $range;
		}
		$this->maxValue = $range;
	}

	/**
	 * @return array{list<ImprovedNoise>, list<float>, list<float>, list<float>}
	 */
	private static function createNoise(RandomSource $random, int $firstOctave, float $smearScaleY, float $valueFactor) : array{
		$octaves = -$firstOctave + 1;
		$factor = 1.0;
		$valueFactor /= (2.0 ** $octaves) - 1;
		$noises = [];
		$frequencies = [];
		$amplitudes = [];
		$fudge = [];
		for($i = $octaves - 1; $i >= 0; --$i){
			$noises[] = new ImprovedNoise($random);
			$fudge[] = $smearScaleY * $factor;
			$frequencies[] = $factor;
			$amplitudes[] = $valueFactor;
			$factor /= 2;
			$valueFactor *= 2;
		}
		return [$noises, $frequencies, $amplitudes, $fudge];
	}

	public function sample(float $x, float $y, float $z) : float{
		$xx = $x * $this->xzMultiplier;
		$yy = $y * $this->yMultiplier;
		$zz = $z * $this->xzMultiplier;
		$main = $this->stack(2, $xx / $this->xzFactor, $yy / $this->yFactor, $zz / $this->xzFactor) + 0.5;
		if($main <= 0){
			return $this->stack(0, $xx, $yy, $zz);
		}
		if($main >= 1){
			return $this->stack(1, $xx, $yy, $zz);
		}
		$a = $this->stack(0, $xx, $yy, $zz);
		$b = $this->stack(1, $xx, $yy, $zz);
		return FloatMath::lerp($main, $a, $b);
	}

	/**
	 * @phpstan-param 0|1|2 $s
	 */
	public function stack(int $s, float $x, float $y, float $z) : float{
		$value = 0.0;
		$amplitudes = $this->amplitudes[$s];
		$frequencies = $this->frequencies[$s];
		$fudgeScales = $this->fudgeScales[$s];
		foreach($this->noises[$s] as $i => $o){
			$f = $frequencies[$i];
			$value += $amplitudes[$i] * self::sampleSmeared($o, $x * $f, $y * $f, $z * $f, $fudgeScales[$i]);
		}
		return $value;
	}

	public static function sampleSmeared(ImprovedNoise $o, float $x, float $y, float $z, float $fudgeScale) : float{
		$x1 = $x - floor($x / 33554432.0 + 0.5) * 33554432.0 + $o->xo;
		$y1 = $y - floor($y / 33554432.0 + 0.5) * 33554432.0 + $o->yo;
		$z1 = $z - floor($z / 33554432.0 + 0.5) * 33554432.0 + $o->zo;
		$fx = floor($x1);
		$fy = floor($y1);
		$fz = floor($z1);
		$rx = $x1 - $fx;
		$ry = $y1 - $fy;
		$rz = $z1 - $fz;
		$limit = ($y >= 0 && $y < $ry) ? $y : $ry;
		$fudgedRy = $ry - floor($limit / $fudgeScale + self::SHIFT_UP_EPSILON) * $fudgeScale;

		$p = $o->p;
		$gx = $o->gx;
		$gy = $o->gy;
		$gz = $o->gz;
		$ix = (int) $fx;
		$iy = (int) $fy;
		$iz = (int) $fz;
		$x0 = $p[$ix & 255];
		$x1i = $p[($ix + 1) & 255];
		$xy00 = $p[($x0 + $iy) & 255];
		$xy01 = $p[($x0 + $iy + 1) & 255];
		$xy10 = $p[($x1i + $iy) & 255];
		$xy11 = $p[($x1i + $iy + 1) & 255];

		$rx1 = $rx - 1.0;
		$ry1 = $fudgedRy - 1.0;
		$rz1 = $rz - 1.0;

		$i = ($xy00 + $iz) & 255;
		$d000 = $gx[$i] * $rx + $gy[$i] * $fudgedRy + $gz[$i] * $rz;
		$i = ($xy10 + $iz) & 255;
		$d100 = $gx[$i] * $rx1 + $gy[$i] * $fudgedRy + $gz[$i] * $rz;
		$i = ($xy01 + $iz) & 255;
		$d010 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy11 + $iz) & 255;
		$d110 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy00 + $iz + 1) & 255;
		$d001 = $gx[$i] * $rx + $gy[$i] * $fudgedRy + $gz[$i] * $rz1;
		$i = ($xy10 + $iz + 1) & 255;
		$d101 = $gx[$i] * $rx1 + $gy[$i] * $fudgedRy + $gz[$i] * $rz1;
		$i = ($xy01 + $iz + 1) & 255;
		$d011 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz1;
		$i = ($xy11 + $iz + 1) & 255;
		$d111 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz1;

		$sx = $rx * $rx * $rx * ($rx * ($rx * 6.0 - 15.0) + 10.0);
		$sy = $ry * $ry * $ry * ($ry * ($ry * 6.0 - 15.0) + 10.0);
		$sz = $rz * $rz * $rz * ($rz * ($rz * 6.0 - 15.0) + 10.0);

		$a = $d000 + $sx * ($d100 - $d000);
		$b = $d010 + $sx * ($d110 - $d010);
		$c = $d001 + $sx * ($d101 - $d001);
		$d = $d011 + $sx * ($d111 - $d011);
		$ab = $a + $sy * ($b - $a);
		$cd = $c + $sy * ($d - $c);
		return $ab + $sz * ($cd - $ab);
	}
}
