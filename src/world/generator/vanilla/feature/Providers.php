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

use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Parsers of vanilla's value providers (IntProvider, FloatProvider, HeightProvider, VerticalAnchor) and weighted
 * lists, with the exact random call sequences of Java 26.3. Float providers work in float32 like Java.
 */
final class Providers{

	private function __construct(){
		//NOOP
	}

	public static function f(float $x) : float{
		return FloatMath::fround($x);
	}

	public static function int(mixed $json) : IntProvider{
		if(is_int($json)){
			return IntProvider::constant($json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid int provider");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "constant":
				return IntProvider::constant(WorldgenData::int($json, "value"));
			case "uniform":
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return new IntProvider(fn(FeatureRandom $r) : int => $r->nextBoundedInt($max - $min + 1) + $min, $min, $max);
			case "biased_to_bottom":
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return new IntProvider(fn(FeatureRandom $r) : int => $min + $r->nextBoundedInt($r->nextBoundedInt($max - $min + 1) + 1), $min, $max);
			case "very_biased_to_bottom":
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return new IntProvider(fn(FeatureRandom $r) : int => $min + $r->nextBoundedInt($r->nextBoundedInt($r->nextBoundedInt($max - $min + 1) + 1) + 1), $min, $max);
			case "clamped":
				$source = self::int($json["source"] ?? null);
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return new IntProvider(function(FeatureRandom $r) use ($source, $min, $max) : int{
					$v = $source->sample($r);
					return $v < $min ? $min : ($v > $max ? $max : $v);
				}, max($min, $source->min), min($max, $source->max));
			case "clamped_normal":
				$mean = self::f(WorldgenData::float($json, "mean"));
				$deviation = self::f(WorldgenData::float($json, "deviation"));
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return new IntProvider(function(FeatureRandom $r) use ($mean, $deviation, $min, $max) : int{
					$v = self::f($mean + self::f(self::f($r->nextGaussian()) * $deviation));
					return (int) ($v < $min ? $min : ($v > $max ? $max : $v));
				}, $min, $max);
			case "trapezoid":
				$min = WorldgenData::int($json, "min");
				$max = WorldgenData::int($json, "max");
				$plateau = WorldgenData::int($json, "plateau", 0);
				if($plateau === 0 && $max === -$min){
					return new IntProvider(fn(FeatureRandom $r) : int => $r->nextBoundedInt($max + 1) - $r->nextBoundedInt($max + 1), $min, $max);
				}
				$range = $max - $min;
				if($plateau === $range){
					return new IntProvider(fn(FeatureRandom $r) : int => $r->nextBoundedInt($max - $min + 1) + $min, $min, $max);
				}
				$plateauStart = intdiv($range - $plateau, 2);
				$plateauEnd = $range - $plateauStart;
				return new IntProvider(fn(FeatureRandom $r) : int => $min + $r->nextBoundedInt($plateauEnd + 1) + $r->nextBoundedInt($plateauStart + 1), $min, $max);
			case "weighted_list":
				[$items, $weights, $total] = self::weightedList($json["distribution"] ?? null, fn(mixed $data) : IntProvider => self::int($data));
				$lo = PHP_INT_MAX;
				$hi = PHP_INT_MIN;
				foreach($items as $item){
					$lo = min($lo, $item->min);
					$hi = max($hi, $item->max);
				}
				return new IntProvider(fn(FeatureRandom $r) : int => self::pick($r, $items, $weights, $total)->sample($r), $lo, $hi);
			default:
				throw new \RuntimeException("Unknown int provider type $type");
		}
	}

	/**
	 * @phpstan-return \Closure(FeatureRandom) : float
	 */
	public static function float(mixed $json) : \Closure{
		if(is_int($json) || is_float($json)){
			$v = self::f((float) $json);
			return fn(FeatureRandom $r) : float => $v;
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid float provider");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "constant":
				$v = self::f(WorldgenData::float($json, "value"));
				return fn(FeatureRandom $r) : float => $v;
			case "uniform":
				$min = self::f(WorldgenData::float($json, "min_inclusive"));
				$span = self::f(self::f(WorldgenData::float($json, "max_exclusive")) - $min);
				return fn(FeatureRandom $r) : float => self::f(self::f($r->nextFloat() * $span) + $min);
			case "clamped_normal":
				$mean = self::f(WorldgenData::float($json, "mean"));
				$deviation = self::f(WorldgenData::float($json, "deviation"));
				$min = self::f(WorldgenData::float($json, "min"));
				$max = self::f(WorldgenData::float($json, "max"));
				return function(FeatureRandom $r) use ($mean, $deviation, $min, $max) : float{
					$v = self::f($mean + self::f(self::f($r->nextGaussian()) * $deviation));
					return $v < $min ? $min : ($v > $max ? $max : $v);
				};
			case "trapezoid":
				$min = self::f(WorldgenData::float($json, "min"));
				$max = self::f(WorldgenData::float($json, "max"));
				$plateau = self::f(WorldgenData::float($json, "plateau", 0.0));
				$range = self::f($max - $min);
				$plateauStart = self::f(self::f($range - $plateau) / 2.0);
				$plateauEnd = self::f($range - $plateauStart);
				return fn(FeatureRandom $r) : float => self::f(self::f($min + self::f($r->nextFloat() * $plateauEnd)) + self::f($r->nextFloat() * $plateauStart));
			default:
				throw new \RuntimeException("Unknown float provider type $type");
		}
	}

	/**
	 * VerticalAnchor: {"absolute": y} | {"above_bottom": n} | {"below_top": n} | {"relative_to_sea_level": n}
	 * @phpstan-return \Closure(FeatureContext) : int
	 */
	public static function anchor(mixed $json) : \Closure{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid vertical anchor");
		}
		if(isset($json["absolute"])){
			$y = WorldgenData::int($json, "absolute");
			return fn(FeatureContext $c) : int => $y;
		}
		if(isset($json["above_bottom"])){
			$o = WorldgenData::int($json, "above_bottom");
			return fn(FeatureContext $c) : int => $c->minGenY + $o;
		}
		if(isset($json["below_top"])){
			$o = WorldgenData::int($json, "below_top");
			return fn(FeatureContext $c) : int => $c->genDepth - 1 + $c->minGenY - $o;
		}
		if(isset($json["relative_to_sea_level"])){
			$o = WorldgenData::int($json, "relative_to_sea_level");
			return fn(FeatureContext $c) : int => $c->seaLevel + $o;
		}
		throw new \RuntimeException("Invalid vertical anchor");
	}

