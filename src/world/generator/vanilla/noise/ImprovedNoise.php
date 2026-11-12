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
use function array_values;
use function floor;

/**
 * One octave of 3D gradient ("Perlin") noise with a random permutation and offset
 * (ported from misode/deepslate GradientNoise/PerlinNoise, MIT).
 *
 * The permutation table is stored pre-multiplied into gradient tables so that sampling only needs flat int/float
 * array reads: $p is the permutation (values 0-255), $gx/$gy/$gz the gradient vector selected by p[i] & 15.
 */
final class ImprovedNoise{
	/** Vanilla gradient table (16 entries, the last four repeat earlier ones). */
	public const GRADIENT_X = [1, -1, 1, -1, 1, -1, 1, -1, 0, 0, 0, 0, 1, 0, -1, 0];
	public const GRADIENT_Y = [1, 1, -1, -1, 0, 0, 0, 0, 1, -1, 1, -1, 1, -1, 1, -1];
	public const GRADIENT_Z = [0, 0, 0, 0, 1, 1, -1, -1, 1, 1, -1, -1, 0, 1, 0, -1];

	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	public array $p;
	/**
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public array $gx = [];
	/**
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public array $gy = [];
	/**
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public array $gz = [];

	public float $xo;
	public float $yo;
	public float $zo;

	public function __construct(RandomSource $random){
		$this->xo = $random->nextDouble() * 256.0;
		$this->yo = $random->nextDouble() * 256.0;
		$this->zo = $random->nextDouble() * 256.0;
		$p = [];
		for($i = 0; $i < 256; ++$i){
			$p[] = $i;
		}
		for($i = 0; $i < 256; ++$i){
			$offset = $random->nextBoundedInt(256 - $i);
			$tmp = $p[$i];
			$p[$i] = $p[$i + $offset];
			$p[$i + $offset] = $tmp;
		}
		$this->p = array_values($p);
		foreach($p as $hash){
			$this->gx[] = (float) self::GRADIENT_X[$hash & 15];
			$this->gy[] = (float) self::GRADIENT_Y[$hash & 15];
			$this->gz[] = (float) self::GRADIENT_Z[$hash & 15];
		}
	}

	/** Coordinates are wrapped to avoid precision loss far from the origin. */
	public static function wrap(float $x) : float{
		return $x - floor($x / 33554432.0 + 0.5) * 33554432.0;
	}

	/**
	 * Samples the octave. x/y/z must already be scaled by the octave frequency; wrapping and offsets are applied here.
	 */
	public function sample(float $x, float $y, float $z) : float{
		return self::sampleRaw($this->p, $this->gx, $this->gy, $this->gz,
			$x - floor($x / 33554432.0 + 0.5) * 33554432.0 + $this->xo,
			$y - floor($y / 33554432.0 + 0.5) * 33554432.0 + $this->yo,
			$z - floor($z / 33554432.0 + 0.5) * 33554432.0 + $this->zo
		);
	}

	/**
	 * Y-smeared variant used by the blended noise: the gradient y offset is snapped to multiples of $fudgeScale
	 * (computed from the unwrapped scaled coordinate $originalY) while the interpolation uses the real offset.
	 * Coordinates must already be wrapped and offset.
	 *
	 * @param int[]   $p
	 * @param float[] $gx
	 * @param float[] $gy
	 * @param float[] $gz
	 * @phpstan-param list<int>   $p
	 * @phpstan-param list<float> $gx
	 * @phpstan-param list<float> $gy
	 * @phpstan-param list<float> $gz
	 */
	public static function sampleSmearedRaw(array $p, array $gx, array $gy, array $gz, float $x, float $y, float $z, float $originalY, float $fudgeScale) : float{
		$fx = floor($x);
		$fy = floor($y);
		$fz = floor($z);
		$rx = $x - $fx;
		$ry = $y - $fy;
		$rz = $z - $fz;
		$limit = ($originalY >= 0 && $originalY < $ry) ? $originalY : $ry;
		$gry = $ry - floor($limit / $fudgeScale + 1.0E-7) * $fudgeScale;
		$ix = (int) $fx;
		$iy = (int) $fy;
		$iz = (int) $fz;

		$x0 = $p[$ix & 255];
		$x1 = $p[($ix + 1) & 255];
		$xy00 = $p[($x0 + $iy) & 255];
		$xy01 = $p[($x0 + $iy + 1) & 255];
		$xy10 = $p[($x1 + $iy) & 255];
		$xy11 = $p[($x1 + $iy + 1) & 255];

		$rx1 = $rx - 1.0;
		$ry1 = $gry - 1.0;
		$rz1 = $rz - 1.0;

		$i = ($xy00 + $iz) & 255;
		$d000 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz;
		$i = ($xy10 + $iz) & 255;
		$d100 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz;
		$i = ($xy01 + $iz) & 255;
		$d010 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy11 + $iz) & 255;
		$d110 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy00 + $iz + 1) & 255;
		$d001 = $gx[$i] * $rx + $gy[$i] * $gry + $gz[$i] * $rz1;
		$i = ($xy10 + $iz + 1) & 255;
		$d101 = $gx[$i] * $rx1 + $gy[$i] * $gry + $gz[$i] * $rz1;
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

	/**
	 * @param int[]   $p
	 * @param float[] $gx
	 * @param float[] $gy
	 * @param float[] $gz
	 * @phpstan-param list<int>   $p
	 * @phpstan-param list<float> $gx
	 * @phpstan-param list<float> $gy
	 * @phpstan-param list<float> $gz
	 */
	public static function sampleRaw(array $p, array $gx, array $gy, array $gz, float $x, float $y, float $z) : float{
		$fx = floor($x);
		$fy = floor($y);
		$fz = floor($z);
		$rx = $x - $fx;
		$ry = $y - $fy;
		$rz = $z - $fz;
		$ix = (int) $fx;
		$iy = (int) $fy;
		$iz = (int) $fz;

		$x0 = $p[$ix & 255];
		$x1 = $p[($ix + 1) & 255];
		$xy00 = $p[($x0 + $iy) & 255];
		$xy01 = $p[($x0 + $iy + 1) & 255];
		$xy10 = $p[($x1 + $iy) & 255];
		$xy11 = $p[($x1 + $iy + 1) & 255];

		$rx1 = $rx - 1.0;
		$ry1 = $ry - 1.0;
		$rz1 = $rz - 1.0;

		$i = ($xy00 + $iz) & 255;
		$d000 = $gx[$i] * $rx + $gy[$i] * $ry + $gz[$i] * $rz;
		$i = ($xy10 + $iz) & 255;
		$d100 = $gx[$i] * $rx1 + $gy[$i] * $ry + $gz[$i] * $rz;
		$i = ($xy01 + $iz) & 255;
		$d010 = $gx[$i] * $rx + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy11 + $iz) & 255;
		$d110 = $gx[$i] * $rx1 + $gy[$i] * $ry1 + $gz[$i] * $rz;
		$i = ($xy00 + $iz + 1) & 255;
		$d001 = $gx[$i] * $rx + $gy[$i] * $ry + $gz[$i] * $rz1;
		$i = ($xy10 + $iz + 1) & 255;
		$d101 = $gx[$i] * $rx1 + $gy[$i] * $ry + $gz[$i] * $rz1;
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
