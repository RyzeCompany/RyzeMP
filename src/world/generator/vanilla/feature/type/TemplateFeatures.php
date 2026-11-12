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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\feature\template\StructureTemplate;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_search;
use function count;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function min;

/**
 * minecraft:template and minecraft:fossil: structure templates placed by features, with the structure processors
 * they use (block_rot, protected_blocks, rule).
 */
final class TemplateFeatures{

	private function __construct(){
		//NOOP
	}

	/**
	 * Parses a processor list (an id of worldgen/processor_list, {"processors": [...]} or a plain list).
	 * @phpstan-return list<\Closure(FeatureContext, int, int, int, int, ?CompoundTag=) : ?int>
	 */
	public static function processors(FeatureRegistry $registry, mixed $json) : array{
		if(is_string($json)){
			$json = $registry->data->read("data/minecraft/worldgen/processor_list/" . WorldgenData::stripNamespace($json) . ".json");
		}
		if(is_array($json) && isset($json["processors"])){
			$json = $json["processors"];
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid processor list");
		}
		$blocks = $registry->blocks;
		$list = [];
		foreach($json as $processor){
			if(!is_array($processor)){
				throw new \RuntimeException("Invalid structure processor");
			}
			$type = WorldgenData::stripNamespace(WorldgenData::string($processor, "processor_type"));
			switch($type){
				case "block_rot":
					$integrity = FloatMath::fround(WorldgenData::float($processor, "integrity"));
					$rottable = isset($processor["rottable_blocks"]) ? $blocks->blockSet($processor["rottable_blocks"]) : null;
					$list[] = function(FeatureContext $c, int $x, int $y, int $z, int $state) use ($integrity, $rottable, $blocks) : ?int{
						if($rottable !== null && !isset($rottable[$blocks->blockOf[$state]])){
							return $state;
						}
						return $c->random->nextFloat() <= $integrity ? $state : null;
					};
					break;
				case "protected_blocks":
					$protected = $blocks->blockSet($processor["value"] ?? null);
					$list[] = fn(FeatureContext $c, int $x, int $y, int $z, int $state) : ?int => isset($protected[$blocks->blockOf[$c->level->getState($x, $y, $z)]]) ? null : $state;
					break;
				case "rule":
					$rules = [];
					foreach(WorldgenData::array($processor, "rules") as $rule){
						if(!is_array($rule)){
							throw new \RuntimeException("Invalid processor rule");
						}
						$posPredicate = WorldgenData::stripNamespace(is_array($rule["position_predicate"] ?? null) && is_string($rule["position_predicate"]["predicate_type"] ?? null) ? $rule["position_predicate"]["predicate_type"] : "always_true");
						if($posPredicate !== "always_true"){
							$registry->warn("processor_rule_position:$posPredicate", "Unsupported processor rule position predicate $posPredicate");
						}
						$rules[] = [
							Predicates::rule($blocks, $rule["input_predicate"] ?? null),
							Predicates::rule($blocks, $rule["location_predicate"] ?? null),
							$blocks->stateFromJson($rule["output_state"] ?? null),
							self::blockEntityModifier($registry, $rule["block_entity_modifier"] ?? null)
						];
					}
					$list[] = function(FeatureContext $c, int $x, int $y, int $z, int $state, ?CompoundTag &$nbt = null) use ($rules) : int{
						$random = new LegacyRandom(Int64::positionSeed($x, $y, $z));
						$location = $c->level->getState($x, $y, $z);
						foreach($rules as [$input, $locationTest, $output, $modifier]){
							if($input($state, $random, $y) && $locationTest($location, $random, $y)){
								if($modifier !== null){
									$nbt = $modifier($random, $nbt);
								}
								return $output;
							}
						}
						return $state;
					};
					break;
				default:
					$registry->warn("processor:$type", "Unsupported structure processor $type");
			}
		}
		return $list;
	}