	/**
	 * @phpstan-return \Closure(FeatureRandom, FeatureContext) : int
	 */
	public static function height(mixed $json) : \Closure{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid height provider");
		}
		if(!isset($json["type"])){
			$anchor = self::anchor($json);
			return fn(FeatureRandom $r, FeatureContext $c) : int => $anchor($c);
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "constant":
				$anchor = self::anchor($json["value"] ?? null);
				return fn(FeatureRandom $r, FeatureContext $c) : int => $anchor($c);
			case "uniform":
				$minA = self::anchor($json["min_inclusive"] ?? null);
				$maxA = self::anchor($json["max_inclusive"] ?? null);
				return function(FeatureRandom $r, FeatureContext $c) use ($minA, $maxA) : int{
					$min = $minA($c);
					$max = $maxA($c);
					return $min > $max ? $min : $r->nextBoundedInt($max - $min + 1) + $min;
				};
			case "trapezoid":
				$minA = self::anchor($json["min_inclusive"] ?? null);
				$maxA = self::anchor($json["max_inclusive"] ?? null);
				$plateau = WorldgenData::int($json, "plateau", 0);
				return function(FeatureRandom $r, FeatureContext $c) use ($minA, $maxA, $plateau) : int{
					$min = $minA($c);
					$max = $maxA($c);
					if($min > $max){
						return $min;
					}
					$range = $max - $min;
					if($plateau >= $range){
						return $r->nextBoundedInt($max - $min + 1) + $min;
					}
					$plateauStart = intdiv($range - $plateau, 2);
					$plateauEnd = $range - $plateauStart;
					return $min + $r->nextBoundedInt($plateauEnd + 1) + $r->nextBoundedInt($plateauStart + 1);
				};
			case "biased_to_bottom":
				$minA = self::anchor($json["min_inclusive"] ?? null);
				$maxA = self::anchor($json["max_inclusive"] ?? null);
				$inner = WorldgenData::int($json, "inner", 1);
				return function(FeatureRandom $r, FeatureContext $c) use ($minA, $maxA, $inner) : int{
					$min = $minA($c);
					$max = $maxA($c);
					if($max - $min - $inner + 1 <= 0){
						return $min;
					}
					$limit = $r->nextBoundedInt($max - $min - $inner + 1);
					return $r->nextBoundedInt($limit + $inner) + $min;
				};
			case "very_biased_to_bottom":
				$minA = self::anchor($json["min_inclusive"] ?? null);
				$maxA = self::anchor($json["max_inclusive"] ?? null);
				$inner = WorldgenData::int($json, "inner", 1);
				return function(FeatureRandom $r, FeatureContext $c) use ($minA, $maxA, $inner) : int{
					$min = $minA($c);
					$max = $maxA($c);
					if($max - $min - $inner + 1 <= 0){
						return $min;
					}
					$upper = $r->mthNextInt($min + $inner, $max);
					$biasedUpper = $r->mthNextInt($min, $upper - 1);
					return $r->mthNextInt($min, $biasedUpper - 1 + $inner);
				};
			case "weighted_list":
				[$items, $weights, $total] = self::weightedList($json["distribution"] ?? null, fn(mixed $data) : \Closure => self::height($data));
				return fn(FeatureRandom $r, FeatureContext $c) : int => (self::pick($r, $items, $weights, $total))($r, $c);
			default:
				throw new \RuntimeException("Unknown height provider type $type");
		}
	}

	/**
	 * Parses a weighted list ([{"data": ..., "weight": n}, ...]).
	 *
	 * @phpstan-template T
	 * @phpstan-param \Closure(mixed) : T $parse
	 * @phpstan-return array{list<T>, list<int>, int}
	 */
	public static function weightedList(mixed $json, \Closure $parse) : array{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid weighted list");
		}
		$items = [];
		$weights = [];
		$total = 0;
		foreach($json as $entry){
			if(!is_array($entry)){
				throw new \RuntimeException("Invalid weighted list entry");
			}
			$items[] = $parse($entry["data"] ?? null);
			$weight = WorldgenData::int($entry, "weight");
			$weights[] = $weight;
			$total += $weight;
		}
		return [$items, $weights, $total];
	}

	/**
	 * WeightedList.getRandomOrThrow().
	 *
	 * @phpstan-template T
	 * @phpstan-param list<T> $items
	 * @phpstan-param list<int> $weights
	 * @phpstan-return T
	 */
	public static function pick(FeatureRandom $random, array $items, array $weights, int $total) : mixed{
		if($total <= 0){
			throw new \RuntimeException("Weighted list has no elements");
		}
		$selection = $random->nextBoundedInt($total);
		foreach($weights as $i => $weight){
			$selection -= $weight;
			if($selection < 0){
				return $items[$i];
			}
		}
		throw new \LogicException("Selection exceeded total weight");
	}

	/**
	 * Parses a Vec3i ([x, y, z]), defaulting to 0 0 0.
	 * @phpstan-return array{int, int, int}
	 */
	public static function vec3(mixed $json) : array{
		if(is_array($json) && count($json) === 3 && is_int($json[0] ?? null) && is_int($json[1] ?? null) && is_int($json[2] ?? null)){
			return [$json[0], $json[1], $json[2]];
		}
		return [0, 0, 0];
	}

	public static function str(mixed $json) : string{
		return is_string($json) ? $json : throw new \RuntimeException("Expected a string");
	}
}
