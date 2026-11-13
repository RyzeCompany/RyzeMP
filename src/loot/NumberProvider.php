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

namespace pocketmine\loot;

use pocketmine\world\generator\vanilla\math\FloatMath;
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * A vanilla number provider (constant, uniform, binomial...), with Java's float arithmetic.
 */
final class NumberProvider{

	/**
	 * @phpstan-param \Closure(LootContext) : float $float
	 * @phpstan-param \Closure(LootContext) : int $int
	 */
	private function __construct(
		private \Closure $float,
		private \Closure $int,
		public readonly ?float $constant = null
	){}

	public function getFloat(LootContext $context) : float{
		return ($this->float)($context);
	}

	public function getInt(LootContext $context) : int{
		return ($this->int)($context);
	}

	public static function constant(float $value) : self{
		$value = FloatMath::fround($value);
		$rounded = self::round($value);
		return new self(fn() : float => $value, fn() : int => $rounded, $value);
	}

	/**
	 * Java's Math.round(float).
	 */
	public static function round(float $value) : int{
		return (int) floor($value + 0.5);
	}

	/**
	 * Mth.nextInt(): a random int in [min, max], without using the random if min >= max.
	 */
	public static function nextInt(LootContext $context, int $min, int $max) : int{
		return $min >= $max ? $min : $context->random->nextBoundedInt($max - $min + 1) + $min;
	}

	/**
	 * Mth.randomBetween() for floats.
	 */
	public static function randomBetween(LootContext $context, float $min, float $max) : float{
		return FloatMath::fround(FloatMath::fround($context->random->nextFloat() * FloatMath::fround($max - $min)) + $min);
	}

	public static function parse(mixed $json, LootCompiler $compiler) : self{
		if(is_int($json) || is_float($json)){
			return self::constant((float) $json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid number provider");
		}
		$type = is_string($json["type"] ?? null) ? LootData::stripNamespace($json["type"]) : (isset($json["min"]) || isset($json["max"]) ? "uniform" : "constant");
		switch($type){
			case "constant":
				$value = $json["value"] ?? 0;
				return self::constant(is_int($value) || is_float($value) ? (float) $value : 0.0);
			case "uniform":
				$min = self::parse($json["min"] ?? 0, $compiler);
				$max = self::parse($json["max"] ?? 1, $compiler);
				return new self(
					fn(LootContext $c) : float => self::randomBetween($c, $min->getFloat($c), $max->getFloat($c)),
					fn(LootContext $c) : int => self::nextInt($c, $min->getInt($c), $max->getInt($c))
				);
			case "binomial":
				$n = self::parse($json["n"] ?? 0, $compiler);
				$p = self::parse($json["p"] ?? 0, $compiler);
				$int = function(LootContext $c) use ($n, $p) : int{
					$trials = $n->getInt($c);
					$chance = $p->getFloat($c);
					$count = 0;
					for($i = 0; $i < $trials; ++$i){
						if($c->random->nextFloat() < $chance){
							++$count;
						}
					}
					return $count;
				};
				return new self(fn(LootContext $c) : float => (float) $int($c), $int);
			case "sum":
				$summands = [];
				foreach(is_array($json["summands"] ?? null) ? $json["summands"] : [] as $summand){
					$summands[] = self::parse($summand, $compiler);
				}
				$float = function(LootContext $c) use ($summands) : float{
					$sum = 0.0;
					foreach($summands as $summand){
						$sum = FloatMath::fround($sum + $summand->getFloat($c));
					}
					return $sum;
				};
				return new self($float, fn(LootContext $c) : int => self::round($float($c)));
			default:
				$compiler->warn("number:$type", "Unsupported number provider $type (using 0)");
				return self::constant(0.0);
		}
	}
}
