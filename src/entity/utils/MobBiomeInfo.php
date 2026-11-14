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

namespace pocketmine\entity\utils;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\math\Vector3;
use pocketmine\utils\Filesystem;
use pocketmine\world\World;
use function array_keys;
use function floor;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Biome tags and temperatures of the vanilla biome definitions (BedrockData biome_definitions.json), used to pick
 * mob variants the way vanilla does ("spawns_snow_foxes", "spawns_cold_variant_frogs", "desert"...).
 */
final class MobBiomeInfo{
	/**
	 * @var array[]|null biome ID => [tags, temperature]
	 * @phpstan-var array<int, array{list<string>, float, string}>|null
	 */
	private static ?array $biomes = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return array<int, array{list<string>, float, string}>
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
				$tags = [];
				foreach(is_array($definition["tags"] ?? null) ? $definition["tags"] : [] as $tag){
					if(is_string($tag)){
						$tags[] = $tag;
					}
				}
				$temperature = $definition["temperature"] ?? 0.8;
				$biomes[$id] = [$tags, is_float($temperature) || is_int($temperature) ? (float) $temperature : 0.8, $name];
			}
		}
		return self::$biomes = $biomes;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getTags(World $world, Vector3 $pos) : array{
		return self::load()[$world->getBiomeId((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))][0] ?? [];
	}

	public static function hasTag(World $world, Vector3 $pos, string $tag) : bool{
		return in_array($tag, self::getTags($world, $pos), true);
	}

	/**
	 * Returns the base temperature of the biome (plains 0.8, snowy biomes below 0.15, deserts 2.0).
	 */
	public static function getTemperature(World $world, Vector3 $pos) : float{
		return self::load()[$world->getBiomeId((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))][1] ?? 0.8;
	}

	/**
	 * Returns the tags of the biome with this ID (empty if unknown).
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getTagsById(int $biomeId) : array{
		return self::load()[$biomeId][0] ?? [];
	}

	/**
	 * Returns the base temperature of the biome with this ID (0.8 if unknown).
	 */
	public static function getTemperatureById(int $biomeId) : float{
		return self::load()[$biomeId][1] ?? 0.8;
	}

	/**
	 * Returns the IDs of all biomes with a vanilla definition.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getKnownBiomeIds() : array{
		return array_keys(self::load());
	}

	/**
	 * Returns the Bedrock name of the biome without namespace (e.g. "mega_taiga"), or "" if unknown.
	 */
	public static function getName(World $world, Vector3 $pos) : string{
		return self::load()[$world->getBiomeId((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))][2] ?? "";
	}
}
