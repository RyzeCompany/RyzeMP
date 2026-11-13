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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\generator\vanilla\DensityFunctionParser;
use pocketmine\world\generator\vanilla\noise\ImprovedNoise;
use pocketmine\world\generator\vanilla\random\RandomSource;
use function floor;

/**
 * Vanilla 26.x NormalNoise.create() as used by features (NoiseStack of float PerlinNoise octaves): unlike the density
 * function noises, these sample and accumulate in float32. Used by the noise block state providers.
 */
final class FloatNoise{
	private const INPUT_FACTOR = 1.0181268882175227;

	/** @phpstan-var list<ImprovedNoise> */
	private array $noises = [];
	/** @phpstan-var list<float> */
	private array $frequencies = [];
	/** @phpstan-var list<float> float32 amplitudes */
	private array $amplitudes = [];

	/**
	 * @param mixed[] $json noise parameters
	 */
	public function __construct(array $json, RandomSource $random){
		$parameters = DensityFunctionParser::noiseParameters($json);
		$factor = $parameters->getNormalizationFactor();
		$first = $random->forkPositional();
		$second = $random->forkPositional();
		foreach($parameters->getOctaves() as [$index, $frequency, $amplitude]){
			$name = "octave_" . $index;
			$valueFactor = Providers::f($factor * $amplitude);
			$this->noises[] = new ImprovedNoise($first->fromHashOf($name));
			$this->frequencies[] = $frequency;
			$this->amplitudes[] = $valueFactor;
			$this->noises[] = new ImprovedNoise($second->fromHashOf($name));
			$this->frequencies[] = $frequency * self::INPUT_FACTOR;
			$this->amplitudes[] = $valueFactor;
		}
	}

	public function get(float $x, float $y, float $z) : float{
		$value = 0.0;
		foreach($this->noises as $i => $noise){
			$f = $this->frequencies[$i];
			$value = Providers::f($value + Providers::f($this->amplitudes[$i] * self::perlin($noise, $x * $f, $y * $f, $z * $f)));
		}
		return $value;
	}

	private static function wrap(float $x) : float{
		if($x < -16777215.999999998 || $x >= 16777215.999999998){
			return $x - floor($x / 33554432.0 + 0.5) * 33554432.0;
		}
		return $x;
	}

	/**
	 * 26.x PerlinNoise.get(x, y, z): float32 interpolation.
	 */
	private static function perlin(ImprovedNoise $n, float $x, float $y, float $z) : float{
		$x = self::wrap($x) + $n->xo;
		$y = self::wrap($y) + $n->yo;
		$z = self::wrap($z) + $n->zo;
		$fx = (int) floor($x);
		$fy = (int) floor($y);
		$fz = (int) floor($z);
		$rx = Providers::f($x - $fx);
		$ry = Providers::f($y - $fy);
		$rz = Providers::f($z - $fz);
		$p = $n->p;
		$x0 = $p[$fx & 255];
		$x1 = $p[($fx + 1) & 255];
		$xy00 = $p[($x0 + $fy) & 255];
		$xy01 = $p[($x0 + $fy + 1) & 255];
		$xy10 = $p[($x1 + $fy) & 255];
		$xy11 = $p[($x1 + $fy + 1) & 255];
		$rx1 = Providers::f($rx - 1.0);
		$ry1 = Providers::f($ry - 1.0);
		$rz1 = Providers::f($rz - 1.0);
		$d000 = self::grad($p[($xy00 + $fz) & 255], $rx, $ry, $rz);
		$d100 = self::grad($p[($xy10 + $fz) & 255], $rx1, $ry, $rz);
		$d010 = self::grad($p[($xy01 + $fz) & 255], $rx, $ry1, $rz);
		$d110 = self::grad($p[($xy11 + $fz) & 255], $rx1, $ry1, $rz);
		$d001 = self::grad($p[($xy00 + $fz + 1) & 255], $rx, $ry, $rz1);
		$d101 = self::grad($p[($xy10 + $fz + 1) & 255], $rx1, $ry, $rz1);
		$d011 = self::grad($p[($xy01 + $fz + 1) & 255], $rx, $ry1, $rz1);
		$d111 = self::grad($p[($xy11 + $fz + 1) & 255], $rx1, $ry1, $rz1);
		$ax = self::smoothstep($rx);
		$ay = self::smoothstep($ry);
		$az = self::smoothstep($rz);
		return self::lerp($az,
			self::lerp($ay, self::lerp($ax, $d000, $d100), self::lerp($ax, $d010, $d110)),
			self::lerp($ay, self::lerp($ax, $d001, $d101), self::lerp($ax, $d011, $d111))
		);
	}

	private static function grad(int $hash, float $x, float $y, float $z) : float{
		$g = $hash & 15;
		return Providers::f(Providers::f(ImprovedNoise::GRADIENT_X[$g] * $x + ImprovedNoise::GRADIENT_Y[$g] * $y) + ImprovedNoise::GRADIENT_Z[$g] * $z);
	}

	private static function smoothstep(float $x) : float{
		$x3 = Providers::f(Providers::f($x * $x) * $x);
		$inner = Providers::f(Providers::f($x * Providers::f(Providers::f($x * 6.0) - 15.0)) + 10.0);
		return Providers::f($x3 * $inner);
	}

	private static function lerp(float $alpha, float $a, float $b) : float{
		return Providers::f($a + Providers::f($alpha * Providers::f($b - $a)));
	}
}