	/**
	 * A rule's block_entity_modifier (RuleBlockEntityModifier): returns fn(random, nbt) : ?nbt, or null for
	 * passthrough (the default).
	 * @phpstan-return (\Closure(LegacyRandom, ?CompoundTag) : CompoundTag)|null
	 */
	private static function blockEntityModifier(FeatureRegistry $registry, mixed $json) : ?\Closure{
		if(!is_array($json)){
			return null;
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "passthrough":
				return null;
			case "clear":
				return fn(LegacyRandom $random, ?CompoundTag $nbt) : CompoundTag => CompoundTag::create();
			case "append_loot":
				$table = JavaBlocks::namespaced(WorldgenData::string($json, "loot_table"));
				return fn(LegacyRandom $random, ?CompoundTag $nbt) : CompoundTag => ($nbt !== null ? clone $nbt : CompoundTag::create())
					->setString("LootTable", $table)
					->setLong("LootTableSeed", $random->nextLong());
			default:
				$registry->warn("block_entity_modifier:$type", "Unsupported block entity modifier $type (ignored)");
				return null;
		}
	}

	/**
	 * StructureTemplate.placeInWorld() with the settings' random being the feature random, no mirror, and the
	 * rotation pivot at the origin. Block entities get the loot tables of their data (containers, brushable blocks);
	 * entities and neighbour shape updates are not handled.
	 *
	 * @phpstan-param list<\Closure(FeatureContext, int, int, int, int, ?CompoundTag=) : ?int> $processors
	 * @phpstan-param array{int, int, int, int}|null $box minX, minZ, maxX, maxZ
	 */
	public static function placeInWorld(FeatureContext $c, StructureTemplate $template, int $x, int $y, int $z, int $rotation, array $processors, ?array $box) : bool{
		if(count($template->palettes) === 0){
			return false;
		}
		$palette = $template->palettes[$c->random->nextBoundedInt(count($template->palettes))];
		if(count($palette) === 0 || $template->sizeX < 1 || $template->sizeY < 1 || $template->sizeZ < 1){
			return false;
		}
		$level = $c->level;
		$blocks = $c->blocks;
		$processed = [];
		foreach($palette as [$rx, $ry, $rz, $state, $nbt]){
			[$tx, $tz] = StructureTemplate::transform($rx, $rz, $rotation);
			$wx = $tx + $x;
			$wy = $ry + $y;
			$wz = $tz + $z;
			if($box !== null && ($wx < $box[0] || $wz < $box[1] || $wx > $box[2] || $wz > $box[3])){
				continue;
			}
			$nbt = $nbt !== null ? clone $nbt : null;
			foreach($processors as $processor){
				$state = $processor($c, $wx, $wy, $wz, $state, $nbt);
				if($state === null){
					continue 2;
				}
			}
			$processed[] = [$wx, $wy, $wz, $state, $nbt];
		}
		/** @phpstan-var list<array{int, int, int}> $toFill */
		$toFill = [];
		$locked = [];
		foreach($processed as [$wx, $wy, $wz, $state, $nbt]){
			$previous = $blocks->fluid($level->getState($wx, $wy, $wz));
			$state = $template->rotateState($blocks, $state, $rotation);
			if(!$level->setState($wx, $wy, $wz, $state)){
				continue;
			}
			if($nbt !== null){
				$block = $blocks->blockName($state);
				if(BlockEntityData::isRandomizableContainer($block)){
					$nbt->setLong("LootTableSeed", $c->random->nextLong());
				}
				$tile = BlockEntityData::fromJava($block, $nbt);
				if($tile !== null){
					$level->setBlockEntity($wx, $wy, $wz, $tile);
				}
			}
			if($previous === JavaBlocks::FLUID_NONE){
				continue;
			}
			if($blocks->fluidIsSource($state)){
				$locked["$wx:$wy:$wz"] = true;
			}elseif($blocks->hasProperty($state, "waterlogged")){
				//SimpleWaterloggedBlock.placeLiquid(): only water sources waterlog
				if(($previous & 15) === JavaBlocks::FLUID_WATER && ($previous & 16) !== 0){
					$level->setState($wx, $wy, $wz, $blocks->withBool($state, "waterlogged", true));
				}else{
					$toFill[] = [$wx, $wy, $wz];
				}
			}
		}
		$directions = [Direction::UP, Direction::NORTH, Direction::EAST, Direction::SOUTH, Direction::WEST];
		$filled = true;
		while($filled && count($toFill) > 0){
			$filled = false;
			foreach($toFill as $i => [$fx, $fy, $fz]){
				$source = false;
				foreach($directions as $d){
					$nx = $fx + Direction::STEP_X[$d];
					$ny = $fy + Direction::STEP_Y[$d];
					$nz = $fz + Direction::STEP_Z[$d];
					$neighbour = $level->getState($nx, $ny, $nz);
					if($blocks->isWater($neighbour) && $blocks->fluidIsSource($neighbour) && !isset($locked["$nx:$ny:$nz"])){
						$source = true;
						break;
					}
				}
				if($source){
					$state = $level->getState($fx, $fy, $fz);
					if($blocks->hasProperty($state, "waterlogged")){
						$level->setState($fx, $fy, $fz, $blocks->withBool($state, "waterlogged", true));
						$filled = true;
						unset($toFill[$i]);
					}
				}
			}
		}
		return true;
	}

