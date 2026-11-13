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

namespace pocketmine\world\generator\vanilla\carver;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_array;
use function is_float;
use function is_int;

/**
 * Float, int and height providers of the worldgen data (FloatProvider, IntProvider, HeightProvider), sampled with
 * the same random draws and float32 arithmetic as vanilla.
 */
final class ValueProvider{
	public const CONSTANT = 0;
	public const UNIFORM = 1;
	public const TRAPEZOID = 2;
	public const BIASED_TO_BOTTOM = 3;
	public const VERY_BIASED_TO_BOTTOM = 4;

	/**
	 * @param float[] $params constant: [value]; float uniform: [min, max exclusive]; trapezoid: [min, max, plateau];
	 *                        int and height providers: [min inclusive, max inclusive] (resolved y for heights)
	 * @phpstan-param list<float> $params
	 */
	private function __construct(
		public readonly int $type,
		public readonly array $params
	){}

	/** FloatProvider: a number or {"type": "constant"|"uniform"|"trapezoid", ...}. Values are floats (float32). */
	public static function float(mixed $json) : self{
		if(is_int($json) || is_float($json)){
			return new self(self::CONSTANT, [CarverMath::f((float) $json)]);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid float provider");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		$f = fn(string $key) : float => CarverMath::f(WorldgenData::float($json, $key));
		return match($type){
			"constant" => new self(self::CONSTANT, [$f("value")]),
			"uniform" => new self(self::UNIFORM, [$f("min_inclusive"), $f("max_exclusive")]),
			"trapezoid" => new self(self::TRAPEZOID, [$f("min"), $f("max"), $f("plateau")]),
			default => throw new \RuntimeException("Unsupported float provider type \"$type\"")
		};
	}

	/** IntProvider: an integer or {"type": "constant"|"uniform"|"biased_to_bottom"|"very_biased_to_bottom", ...}. */
	public static function int(mixed $json) : self{
		if(is_int($json)){
			return new self(self::CONSTANT, [(float) $json]);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid int provider");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		if($type === "constant"){
			return new self(self::CONSTANT, [(float) WorldgenData::int($json, "value")]);
		}
		$range = [(float) WorldgenData::int($json, "min_inclusive"), (float) WorldgenData::int($json, "max_inclusive")];
		return match($type){
			"uniform" => new self(self::UNIFORM, $range),
			"biased_to_bottom" => new self(self::BIASED_TO_BOTTOM, $range),
			"very_biased_to_bottom" => new self(self::VERY_BIASED_TO_BOTTOM, $range),
			default => throw new \RuntimeException("Unsupported int provider type \"$type\"")
		};
	}

	/**
	 * HeightProvider: a vertical anchor (constant) or {"type": "constant"|"uniform", ...}, resolved for the world's
	 * height range.
	 */
	public static function height(mixed $json, int $minY, int $height) : self{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid height provider");
		}
		if(!isset($json["type"])){
			return new self(self::CONSTANT, [(float) self::anchor($json, $minY, $height)]);
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		return match($type){
			"constant" => new self(self::CONSTANT, [(float) self::anchor($json["value"] ?? null, $minY, $height)]),
			"uniform" => new self(self::UNIFORM, [(float) self::anchor($json["min_inclusive"] ?? null, $minY, $height), (float) self::anchor($json["max_inclusive"] ?? null, $minY, $height)]),
			default => throw new \RuntimeException("Unsupported height provider type \"$type\"")
		};
	}

	/** VerticalAnchor: {"absolute": y} | {"above_bottom": n} | {"below_top": n} */
	public static function anchor(mixed $json, int $minY, int $height) : int{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid vertical anchor");
		}
		if(isset($json["absolute"])){
			return WorldgenData::int($json, "absolute");
		}
		if(isset($json["above_bottom"])){
			return $minY + WorldgenData::int($json, "above_bottom");
		}
		if(isset($json["below_top"])){
			return $minY + $height - 1 - WorldgenData::int($json, "below_top");
		}
		throw new \RuntimeException("Invalid vertical anchor");
	}

	/** FloatProvider.sample() */
	public function sampleFloat(LegacyRandom $random) : float{
		$p = $this->params;
		switch($this->type){
			case self::CONSTANT:
				return $p[0];
			case self::UNIFORM:
				//Mth.randomBetween(): nextFloat() * (max - min) + min
				return CarverMath::f(CarverMath::f($random->nextFloat() * CarverMath::f($p[1] - $p[0])) + $p[0]);
			case self::TRAPEZOID:
				$range = CarverMath::f($p[1] - $p[0]);
				$slope = CarverMath::f(CarverMath::f($range - $p[2]) / 2.0);
				$plateau = CarverMath::f($range - $slope);
				$v = CarverMath::f($p[0] + CarverMath::f($random->nextFloat() * $plateau));
				return CarverMath::f($v + CarverMath::f($random->nextFloat() * $slope));
		}
		throw new \LogicException("Not a float provider");
	}

	/** IntProvider.sample() / HeightProvider.sample() */
	public function sampleInt(LegacyRandom $random) : int{
		$p = $this->params;
		$min = (int) $p[0];
		if($this->type === self::CONSTANT){
			return $min;
		}
		$max = (int) $p[1];
		switch($this->type){
			case self::UNIFORM:
				//Mth.randomBetweenInclusive(); UniformHeight returns the minimum for an empty range
				return $min > $max ? $min : $min + $random->nextBoundedInt($max - $min + 1);
			case self::BIASED_TO_BOTTOM:
				return $min + $random->nextBoundedInt($random->nextBoundedInt($max - $min + 1) + 1);
			case self::VERY_BIASED_TO_BOTTOM:
				//the former hardcoded cave count: nextInt(nextInt(nextInt(n) + 1) + 1)
				$a = $random->nextBoundedInt($max - $min + 1);
				$b = $random->nextBoundedInt($a + 1);
				return $min + $random->nextBoundedInt($b + 1);
		}
		throw new \LogicException("Not an int provider");
	}
}
