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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_keys;
use function count;
use function is_array;
use function is_int;
use function is_string;

/**
 * Parsers of vanilla's block state providers. A provider returns the Java state for a position, or null for
 * getOptionalState() providers that give nothing (rule_based without fallback, empty random_block).
 *
 * @phpstan-type StateProvider \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int
 */
final class StateProviders{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int
	 */
	public static function parse(FeatureRegistry $registry, mixed $json) : \Closure{
		$blocks = $registry->blocks;
		if(is_string($json)){
			return $registry->stateProvider($json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid block state provider");
		}
		if(!isset($json["type"])){
			$state = $blocks->stateFromJson($json);
			return fn(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) : int => $state;
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "simple_state_provider":
				$state = $blocks->stateFromJson($json["state"] ?? null);
				return fn(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) : int => $state;
			case "weighted":
				[$items, $weights, $total] = Providers::weightedList($json["entries"] ?? null, fn(mixed $data) : int => $blocks->stateFromJson($data));
				return fn(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) : int => Providers::pick($r, $items, $weights, $total);
			case "randomized_int":
				$source = self::parse($registry, $json["source"] ?? null);
				$property = WorldgenData::string($json, "property");
				$values = Providers::int($json["values"] ?? null);
				return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($source, $property, $values, $blocks) : int{
					$state = $source($c, $r, $x, $y, $z) ?? $c->level->getState($x, $y, $z);
					if(!$blocks->hasProperty($state, $property)){
						return $state;
					}
					return $blocks->withInt($state, $property, $values->sample($r));
				};
			case "rotated":
				$source = self::parse($registry, $json["state"] ?? null);
				$fixed = is_string($json["direction"] ?? null) ? Direction::fromName($json["direction"]) : null;
				return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($source, $fixed, $blocks) : int{
					$direction = $fixed ?? $r->nextBoundedInt(6);
					$state = $source($c, $r, $x, $y, $z) ?? $c->level->getState($x, $y, $z);
					$state = $blocks->with($state, "axis", ["x", "y", "z"][Direction::AXIS[$direction]]);
					//FACING and HORIZONTAL_FACING are both called "facing"; values the block does not have are ignored
					$state = $blocks->with($state, "facing", Direction::NAMES[$direction]);
					return $state;
				};
			case "random_block":
				$list = array_keys($blocks->blockSet($json["blocks"] ?? null));
				$size = count($list);
				return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($list, $size, $blocks) : ?int{
					if($size === 0){
						return null;
					}
					return $blocks->defaultState[$list[$r->nextBoundedInt($size)]];
				};
			case "rule_based":
				$fallback = isset($json["fallback"]) ? self::parse($registry, $json["fallback"]) : null;
				$rules = [];
				foreach(WorldgenData::array($json, "rules") as $rule){
					if(!is_array($rule)){
						throw new \RuntimeException("Invalid rule");
					}
					$rules[] = [Predicates::block($blocks, $rule["if_true"] ?? null), self::parse($registry, $rule["then"] ?? null)];
				}
				return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($rules, $fallback) : ?int{
					foreach($rules as [$if, $then]){
						if($if($c, $x, $y, $z)){
							$state = $then($c, $r, $x, $y, $z);
							if($state !== null){
								return $state;
							}
						}
					}
					return $fallback !== null ? $fallback($c, $r, $x, $y, $z) : null;
				};
			case "noise":
			case "noise_threshold":
			case "dual_noise":
				return self::noiseProvider($registry, $type, $json);
			default:
				throw new \RuntimeException("Unknown block state provider type $type");
		}
	}

	/**
	 * @param mixed[] $json
	 * @phpstan-return \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int
	 */
	private static function noiseProvider(FeatureRegistry $registry, string $type, array $json) : \Closure{
		$blocks = $registry->blocks;
		$seed = WorldgenData::int($json, "seed");
		$scale = FloatMath::fround(WorldgenData::float($json, "scale"));
		$noise = new FloatNoise(self::noiseJson($json["noise"] ?? null), new LegacyRandom($seed));
		$noiseValue = fn(int $x, int $y, int $z) : float => $noise->get($x * $scale, $y * $scale, $z * $scale);
		$pickFromList = function(array $states, float $value) : int{
			$placement = FloatMath::fround(FloatMath::fround(1.0 + $value) / 2.0);
			$placement = $placement < 0.0 ? 0.0 : ($placement > 0.9998999834060669 ? 0.9998999834060669 : $placement);
			return $states[(int) FloatMath::fround($placement * count($states))];
		};
		if($type === "noise_threshold"){
			$threshold = FloatMath::fround(WorldgenData::float($json, "threshold"));
			$highChance = FloatMath::fround(WorldgenData::float($json, "high_chance"));
			$default = $blocks->stateFromJson($json["default_state"] ?? null);
			$low = [];
			foreach(WorldgenData::array($json, "low_states") as $s){
				$low[] = $blocks->stateFromJson($s);
			}
			$high = [];
			foreach(WorldgenData::array($json, "high_states") as $s){
				$high[] = $blocks->stateFromJson($s);
			}
			return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($noiseValue, $threshold, $highChance, $default, $low, $high) : int{
				$value = $noiseValue($x, $y, $z);
				if($value < $threshold){
					return $low[$r->nextBoundedInt(count($low))];
				}
				if($r->nextFloat() < $highChance){
					return $high[$r->nextBoundedInt(count($high))];
				}
				return $default;
			};
		}
		$states = [];
		foreach(WorldgenData::array($json, "states") as $s){
			$states[] = $blocks->stateFromJson($s);
		}
		if($type === "noise"){
			return fn(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) : int => $pickFromList($states, $noiseValue($x, $y, $z));
		}
		//dual_noise
		$variety = WorldgenData::array($json, "variety");
		$varietyMin = is_int($variety[0] ?? null) ? $variety[0] : 1;
		$varietyMax = is_int($variety[1] ?? null) ? $variety[1] : $varietyMin;
		$slowScale = FloatMath::fround(WorldgenData::float($json, "slow_scale"));
		$slow = new FloatNoise(self::noiseJson($json["slow_noise"] ?? null), new LegacyRandom($seed));
		$slowValue = fn(int $x, int $y, int $z) : float => $slow->get(
			FloatMath::fround($x * $slowScale), FloatMath::fround($y * $slowScale), FloatMath::fround($z * $slowScale)
		);
		return function(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) use ($states, $slowValue, $varietyMin, $varietyMax, $pickFromList, $noiseValue) : int{
			$varietyNoise = $slowValue($x, $y, $z);
			//Mth.clampedMap(varietyNoise, -1, 1, min, max + 1)
			$t = ($varietyNoise - -1.0) / (1.0 - -1.0);
			$mapped = $t < 0.0 ? (float) $varietyMin : ($t > 1.0 ? (float) ($varietyMax + 1) : $varietyMin + $t * (($varietyMax + 1) - $varietyMin));
			$localVariety = (int) $mapped;
			$possible = [];
			for($i = 0; $i < $localVariety; ++$i){
				$possible[] = $pickFromList($states, $slowValue($x + $i * 54545, $y, $z + $i * 34234));
			}
			return $pickFromList($possible, $noiseValue($x, $y, $z));
		};
	}

	/**
	 * @return mixed[]
	 */
	private static function noiseJson(mixed $json) : array{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid noise parameters");
		}
		return $json;
	}
}
