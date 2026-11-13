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
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_array;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Parsers of vanilla's block predicates (worldgen) and rule tests (ore targets, structure processors).
 *
 * @phpstan-type BlockPredicate \Closure(FeatureContext, int, int, int) : bool
 * @phpstan-type RuleTest \Closure(int, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource, int) : bool
 */
final class Predicates{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return \Closure(FeatureContext, int, int, int) : bool
	 */
	public static function block(JavaBlocks $blocks, mixed $json) : \Closure{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid block predicate");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		[$ox, $oy, $oz] = Providers::vec3($json["offset"] ?? null);
		switch($type){
			case "true":
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => true;
			case "not":
				$p = self::block($blocks, $json["predicate"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => !$p($c, $x, $y, $z);
			case "all_of":
				$list = [];
				foreach(WorldgenData::array($json, "predicates") as $sub){
					$list[] = self::block($blocks, $sub);
				}
				return function(FeatureContext $c, int $x, int $y, int $z) use ($list) : bool{
					foreach($list as $p){
						if(!$p($c, $x, $y, $z)){
							return false;
						}
					}
					return true;
				};
			case "any_of":
				$list = [];
				foreach(WorldgenData::array($json, "predicates") as $sub){
					$list[] = self::block($blocks, $sub);
				}
				return function(FeatureContext $c, int $x, int $y, int $z) use ($list) : bool{
					foreach($list as $p){
						if($p($c, $x, $y, $z)){
							return true;
						}
					}
					return false;
				};
			case "matching_blocks":
				$set = $blocks->blockSet($json["blocks"] ?? null);
				$of = $blocks->blockOf;
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => isset($set[$of[$c->level->getState($x + $ox, $y + $oy, $z + $oz)]]);
			case "matching_block_tag":
				$set = $blocks->tag(WorldgenData::string($json, "tag"));
				$of = $blocks->blockOf;
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => isset($set[$of[$c->level->getState($x + $ox, $y + $oy, $z + $oz)]]);
			case "matching_fluids":
				$fluids = self::fluidSet($json["fluids"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => self::fluidMatches($blocks, $c->level->getState($x + $ox, $y + $oy, $z + $oz), $fluids);
			case "solid":
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => $blocks->isSolid($c->level->getState($x + $ox, $y + $oy, $z + $oz));
			case "replaceable":
				$set = $blocks->tag("minecraft:replaceable");
				$of = $blocks->blockOf;
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => isset($set[$of[$c->level->getState($x + $ox, $y + $oy, $z + $oz)]]);
			case "would_survive":
				$state = $blocks->stateFromJson($json["state"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => $c->survival->canSurvive($c->level, $state, $x + $ox, $y + $oy, $z + $oz);
			case "has_sturdy_face":
				$direction = Direction::fromName(WorldgenData::string($json, "direction"));
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => $blocks->isFaceSturdy($c->level->getState($x + $ox, $y + $oy, $z + $oz), $direction);
			case "inside_world_bounds":
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => $c->level->isInsideBuildHeight($y + $oy);
			case "height_range":
				$min = Providers::anchor($json["min_inclusive"] ?? null);
				$max = Providers::anchor($json["max_inclusive"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => $y >= $min($c) && $y <= $max($c);
			case "volume_match":
				[$x0, $y0, $z0] = Providers::vec3($json["min"] ?? null);
				[$x1, $y1, $z1] = Providers::vec3($json["max"] ?? null);
				$match = self::block($blocks, $json["match"] ?? null);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($x0, $y0, $z0, $x1, $y1, $z1, $match) : bool{
					for($ox = $x0; $ox <= $x1; ++$ox){
						for($oz = $z0; $oz <= $z1; ++$oz){
							for($oy = $y0; $oy <= $y1; ++$oy){
								if(!$match($c, $x + $ox, $y + $oy, $z + $oz)){
									return false;
								}
							}
						}
					}
					return true;
				};
			case "unobstructed":
				return fn(FeatureContext $c, int $x, int $y, int $z) : bool => true; //no entities during worldgen
			case "matching_biomes":
				$names = $json["biomes"] ?? null;
				return function(FeatureContext $c, int $x, int $y, int $z) use ($names) : bool{
					return $c->registry->biomeInSet($c->level->getBiome($x, $y, $z), $names);
				};
			default:
				throw new \RuntimeException("Unknown block predicate type $type");
		}
	}

	/**
	 * Fluid holder set: "minecraft:water" (source only), "minecraft:flowing_water", "#minecraft:water" (both)...
	 * @return int[] list of fluid codes (kind | 16 if source)
	 * @phpstan-return array<int, true>
	 */
	public static function fluidSet(mixed $json) : array{
		$set = [];
		$values = is_array($json) ? $json : [$json];
		foreach($values as $value){
			if(!is_string($value)){
				continue;
			}
			$name = WorldgenData::stripNamespace(str_starts_with($value, "#") ? substr($value, 1) : $value);
			$tag = str_starts_with($value, "#");
			switch($name){
				case "water":
					$set[JavaBlocks::FLUID_WATER | 16] = true;
					if($tag){
						$set[JavaBlocks::FLUID_WATER] = true;
					}
					break;
				case "flowing_water":
					$set[JavaBlocks::FLUID_WATER] = true;
					break;
				case "lava":
					$set[JavaBlocks::FLUID_LAVA | 16] = true;
					if($tag){
						$set[JavaBlocks::FLUID_LAVA] = true;
					}
					break;
				case "flowing_lava":
					$set[JavaBlocks::FLUID_LAVA] = true;
					break;
				case "empty":
					$set[JavaBlocks::FLUID_NONE] = true;
					break;
			}
		}
		return $set;
	}

	/**
	 * @param true[] $fluids
	 * @phpstan-param array<int, true> $fluids
	 */
	public static function fluidMatches(JavaBlocks $blocks, int $state, array $fluids) : bool{
		$fluid = $blocks->fluid($state);
		//flowing water of level 0 does not exist; FLUID_NONE never has the source bit
		return isset($fluids[$fluid]);
	}

	/**
	 * Rule tests (structure processors and ore targets): test(state, random, y).
	 * @phpstan-return \Closure(int, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource, int) : bool
	 */
	public static function rule(JavaBlocks $blocks, mixed $json) : \Closure{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid rule test");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "predicate_type"));
		switch($type){
			case "always_true":
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => true;
			case "block_match":
				$block = $blocks->blockIndex(WorldgenData::string($json, "block"));
				$of = $blocks->blockOf;
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => $of[$state] === $block;
			case "blockstate_match":
				$target = $blocks->stateFromJson($json["block_state"] ?? null);
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => $state === $target;
			case "tag_match":
				$set = $blocks->tag(WorldgenData::string($json, "tag"));
				$of = $blocks->blockOf;
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => isset($set[$of[$state]]);
			case "random_block_match":
				$block = $blocks->blockIndex(WorldgenData::string($json, "block"));
				$probability = Providers::f(WorldgenData::float($json, "probability"));
				$of = $blocks->blockOf;
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => $of[$state] === $block && $r->nextFloat() < $probability;
			case "random_blockstate_match":
				$target = $blocks->stateFromJson($json["block_state"] ?? null);
				$probability = Providers::f(WorldgenData::float($json, "probability"));
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => $state === $target && $r->nextFloat() < $probability;
			case "height_match":
				$min = WorldgenData::int($json, "min_inclusive");
				$max = WorldgenData::int($json, "max_inclusive");
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => $y >= $min && $y <= $max;
			case "not":
				$rule = self::rule($blocks, $json["rule"] ?? null);
				return fn(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) : bool => !$rule($state, $r, $y);
			case "all_of":
				$list = [];
				foreach(WorldgenData::array($json, "rules") as $sub){
					$list[] = self::rule($blocks, $sub);
				}
				return function(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) use ($list) : bool{
					foreach($list as $rule){
						if(!$rule($state, $r, $y)){
							return false;
						}
					}
					return true;
				};
			case "any_of":
				$list = [];
				foreach(WorldgenData::array($json, "rules") as $sub){
					$list[] = self::rule($blocks, $sub);
				}
				return function(int $state, FeatureRandom|\pocketmine\world\generator\vanilla\random\RandomSource $r, int $y) use ($list) : bool{
					foreach($list as $rule){
						if($rule($state, $r, $y)){
							return true;
						}
					}
					return false;
				};
			default:
				throw new \RuntimeException("Unknown rule test type $type");
		}
	}
}
