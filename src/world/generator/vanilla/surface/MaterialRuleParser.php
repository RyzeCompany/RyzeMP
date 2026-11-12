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

use pocketmine\world\generator\vanilla\DensityFunctionParser;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\surface\condition\AbovePreliminarySurfaceCondition;
use pocketmine\world\generator\vanilla\surface\condition\BiomeCondition;
use pocketmine\world\generator\vanilla\surface\condition\HoleCondition;
use pocketmine\world\generator\vanilla\surface\condition\MaterialCondition;
use pocketmine\world\generator\vanilla\surface\condition\NoiseThresholdCondition;
use pocketmine\world\generator\vanilla\surface\condition\NotCondition;
use pocketmine\world\generator\vanilla\surface\condition\SteepCondition;
use pocketmine\world\generator\vanilla\surface\condition\StoneDepthCondition;
use pocketmine\world\generator\vanilla\surface\condition\VerticalGradientCondition;
use pocketmine\world\generator\vanilla\surface\condition\WaterCondition;
use pocketmine\world\generator\vanilla\surface\condition\YAboveCondition;
use pocketmine\world\generator\vanilla\surface\rule\BandlandsRule;
use pocketmine\world\generator\vanilla\surface\rule\BlockRule;
use pocketmine\world\generator\vanilla\surface\rule\ConditionRule;
use pocketmine\world\generator\vanilla\surface\rule\MaterialRule;
use pocketmine\world\generator\vanilla\surface\rule\OreVeinRule;
use pocketmine\world\generator\vanilla\surface\rule\SequenceRule;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Builds material rules and conditions from the worldgen/material_rule and worldgen/material_condition JSON (26.3+
 * format; registry references are resolved and shared).
 */
final class MaterialRuleParser{
	/** @phpstan-var array<string, MaterialRule> */
	private array $rules = [];
	/** @phpstan-var array<string, MaterialCondition> */
	private array $conditions = [];
	/** @phpstan-var array<string, true> */
	private array $resolving = [];
	private ?ClayBands $clayBands = null;

	/**
	 * @param int[] $biomeIndexes biome id => biome index
	 * @phpstan-param array<string, int> $biomeIndexes
	 */
	public function __construct(
		private readonly WorldgenData $data,
		private readonly DensityFunctionParser $densityParser,
		private readonly PositionalRandomFactory $random,
		private readonly MaterialPalette $palette,
		private readonly array $biomeIndexes,
		private readonly InterpolationCache $interpolation,
		private readonly int $minY,
		private readonly int $height
	){}

	public function rule(mixed $json) : MaterialRule{
		if(is_string($json)){
			$key = "minecraft:" . WorldgenData::stripNamespace($json);
			if(isset($this->rules[$key])){
				return $this->rules[$key];
			}
			if(isset($this->resolving[$key])){
				throw new \RuntimeException("Recursive material rule reference $key");
			}
			$this->resolving[$key] = true;
			$rule = $this->rule($this->data->materialRule($key));
			unset($this->resolving[$key]);
			return $this->rules[$key] = $rule;
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid material rule");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "block":
				return new BlockRule($this->palette->register(self::blockId($json["result_state"] ?? null)));
			case "sequence":
				$rules = [];
				foreach(WorldgenData::array($json, "sequence") as $child){
					$rules[] = $this->rule($child);
				}
				return count($rules) === 1 ? $rules[0] : new SequenceRule($rules);
			case "condition":
				return new ConditionRule($this->condition($json["if_true"] ?? null), $this->rule($json["then_run"] ?? null));
			case "bandlands":
				return new BandlandsRule($this->clayBands ??= new ClayBands(
					$this->random->fromHashOf("minecraft:clay_bands"),
					$this->palette,
					$this->densityParser->getNoise("minecraft:clay_bands_offset")
				));
			case "ore_vein":
				return new OreVeinRule(
					$this->densityParser->parse($json["density"] ?? null),
					$this->densityParser->parse($json["richness"] ?? null),
					$this->densityParser->parse($json["filler_gap"] ?? null),
					$this->palette->register(WorldgenData::string($json, "ore_block")),
					$this->palette->register(WorldgenData::string($json, "raw_ore_block")),
					$this->palette->register(WorldgenData::string($json, "filler_block")),
					WorldgenData::float($json, "raw_ore_chance"),
					$this->random->fromHashOf("minecraft:ore")->forkPositional(),
					$this->interpolation,
					$this->minY,
					$this->height
				);
			default:
				throw new \RuntimeException("Unsupported material rule type \"$type\"");
		}
	}

