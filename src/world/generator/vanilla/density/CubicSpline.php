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

namespace pocketmine\world\generator\vanilla\density;

use pocketmine\world\generator\vanilla\math\FloatMath;
use function count;
use function max;
use function min;
use const INF;

/**
 * Cubic (Hermite) spline over a density function coordinate, evaluated in single precision like vanilla
 * (ported from misode/deepslate CubicSpline, MIT). Values are either constants or nested splines.
 */
final class CubicSpline{
	/**
	 * Vanilla works in float32: the coordinate is read as a float and constant point values are stored as floats.
	 * deepslate 0.27.2 keeps both as doubles (1 ulp differences). false = deepslate behaviour, only used to compare
	 * against deepslate reference values; it must be set before the graph is parsed.
	 */
	public static bool $vanillaFloats = true;

	public readonly float $minValue;
	public readonly float $maxValue;

	/**
	 * @param float[]               $locations   already rounded to float32
	 * @param (CubicSpline|float)[] $values      constants already rounded to float32
	 * @param float[]               $derivatives already rounded to float32
	 * @phpstan-param list<float>               $locations
	 * @phpstan-param list<CubicSpline|float>   $values
	 * @phpstan-param list<float>               $derivatives
	 */
	public function __construct(
		public readonly DensityFunction $coordinate,
		public readonly array $locations,
		public readonly array $values,
		public readonly array $derivatives
	){
		[$this->minValue, $this->maxValue] = $this->computeRange();
	}

	/**
	 * @return DensityFunction[]
	 * @phpstan-return list<DensityFunction>
	 */
	public function coordinates() : array{
		$result = [$this->coordinate];
		foreach($this->values as $value){
			if($value instanceof CubicSpline){
				foreach($value->coordinates() as $c){
					$result[] = $c;
				}
			}
		}
		return $result;
	}

	private static function valueMin(CubicSpline|float $v) : float{
		return $v instanceof CubicSpline ? $v->minValue : $v;
	}

	private static function valueMax(CubicSpline|float $v) : float{
		return $v instanceof CubicSpline ? $v->maxValue : $v;
	}

	/**
	 * Conservative output range (only used to skip work in min/max nodes).
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	private function computeRange() : array{
		$last = count($this->locations) - 1;
		$lo = INF;
		$hi = -INF;
		foreach($this->values as $v){
			$lo = min($lo, self::valueMin($v));
			$hi = max($hi, self::valueMax($v));
		}
		$inMin = $this->coordinate->minValue;
		$inMax = $this->coordinate->maxValue;
		if($inMin < $this->locations[0] && $this->derivatives[0] !== 0.0){
			foreach([self::valueMin($this->values[0]), self::valueMax($this->values[0])] as $v){
				$e = $v + $this->derivatives[0] * ($inMin - $this->locations[0]);
				$lo = min($lo, $e);
				$hi = max($hi, $e);
			}
		}
		if($inMax > $this->locations[$last] && $this->derivatives[$last] !== 0.0){
			foreach([self::valueMin($this->values[$last]), self::valueMax($this->values[$last])] as $v){
				$e = $v + $this->derivatives[$last] * ($inMax - $this->locations[$last]);
				$lo = min($lo, $e);
				$hi = max($hi, $e);
			}
		}
		for($i = 0; $i < $last; ++$i){
			$dl = $this->derivatives[$i];
			$dr = $this->derivatives[$i + 1];
			if($dl !== 0.0 || $dr !== 0.0){
				$delta = $this->locations[$i + 1] - $this->locations[$i];
				$lMin = self::valueMin($this->values[$i]);
				$lMax = self::valueMax($this->values[$i]);
				$rMin = self::valueMin($this->values[$i + 1]);
				$rMax = self::valueMax($this->values[$i + 1]);
				$maxDeltaLeft = $dl * $delta;
				$maxDeltaRight = $dr * $delta;
				$minDelta = min($maxDeltaLeft - $rMax + $lMin, -$maxDeltaRight + $rMin - $lMax);
				$maxDelta = max($maxDeltaLeft - $rMin + $lMax, -$maxDeltaRight + $rMax - $lMin);
				//Hermite segments overshoot by at most 1/4 of the tangent deltas; widen a little more to stay safe
				$lo = min($lo, min($lMin, $rMin) + 0.3 * $minDelta);
				$hi = max($hi, max($lMax, $rMax) + 0.3 * $maxDelta);
			}
		}
		if($inMin < $this->locations[0] || $inMax > $this->locations[$last]){
			if($this->derivatives[0] !== 0.0 && $inMin === -INF || $this->derivatives[$last] !== 0.0 && $inMax === INF){
				return [-INF, INF];
			}
		}
		return [$lo, $hi];
	}

	public function compute(float $x, float $y, float $z) : float{
		$c = $this->coordinate->compute($x, $y, $z);
		if(self::$vanillaFloats){
			$c = FloatMath::fround($c);
		}
		$locations = $this->locations;
		$n = count($locations) - 1;
		$i = -1;
		while($i < $n && $c >= $locations[$i + 1]){
			++$i;
		}
		if($i < 0){
			$v = $this->values[0];
			$v0 = $v instanceof CubicSpline ? $v->compute($x, $y, $z) : $v;
			return FloatMath::fround($v0 + FloatMath::fround($this->derivatives[0] * FloatMath::fround($c - $locations[0])));
		}
		if($i === $n){
			$v = $this->values[$n];
			$vn = $v instanceof CubicSpline ? $v->compute($x, $y, $z) : $v;
			return FloatMath::fround($vn + FloatMath::fround($this->derivatives[$n] * FloatMath::fround($c - $locations[$n])));
		}
		$loc0 = $locations[$i];
		$loc1 = $locations[$i + 1];
		$der0 = $this->derivatives[$i];
		$der1 = $this->derivatives[$i + 1];
		$span = FloatMath::fround($loc1 - $loc0);
		$f = FloatMath::fround(FloatMath::fround($c - $loc0) / $span);
		$v = $this->values[$i];
		$val0 = $v instanceof CubicSpline ? $v->compute($x, $y, $z) : $v;
		$v = $this->values[$i + 1];
		$val1 = $v instanceof CubicSpline ? $v->compute($x, $y, $z) : $v;
		$dv = FloatMath::fround($val1 - $val0);
		$f8 = FloatMath::fround(FloatMath::fround($der0 * $span) - $dv);
		$f9 = FloatMath::fround(FloatMath::fround(-$der1 * $span) + $dv);
		return FloatMath::fround(FloatMath::lerp($f, $val0, $val1) + FloatMath::fround(FloatMath::fround($f * FloatMath::fround(1.0 - $f)) * FloatMath::lerp($f, $f8, $f9)));
	}
}
