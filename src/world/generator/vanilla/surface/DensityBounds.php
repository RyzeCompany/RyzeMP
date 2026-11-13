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

use pocketmine\world\generator\vanilla\density\BinaryFunction;
use pocketmine\world\generator\vanilla\density\CacheFunction;
use pocketmine\world\generator\vanilla\density\ClampFunction;
use pocketmine\world\generator\vanilla\density\ConstantFunction;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\GradientFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\density\NamedFunction;
use pocketmine\world\generator\vanilla\density\RangeChoiceFunction;
use pocketmine\world\generator\vanilla\density\UnaryFunction;
use function max;
use function min;

/**
 * Conservative value range of a density function over a block box (interval arithmetic), used to skip whole cells
 * where a material rule's density function can never be positive. Unknown node types use their declared range.
 */
final class DensityBounds{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param InterpolationCache|null $cache corner values for interpolated nodes; null = use declared ranges
	 * @phpstan-return array{float, float}
	 */
	public static function of(DensityFunction $f, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, ?InterpolationCache $cache) : array{
		if($f instanceof ConstantFunction){
			return [$f->value, $f->value];
		}
		if($f instanceof NamedFunction){
			return self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
		}
		if($f instanceof CacheFunction){
			return self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
		}
		if($f instanceof InterpolatedFunction){
			return $cache !== null ? $cache->range($f, $x0, $y0, $z0, $x1, $y1, $z1) : self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, null);
		}
		if($f instanceof GradientFunction && $f->tiling === GradientFunction::TILING_CLAMP){
			//monotonic along its axis
			$a = $f->compute($x0, $y0, $z0);
			$b = $f->compute($x1, $y1, $z1);
			return [min($a, $b), max($a, $b)];
		}
		if($f instanceof RangeChoiceFunction){
			[$lo, $hi] = self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			if($lo >= $f->minInclusive && $hi < $f->maxExclusive){
				return self::of($f->whenInRange, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			}
			if($hi < $f->minInclusive || $lo >= $f->maxExclusive){
				return self::of($f->whenOutOfRange, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			}
			[$a, $b] = self::of($f->whenInRange, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			[$c, $d] = self::of($f->whenOutOfRange, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			return [min($a, $c), max($b, $d)];
		}
		if($f instanceof ClampFunction){
			[$lo, $hi] = self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			return [min(max($lo, $f->min), $f->max), min(max($hi, $f->min), $f->max)];
		}
		if($f instanceof UnaryFunction){
			if($f->op === UnaryFunction::NEGATE){
				[$lo, $hi] = self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
				return [-$hi, -$lo];
			}
			if($f->op === UnaryFunction::ABS){
				[$lo, $hi] = self::of($f->input, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
				if($lo >= 0){
					return [$lo, $hi];
				}
				if($hi <= 0){
					return [-$hi, -$lo];
				}
				return [0.0, max(-$lo, $hi)];
			}
			return [$f->minValue, $f->maxValue];
		}
		if($f instanceof BinaryFunction && $f->op !== BinaryFunction::DIV){
			[$a, $b] = self::of($f->left, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			[$c, $d] = self::of($f->right, $x0, $y0, $z0, $x1, $y1, $z1, $cache);
			return match($f->op){
				BinaryFunction::ADD => [$a + $c, $b + $d],
				BinaryFunction::SUB => [$a - $d, $b - $c],
				BinaryFunction::MUL => [min($a * $c, $a * $d, $b * $c, $b * $d), max($a * $c, $a * $d, $b * $c, $b * $d)],
				BinaryFunction::MIN => [min($a, $c), min($b, $d)],
				BinaryFunction::MAX => [max($a, $c), max($b, $d)],
				default => [$f->minValue, $f->maxValue]
			};
		}
		return [$f->minValue, $f->maxValue];
	}
}
