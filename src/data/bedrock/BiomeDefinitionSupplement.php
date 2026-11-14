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

namespace pocketmine\data\bedrock;

use function in_array;

/**
 * Vanilla biomes of this Bedrock version that the BedrockData biome files (biome_id_map.json,
 * biome_definitions.json) don't list yet. They are added to the ID map, to the biome definitions read by the server
 * (mob variants, spawn rules, weather) and to the definitions sent to clients, unless BedrockData already has them.
 *
 * - dappled_forest (ID 195 in Mojang's 1.26.50 biome dump): climate and tags from the behaviour pack
 *   (biomes/dappled_forest.biome.json); depth and scale of the forest; map water colour of biomes without a
 *   biomes_client.json entry.
 *
 * @internal
 */
final class BiomeDefinitionSupplement{
	public const DAPPLED_FOREST = 195;

	/**
	 * Biome name => [ID, entry in the format of biome_definitions.json]
	 */
	private const BIOMES = [
		"dappled_forest" => [self::DAPPLED_FOREST, [
			"depth" => 0.1,
			"downfall" => 0.6,
			"foliageSnow" => 0,
			"id" => 65535,
			"mapWaterColour" => ["a" => 166, "b" => 255, "g" => 183, "r" => 96],
			"rain" => true,
			"scale" => 0.2,
			"tags" => ["animal", "dappled_forest", "monster", "forest", "overworld", "spawns_cold_variant_farm_animals", "has_structure_abandoned_camp"],
			"temperature" => 0.6
		]]
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Adds the missing biomes to a biome ID map (biome_id_map.json).
	 */
	public static function addIds(LegacyToStringIdMap $map) : void{
		$known = $map->getLegacyToStringMap();
		foreach(self::BIOMES as $name => [$id]){
			if(!isset($known[$id]) && !in_array($name, $known, true)){
				$map->add($name, $id);
			}
		}
	}

	/**
	 * Returns the decoded biome_definitions.json with the missing biomes added.
	 *
	 * @param mixed[] $definitions
	 * @phpstan-param array<mixed> $definitions
	 *
	 * @return mixed[]
	 * @phpstan-return array<mixed>
	 */
	public static function addDefinitions(array $definitions) : array{
		foreach(self::BIOMES as $name => [, $definition]){
			$definitions["minecraft:" . $name] ??= $definition;
		}
		return $definitions;
	}
}
