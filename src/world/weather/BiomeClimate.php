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

namespace pocketmine\world\weather;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\utils\Filesystem;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Adapted for RyzeMP without supplementing invented biome IDs.
 * Rain flag and base temperature of the vanilla biomes (BedrockData biome_definitions.json "rain" and "temperature"),
 * which decide whether it rains, snows or stays dry in a biome.
 */
final class BiomeClimate{
	/** Below this temperature it snows instead of raining (vanilla). */
	public const SNOW_TEMPERATURE = 0.15;
	/** Above this height the temperature drops (vanilla: sea level + 17). */
	public const COOLING_START_Y = 80;
	/** Temperature lost per block above {@link BiomeClimate::COOLING_START_Y} (vanilla 0.05 / 40, without the noise). */
	public const COOLING_PER_BLOCK = 0.05 / 40;

	/** Biomes with more downfall than this are humid. */
	private const HUMID_DOWNFALL = 0.85;
	/** Biomes marked humid by the behaviour pack (minecraft:humidity is_humid) whatever their downfall. */
	private const HUMID_BIOMES = ["roofed_forest" => true, "roofed_forest_mutated" => true];

	/**
	 * Snow layers that pile up while it snows, from the maximum of minecraft:climate snow_accumulation in the
	 * behaviour pack biomes (blocks, 1 layer = 0.125). The other biomes take 1 layer.
	 */
	private const SNOW_ACCUMULATION_LAYERS = [
		"cold_beach" => 2,
		"cold_taiga" => 4,
		"cold_taiga_hills" => 4,
		"deep_frozen_ocean" => 2,
		"extreme_hills" => 2,
		"extreme_hills_edge" => 2,
		"frozen_ocean" => 2,
		"frozen_peaks" => 2,
		"frozen_river" => 2,
		"grove" => 2,
		"ice_mountains" => 12,
		"ice_plains" => 8,
		"ice_plains_spikes" => 12,
		"jagged_peaks" => 2,
		"legacy_frozen_ocean" => 2,
		"snowy_slopes" => 4,
		"stone_beach" => 2,
		"taiga" => 2,
		"taiga_hills" => 2,
	];

	/**
	 * @var array[]|null biome ID => [rain, temperature, humid, snow accumulation layers]
	 * @phpstan-var array<int, array{bool, float, bool, int}>|null
	 */
	private static ?array $biomes = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return array<int, array{bool, float, bool, int}>
	 */
	private static function load() : array{
		if(self::$biomes !== null){
			return self::$biomes;
		}
		$definitions = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BIOME_DEFINITIONS_JSON), true, flags: JSON_THROW_ON_ERROR);
		$biomes = [];
		if(is_array($definitions)){
			foreach(LegacyBiomeIdToStringIdMap::getInstance()->getLegacyToStringMap() as $id => $name){
				$definition = $definitions["minecraft:" . $name] ?? null;
				if(!is_array($definition)){
					continue;
				}
				$rain = $definition["rain"] ?? true;
				$temperature = $definition["temperature"] ?? 0.8;
				$downfall = $definition["downfall"] ?? 0.0;
				$biomes[$id] = [
					!is_bool($rain) || $rain,
					is_float($temperature) || is_int($temperature) ? (float) $temperature : 0.8,
					//Bedrock: downfall above 0.85, or minecraft:humidity is_humid in the behaviour pack (only the dark forests)
					((is_float($downfall) || is_int($downfall)) && $downfall > self::HUMID_DOWNFALL) || isset(self::HUMID_BIOMES[$name]),
					self::SNOW_ACCUMULATION_LAYERS[$name] ?? 1
				];
			}
		}
		return self::$biomes = $biomes;
	}

	/**
	 * Whether it ever rains (or snows) in the biome. False for deserts, savannas, badlands, the Nether and the End.
	 */
	public static function hasPrecipitation(int $biomeId) : bool{
		return self::load()[$biomeId][0] ?? false;
	}

	/**
	 * Whether the biome is humid (jungles, swamps, mushroom fields, dark forests...): fire spreads half as often and
	 * burns blocks up faster there.
	 */
	public static function isHumid(int $biomeId) : bool{
		return self::load()[$biomeId][2] ?? false;
	}

	/**
	 * How many snow layers (1-12, more than 8 stack on a full layer) can pile up in the biome while it snows.
	 */
	public static function getSnowAccumulationLayers(int $biomeId) : int{
		return self::load()[$biomeId][3] ?? 1;
	}

	public static function getBaseTemperature(int $biomeId) : float{
		return self::load()[$biomeId][1] ?? 0.8;
	}

	/**
	 * Temperature of the biome at the given height: it gets colder above y=80, so high mountains get snow.
	 */
	public static function getTemperatureAt(int $biomeId, int $y) : float{
		$temperature = self::getBaseTemperature($biomeId);
		if($y > self::COOLING_START_Y){
			$temperature -= ($y - self::COOLING_START_Y) * self::COOLING_PER_BLOCK;
		}
		return $temperature;
	}

	/**
	 * What falls at the given height of the biome when it rains.
	 */
	public static function getPrecipitation(int $biomeId, int $y) : Precipitation{
		if(!self::hasPrecipitation($biomeId)){
			return Precipitation::NONE;
		}
		return self::getTemperatureAt($biomeId, $y) < self::SNOW_TEMPERATURE ? Precipitation::SNOW : Precipitation::RAIN;
	}

	/**
	 * Whether water freezes and snow settles at the given height of the biome (cold enough to snow).
	 */
	public static function isCold(int $biomeId, int $y) : bool{
		return self::getTemperatureAt($biomeId, $y) < self::SNOW_TEMPERATURE;
	}
}