	public function condition(mixed $json) : MaterialCondition{
		if(is_string($json)){
			$key = "minecraft:" . WorldgenData::stripNamespace($json);
			if(isset($this->conditions[$key])){
				return $this->conditions[$key];
			}
			if(isset($this->resolving["condition:" . $key])){
				throw new \RuntimeException("Recursive material condition reference $key");
			}
			$this->resolving["condition:" . $key] = true;
			$condition = $this->condition($this->data->materialCondition($key));
			unset($this->resolving["condition:" . $key]);
			return $this->conditions[$key] = $condition;
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid material condition");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "above_preliminary_surface":
				return new AbovePreliminarySurfaceCondition();
			case "biome":
				$biomes = $json["biome_is"] ?? [];
				$matches = [];
				foreach(is_array($biomes) ? $biomes : [$biomes] as $biome){
					if(!is_string($biome)){
						throw new \RuntimeException("Invalid biome in material condition");
					}
					$index = $this->biomeIndexes["minecraft:" . WorldgenData::stripNamespace($biome)] ?? null;
					if($index !== null){
						$matches[$index] = true;
					}
				}
				return new BiomeCondition($matches);
			case "hole":
				return new HoleCondition();
			case "noise_threshold":
				return new NoiseThresholdCondition(
					$this->densityParser->getNoise(WorldgenData::string($json, "noise")),
					WorldgenData::float($json, "min_threshold"),
					WorldgenData::float($json, "max_threshold"),
					self::bool($json, "is_3d")
				);
			case "not":
				return new NotCondition($this->condition($json["invert"] ?? null));
			case "steep":
				return new SteepCondition();
			case "stone_depth":
				return new StoneDepthCondition(
					WorldgenData::int($json, "offset", 0),
					self::bool($json, "add_surface_depth"),
					WorldgenData::int($json, "secondary_depth_range", 0),
					WorldgenData::string($json, "surface_type") === "ceiling"
				);
			case "vertical_gradient":
				return new VerticalGradientCondition(
					$this->random->fromHashOf("minecraft:" . WorldgenData::stripNamespace(WorldgenData::string($json, "random_name")))->forkPositional(),
					$this->anchor($json["true_at_and_below"] ?? null),
					$this->anchor($json["false_at_and_above"] ?? null)
				);
			case "water":
				return new WaterCondition(
					WorldgenData::int($json, "offset", 0),
					WorldgenData::int($json, "surface_depth_multiplier", 0),
					self::bool($json, "add_stone_depth")
				);
			case "y_above":
				return new YAboveCondition(
					$this->anchor($json["anchor"] ?? null),
					WorldgenData::int($json, "surface_depth_multiplier", 0),
					self::bool($json, "add_stone_depth")
				);
			default:
				throw new \RuntimeException("Unsupported material condition type \"$type\"");
		}
	}

	/** VerticalAnchor: {"absolute": y} | {"above_bottom": n} | {"below_top": n} */
	private function anchor(mixed $json) : int{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid vertical anchor");
		}
		if(isset($json["absolute"])){
			return WorldgenData::int($json, "absolute");
		}
		if(isset($json["above_bottom"])){
			return $this->minY + WorldgenData::int($json, "above_bottom");
		}
		if(isset($json["below_top"])){
			return $this->minY + $this->height - 1 - WorldgenData::int($json, "below_top");
		}
		throw new \RuntimeException("Invalid vertical anchor");
	}

	private static function blockId(mixed $json) : string{
		if(is_string($json)){
			return $json;
		}
		if(is_array($json)){
			//block state object: only the block matters for the blocks used by the overworld rules
			return WorldgenData::string($json, "Name");
		}
		throw new \RuntimeException("Invalid block state");
	}

	/**
	 * @param mixed[] $json
	 */
	private static function bool(array $json, string $key) : bool{
		$value = $json[$key] ?? false;
		if(!is_bool($value)){
			throw new \RuntimeException("Expected boolean for \"$key\"");
		}
		return $value;
	}
}
