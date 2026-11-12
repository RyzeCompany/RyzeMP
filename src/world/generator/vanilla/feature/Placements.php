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
use pocketmine\world\generator\vanilla\WorldgenData;
use function ceil;
use function count;
use function is_array;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Parsers of vanilla's placement modifiers (Java 26.3). Each modifier maps a position to the list of positions it
 * produces, consuming the random exactly like vanilla.
 *
 * @phpstan-type Modifier \Closure(FeatureContext, int, int, int) : list<array{int, int, int}>
 */
final class Placements{

	private function __construct(){
		//NOOP
	}

	public static function heightmapType(string $name) : int{
		return match($name){
			"WORLD_SURFACE_WG" => FeatureLevel::WORLD_SURFACE_WG,
			"WORLD_SURFACE" => FeatureLevel::WORLD_SURFACE,
			"OCEAN_FLOOR_WG" => FeatureLevel::OCEAN_FLOOR_WG,
			"OCEAN_FLOOR" => FeatureLevel::OCEAN_FLOOR,
			"MOTION_BLOCKING" => FeatureLevel::MOTION_BLOCKING,
			"MOTION_BLOCKING_NO_LEAVES" => FeatureLevel::MOTION_BLOCKING_NO_LEAVES,
			default => throw new \RuntimeException("Unknown heightmap $name")
		};
	}

