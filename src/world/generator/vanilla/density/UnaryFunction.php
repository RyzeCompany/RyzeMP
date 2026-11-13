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

use function abs;
use function log;
use function max;
use function min;
use function sqrt;
use const INF;

/**
 * Single-input transforms: abs, square, cube, half_negative, quarter_negative, squeeze, negate, sqrt, log, sign,
 * reciprocal.
 */
final class UnaryFunction extends DensityFunction{
	public const ABS = "abs";
	public const SQUARE = "square";
	public const CUBE = "cube";
	public const HALF_NEGATIVE = "half_negative";
	public const QUARTER_NEGATIVE = "quarter_negative";
	public const SQUEEZE = "squeeze";
	public const NEGATE = "negate";
	public const SQRT = "sqrt";
	public const LOG = "log";
	public const SIGN = "sign";
	public const RECIPROCAL = "reciprocal";

	public function __construct(
		public readonly string $op,
		public readonly DensityFunction $input
	){
		[$min, $max] = self::range($op, $input->minValue, $input->maxValue);
		parent::__construct($min, $max);
	}

	/**
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	private static function range(string $op, float $min, float $max) : array{
		switch($op){
			case self::ABS:
				$hi = max(abs($min), abs($max));
				return $min <= 0 && $max >= 0 ? [0.0, $hi] : [min(abs($min), abs($max)), $hi];
			case self::SQUARE:
				$hi = max($min * $min, $max * $max);
				return $min <= 0 && $max >= 0 ? [0.0, $hi] : [min($min * $min, $max * $max), $hi];
			case self::CUBE:
			case self::HALF_NEGATIVE:
			case self::QUARTER_NEGATIVE:
			case self::SQUEEZE:
				return [self::apply($op, $min), self::apply($op, $max)];
			case self::NEGATE:
				return [-$max, -$min];
			case self::SIGN:
				return [-1.0, 1.0];
			default:
				return [-INF, INF];
		}
	}

	public static function apply(string $op, float $d) : float{
		switch($op){
			case self::ABS: return abs($d);
			case self::SQUARE: return $d * $d;
			case self::CUBE: return $d * $d * $d;
			case self::HALF_NEGATIVE: return $d > 0 ? $d : $d * 0.5;
			case self::QUARTER_NEGATIVE: return $d > 0 ? $d : $d * 0.25;
			case self::SQUEEZE:
				$c = $d < -1 ? -1.0 : ($d > 1 ? 1.0 : $d);
				return $c / 2 - $c * $c * $c / 24;
			case self::NEGATE: return -$d;
			case self::SQRT: return sqrt($d);
			case self::LOG: return log($d);
			case self::SIGN: return $d > 0 ? 1.0 : ($d < 0 ? -1.0 : $d);
			case self::RECIPROCAL: return 1 / $d;
			default: throw new \InvalidArgumentException("Unknown unary operation $op");
		}
	}

	public function compute(float $x, float $y, float $z) : float{
		return self::apply($this->op, $this->input->compute($x, $y, $z));
	}

	public function children() : array{
		return [$this->input];
	}
}
