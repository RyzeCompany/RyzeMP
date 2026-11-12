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

use function is_nan;
use function max;
use function min;
use const INF;

/**
 * add, sub, mul, div, min, max. mul/div skip the right side when the left side is 0, min/max skip it when the left
 * side is already outside the right side's range (same short-circuits as vanilla, so values are identical).
 */
final class BinaryFunction extends DensityFunction{
	public const ADD = "add";
	public const SUB = "sub";
	public const MUL = "mul";
	public const DIV = "div";
	public const MIN = "min";
	public const MAX = "max";

	public function __construct(
		public readonly string $op,
		public readonly DensityFunction $left,
		public readonly DensityFunction $right
	){
		$a = $left->minValue;
		$b = $left->maxValue;
		$c = $right->minValue;
		$d = $right->maxValue;
		switch($op){
			case self::ADD:
				$min = $a + $c;
				$max = $b + $d;
				break;
			case self::SUB:
				$min = $a - $d;
				$max = $b - $c;
				break;
			case self::MUL:
				$products = [self::mulBound($a, $c), self::mulBound($a, $d), self::mulBound($b, $c), self::mulBound($b, $d)];
				$min = min($products);
				$max = max($products);
				break;
			case self::MIN:
				$min = min($a, $c);
				$max = min($b, $d);
				break;
			case self::MAX:
				$min = max($a, $c);
				$max = max($b, $d);
				break;
			case self::DIV:
				$min = -INF;
				$max = INF;
				break;
			default:
				throw new \InvalidArgumentException("Unknown binary operation $op");
		}
		if(is_nan($min) || is_nan($max)){
			$min = -INF;
			$max = INF;
		}
		parent::__construct($min, $max);
	}

	private static function mulBound(float $a, float $b) : float{
		return $a === 0.0 || $b === 0.0 ? 0.0 : $a * $b;
	}

	public function compute(float $x, float $y, float $z) : float{
		$a = $this->left->compute($x, $y, $z);
		switch($this->op){
			case self::ADD: return $a + $this->right->compute($x, $y, $z);
			case self::SUB: return $a - $this->right->compute($x, $y, $z);
			case self::MUL: return $a === 0.0 ? 0.0 : $a * $this->right->compute($x, $y, $z);
			case self::DIV: return $a === 0.0 ? 0.0 : $a / $this->right->compute($x, $y, $z);
			case self::MIN:
				if($a < $this->right->minValue){
					return $a;
				}
				$b = $this->right->compute($x, $y, $z);
				return $a < $b ? $a : $b;
			case self::MAX:
				if($a > $this->right->maxValue){
					return $a;
				}
				$b = $this->right->compute($x, $y, $z);
				return $a > $b ? $a : $b;
			default: throw new \InvalidArgumentException("Unknown binary operation " . $this->op);
		}
	}

	public function children() : array{
		return [$this->left, $this->right];
	}
}