	/**
	 * @phpstan-return \Closure(FeatureContext, int, int, int) : list<array{int, int, int}>
	 */
	public static function parse(FeatureRegistry $registry, mixed $json) : \Closure{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid placement modifier");
		}
		$blocks = $registry->blocks;
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "count":
				$count = Providers::int($json["count"] ?? null);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($count) : array{
					$n = $count->sample($c->random);
					$out = [];
					for($i = 0; $i < $n; ++$i){
						$out[] = [$x, $y, $z];
					}
					return $out;
				};
			case "in_square":
				return function(FeatureContext $c, int $x, int $y, int $z) : array{
					$px = $c->random->nextBoundedInt(16) + $x;
					$pz = $c->random->nextBoundedInt(16) + $z;
					return [[$px, $y, $pz]];
				};
			case "rarity_filter":
				$chance = FloatMath::fround(1.0 / WorldgenData::int($json, "chance"));
				return fn(FeatureContext $c, int $x, int $y, int $z) : array => $c->random->nextFloat() < $chance ? [[$x, $y, $z]] : [];
			case "random_chance":
				$chance = FloatMath::fround(WorldgenData::float($json, "chance"));
				return fn(FeatureContext $c, int $x, int $y, int $z) : array => $c->random->nextFloat() < $chance ? [[$x, $y, $z]] : [];
			case "height_range":
				$height = Providers::height($json["height"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : array => [[$x, $height($c->random, $c), $z]];
			case "heightmap":
				$heightmap = self::heightmapType(WorldgenData::string($json, "heightmap"));
				return function(FeatureContext $c, int $x, int $y, int $z) use ($heightmap) : array{
					$h = $c->level->getHeight($heightmap, $x, $z);
					return $h > $c->level->minY ? [[$x, $h, $z]] : [];
				};
			case "biome":
				return function(FeatureContext $c, int $x, int $y, int $z) : array{
					$top = $c->topFeature ?? throw new \RuntimeException("Tried to biome check an unregistered feature, or a feature that should not restrict the biome");
					return $c->registry->biomeHasFeature($c->level->getBiome($x, $y, $z), $top->id) ? [[$x, $y, $z]] : [];
				};
			case "block_predicate_filter":
				$predicate = Predicates::block($blocks, $json["predicate"] ?? null);
				return fn(FeatureContext $c, int $x, int $y, int $z) : array => $predicate($c, $x, $y, $z) ? [[$x, $y, $z]] : [];
			case "surface_water_depth_filter":
				$maxDepth = WorldgenData::int($json, "max_water_depth");
				return function(FeatureContext $c, int $x, int $y, int $z) use ($maxDepth) : array{
					$floor = $c->level->getHeight(FeatureLevel::OCEAN_FLOOR, $x, $z);
					$surface = $c->level->getHeight(FeatureLevel::WORLD_SURFACE, $x, $z);
					return $surface - $floor <= $maxDepth ? [[$x, $y, $z]] : [];
				};
			case "surface_relative_threshold_filter":
				$heightmap = self::heightmapType(WorldgenData::string($json, "heightmap"));
				$min = WorldgenData::int($json, "min_inclusive", PHP_INT_MIN >> 1);
				$max = WorldgenData::int($json, "max_inclusive", PHP_INT_MAX >> 1);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($heightmap, $min, $max) : array{
					$surface = $c->level->getHeight($heightmap, $x, $z);
					return $surface + $min <= $y && $y <= $surface + $max ? [[$x, $y, $z]] : [];
				};
			case "count_on_every_layer":
				$count = Providers::int($json["count"] ?? null);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($count) : array{
					$out = [];
					$layer = 0;
					do{
						$found = false;
						for($i = 0, $n = $count->sample($c->random); $i < $n; ++$i, $n = $count->sample($c->random)){
							$px = $c->random->nextBoundedInt(16) + $x;
							$pz = $c->random->nextBoundedInt(16) + $z;
							$startY = $c->level->getHeight(FeatureLevel::MOTION_BLOCKING, $px, $pz);
							$py = self::findOnGroundYPosition($c, $px, $startY, $pz, $layer);
							if($py !== PHP_INT_MAX){
								$out[] = [$px, $py, $pz];
								$found = true;
							}
						}
						++$layer;
					}while($found);
					return $out;
				};
			case "environment_scan":
				$direction = Direction::fromName(WorldgenData::string($json, "direction_of_search"));
				$target = Predicates::block($blocks, $json["target_condition"] ?? null);
				$allowed = isset($json["allowed_search_condition"]) ? Predicates::block($blocks, $json["allowed_search_condition"]) : fn(FeatureContext $c, int $x, int $y, int $z) : bool => true;
				$maxSteps = WorldgenData::int($json, "max_steps");
				$dx = Direction::STEP_X[$direction];
				$dy = Direction::STEP_Y[$direction];
				$dz = Direction::STEP_Z[$direction];
				return function(FeatureContext $c, int $x, int $y, int $z) use ($target, $allowed, $maxSteps, $dx, $dy, $dz) : array{
					if(!$allowed($c, $x, $y, $z)){
						return [];
					}
					for($i = 0; $i < $maxSteps; ++$i){
						if($target($c, $x, $y, $z)){
							return [[$x, $y, $z]];
						}
						$x += $dx;
						$y += $dy;
						$z += $dz;
						if($c->level->isOutsideBuildHeight($y)){
							return [];
						}
						if(!$allowed($c, $x, $y, $z)){
							break;
						}
					}
					return $target($c, $x, $y, $z) ? [[$x, $y, $z]] : [];
				};
			case "offset":
				$ox = Providers::int($json["x"] ?? 0);
				$oy = Providers::int($json["y"] ?? 0);
				$oz = Providers::int($json["z"] ?? 0);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($ox, $oy, $oz) : array{
					$px = $ox->sample($c->random);
					$py = $oy->sample($c->random);
					$pz = $oz->sample($c->random);
					return [[$x + $px, $y + $py, $z + $pz]];
				};
			case "randomly_selected":
				$list = [];
				foreach(WorldgenData::array($json, "placements") as $sub){
					$list[] = self::parse($registry, $sub);
				}
				$size = count($list);
				return fn(FeatureContext $c, int $x, int $y, int $z) : array => ($list[$c->random->nextBoundedInt($size)])($c, $x, $y, $z);
			case "noise_based_count":
				$ratio = WorldgenData::int($json, "noise_to_count_ratio");
				$factor = WorldgenData::float($json, "noise_factor");
				$offset = WorldgenData::float($json, "noise_offset", 0.0);
				return function(FeatureContext $c, int $x, int $y, int $z) use ($ratio, $factor, $offset) : array{
					$noise = $c->registry->biomeInfoNoise($x / $factor, $z / $factor);
					$n = (int) ceil(($noise + $offset) * $ratio);
					$out = [];
					for($i = 0; $i < $n; ++$i){
						$out[] = [$x, $y, $z];
					}
					return $out;
				};
			case "noise_threshold_count":
				$level = WorldgenData::float($json, "noise_level");
				$below = WorldgenData::int($json, "below_noise");
				$above = WorldgenData::int($json, "above_noise");
				return function(FeatureContext $c, int $x, int $y, int $z) use ($level, $below, $above) : array{
					$noise = $c->registry->biomeInfoNoise($x / 200.0, $z / 200.0);
					$n = $noise < $level ? $below : $above;
					$out = [];
					for($i = 0; $i < $n; ++$i){
						$out[] = [$x, $y, $z];
					}
					return $out;
				};
			case "cuboid":
				$xzSize = Providers::int($json["xz_size"] ?? null);
				$ySize = Providers::int($json["y_size"] ?? null);
				$includeEdges = ($json["include_edges"] ?? true) === true;
				$includeInterior = ($json["include_interior"] ?? true) === true;
				return function(FeatureContext $c, int $x, int $y, int $z) use ($xzSize, $ySize, $includeEdges, $includeInterior) : array{
					$height = $ySize->sample($c->random);
					$width = $xzSize->sample($c->random);
					$length = $xzSize->sample($c->random);
					$out = [];
					for($dx = 0; $dx <= $width; ++$dx){
						$xEdge = $dx === 0 || $dx === $width;
						for($dy = 0; $dy <= $height; ++$dy){
							$yEdge = $dy === 0 || $dy === $height;
							for($dz = 0; $dz <= $length; ++$dz){
								$zEdge = $dz === 0 || $dz === $length;
								if(!$includeEdges && (($xEdge && $yEdge) || ($zEdge && $yEdge) || ($xEdge && $zEdge))){
									continue;
								}
								if(!$includeInterior && !$xEdge && !$yEdge && !$zEdge){
									continue;
								}
								$out[] = [$x + $dx, $y + $dy, $z + $dz];
							}
						}
					}
					return $out;
				};
			case "fixed_placement":
				$positions = [];
				foreach(WorldgenData::array($json, "positions") as $p){
					$positions[] = Providers::vec3($p);
				}
				return function(FeatureContext $c, int $x, int $y, int $z) use ($positions) : array{
					$out = [];
					foreach($positions as [$px, $py, $pz]){
						if(($px >> 4) === ($x >> 4) && ($pz >> 4) === ($z >> 4)){
							$out[] = [$px, $py, $pz];
						}
					}
					return $out;
				};
			default:
				throw new \RuntimeException("Unknown placement modifier type $type");
		}
	}

	/**
	 * CountOnEveryLayerPlacement.findOnGroundYPosition()
	 */
	private static function findOnGroundYPosition(FeatureContext $c, int $x, int $startY, int $z, int $layerToPlaceOn) : int{
		$level = $c->level;
		$blocks = $c->blocks;
		$current = $level->getState($x, $startY, $z);
		$currentLayer = 0;
		for($y = $startY; $y >= $level->minY + 1; --$y){
			$below = $level->getState($x, $y - 1, $z);
			if(!self::isEmpty($c, $below) && self::isEmpty($c, $current) && $blocks->blockName($below) !== "minecraft:bedrock"){
				if($currentLayer === $layerToPlaceOn){
					return $y;
				}
				++$currentLayer;
			}
			$current = $below;
		}
		return PHP_INT_MAX;
	}

	private static function isEmpty(FeatureContext $c, int $state) : bool{
		$block = $c->blocks->blockOf[$state];
		return $c->blocks->isAir($state) || $block === $c->blocks->waterBlock || $block === $c->blocks->lavaBlock;
	}
}