	/**
	 * Rotation.rotate(direction).getUnitVec3i() * length
	 * @phpstan-return array{int, int}
	 */
	public static function rotatedOffset(int $direction, int $rotation, int $length) : array{
		$d = StructureTemplate::rotateDirection($direction, $rotation);
		return [Direction::STEP_X[$d] * $length, Direction::STEP_Z[$d] * $length];
	}

	/**
	 * @param mixed[] $json
	 */
	public static function template(FeatureRegistry $registry, array $json) : Feature{
		[$entries, $weights, $total] = Providers::weightedList($json["templates"] ?? null, function(mixed $data) : array{
			if(!is_array($data)){
				throw new \RuntimeException("Invalid template entry");
			}
			$rotations = [];
			foreach(is_array($data["rotations"] ?? null) ? $data["rotations"] : StructureTemplate::ROTATION_NAMES as $name){
				$index = array_search($name, StructureTemplate::ROTATION_NAMES, true);
				if($index === false){
					throw new \RuntimeException("Invalid template rotation");
				}
				$rotations[] = $index;
			}
			return [WorldgenData::string($data, "id"), $rotations];
		});
		$processors = isset($json["processors"]) ? self::processors($registry, $json["processors"]) : [];
		return new class($registry, $entries, $weights, $total, $processors) implements Feature{
			/**
			 * @phpstan-param list<array{string, list<int>}> $entries
			 * @phpstan-param list<int> $weights
			 * @phpstan-param list<\Closure(FeatureContext, int, int, int, int, ?CompoundTag=) : ?int> $processors
			 */
			public function __construct(
				private FeatureRegistry $registry,
				private array $entries,
				private array $weights,
				private int $total,
				private array $processors
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				[$id, $rotations] = Providers::pick($random, $this->entries, $this->weights, $this->total);
				$rotation = $rotations[$random->nextBoundedInt(count($rotations))];
				$template = StructureTemplate::load($this->registry->data, $this->registry->blocks, $id);
				[$ox, $oz] = TemplateFeatures::rotatedOffset(Direction::WEST, $rotation, intdiv($template->sizeX, 2));
				[$px, $pz] = TemplateFeatures::rotatedOffset(Direction::NORTH, $rotation, intdiv($template->sizeZ, 2));
				return TemplateFeatures::placeInWorld($c, $template, $x + $ox + $px, $y, $z + $oz + $pz, $rotation, $this->processors, null);
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function fossil(FeatureRegistry $registry, array $json) : Feature{
		$fossils = [];
		foreach(WorldgenData::array($json, "fossil_structures") as $id){
			$fossils[] = Providers::str($id);
		}
		$overlays = [];
		foreach(WorldgenData::array($json, "overlay_structures") as $id){
			$overlays[] = Providers::str($id);
		}
		if(count($fossils) !== count($overlays) || count($fossils) === 0){
			throw new \RuntimeException("Fossil structure lists must be non-empty and of equal lengths");
		}
		return new class(
			$registry,
			$fossils,
			$overlays,
			self::processors($registry, $json["fossil_processors"] ?? null),
			self::processors($registry, $json["overlay_processors"] ?? null),
			WorldgenData::int($json, "max_empty_corners_allowed")
		) implements Feature{
			/**
			 * @phpstan-param list<string> $fossils
			 * @phpstan-param list<string> $overlays
			 * @phpstan-param list<\Closure(FeatureContext, int, int, int, int, ?CompoundTag=) : ?int> $fossilProcessors
			 * @phpstan-param list<\Closure(FeatureContext, int, int, int, int, ?CompoundTag=) : ?int> $overlayProcessors
			 */
			public function __construct(
				private FeatureRegistry $registry,
				private array $fossils,
				private array $overlays,
				private array $fossilProcessors,
				private array $overlayProcessors,
				private int $maxEmptyCorners
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				$level = $c->level;
				$blocks = $c->blocks;
				$rotation = $random->nextBoundedInt(4);
				$index = $random->nextBoundedInt(count($this->fossils));
				$base = StructureTemplate::load($this->registry->data, $blocks, $this->fossils[$index]);
				$overlay = StructureTemplate::load($this->registry->data, $blocks, $this->overlays[$index]);
				$chunkX = $x >> 4;
				$chunkZ = $z >> 4;
				$box = [($chunkX << 4) - 16, ($chunkZ << 4) - 16, ($chunkX << 4) + 15 + 16, ($chunkZ << 4) + 15 + 16];
				[$sizeX, , $sizeZ] = $base->getSize($rotation);
				$lowX = $x + intdiv(-$sizeX, 2);
				$lowZ = $z + intdiv(-$sizeZ, 2);
				$lowestSurfaceY = $y;
				for($xs = 0; $xs < $sizeX; ++$xs){
					for($zs = 0; $zs < $sizeZ; ++$zs){
						$lowestSurfaceY = min($lowestSurfaceY, $level->getHeight(FeatureLevel::OCEAN_FLOOR_WG, $lowX + $xs, $lowZ + $zs));
					}
				}
				$targetY = max($lowestSurfaceY - 15 - $random->nextBoundedInt(10), $level->minY + 10);
				[$zx, $zz] = $base->zeroPositionOffset($rotation);
				$tx = $lowX + $zx;
				$tz = $lowZ + $zz;
				[$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $base->getBoundingBox($tx, $targetY, $tz, $rotation);
				$empty = 0;
				foreach([$minX, $maxX] as $cx){
					foreach([$minY, $maxY] as $cy){
						foreach([$minZ, $maxZ] as $cz){
							$state = $level->getState($cx, $cy, $cz);
							$block = $blocks->blockOf[$state];
							if($blocks->isAir($state) || $block === $blocks->lavaBlock || $block === $blocks->waterBlock){
								++$empty;
							}
						}
					}
				}
				if($empty > $this->maxEmptyCorners){
					return false;
				}
				TemplateFeatures::placeInWorld($c, $base, $tx, $targetY, $tz, $rotation, $this->fossilProcessors, $box);
				TemplateFeatures::placeInWorld($c, $overlay, $tx, $targetY, $tz, $rotation, $this->overlayProcessors, $box);
				return true;
			}
		};
	}
}
