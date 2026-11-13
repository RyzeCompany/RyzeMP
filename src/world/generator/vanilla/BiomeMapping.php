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

namespace pocketmine\world\generator\vanilla;

use pocketmine\data\bedrock\BiomeIds;
use function array_keys;
use function str_starts_with;
use function substr;

/**
 * Java Edition biome names (used by the worldgen data) to Bedrock biome ids.
 */
final class BiomeMapping{
	private const MAP = [
		"badlands" => BiomeIds::MESA,
		"bamboo_jungle" => BiomeIds::BAMBOO_JUNGLE,
		"beach" => BiomeIds::BEACH,
		"birch_forest" => BiomeIds::BIRCH_FOREST,
		"cherry_grove" => BiomeIds::CHERRY_GROVE,
		"cold_ocean" => BiomeIds::COLD_OCEAN,
		"dark_forest" => BiomeIds::ROOFED_FOREST,
		"deep_cold_ocean" => BiomeIds::DEEP_COLD_OCEAN,
		"deep_dark" => BiomeIds::DEEP_DARK,
		"deep_frozen_ocean" => BiomeIds::DEEP_FROZEN_OCEAN,
		"deep_lukewarm_ocean" => BiomeIds::DEEP_LUKEWARM_OCEAN,
		"deep_ocean" => BiomeIds::DEEP_OCEAN,
		"desert" => BiomeIds::DESERT,
		"dripstone_caves" => BiomeIds::DRIPSTONE_CAVES,
		"eroded_badlands" => BiomeIds::MESA_BRYCE,
		"flower_forest" => BiomeIds::FLOWER_FOREST,
		"forest" => BiomeIds::FOREST,
		"frozen_ocean" => BiomeIds::FROZEN_OCEAN,
		"frozen_peaks" => BiomeIds::FROZEN_PEAKS,
		"frozen_river" => BiomeIds::FROZEN_RIVER,
		"grove" => BiomeIds::GROVE,
		"ice_spikes" => BiomeIds::ICE_PLAINS_SPIKES,
		"jagged_peaks" => BiomeIds::JAGGED_PEAKS,
		"jungle" => BiomeIds::JUNGLE,
		"lukewarm_ocean" => BiomeIds::LUKEWARM_OCEAN,
		"lush_caves" => BiomeIds::LUSH_CAVES,
		"mangrove_swamp" => BiomeIds::MANGROVE_SWAMP,
		"meadow" => BiomeIds::MEADOW,
		"mushroom_fields" => BiomeIds::MUSHROOM_ISLAND,
		"ocean" => BiomeIds::OCEAN,
		"old_growth_birch_forest" => BiomeIds::BIRCH_FOREST_MUTATED,
		"old_growth_pine_taiga" => BiomeIds::MEGA_TAIGA,
		"old_growth_spruce_taiga" => BiomeIds::REDWOOD_TAIGA_MUTATED,
		"pale_garden" => BiomeIds::PALE_GARDEN,
		"plains" => BiomeIds::PLAINS,
		"river" => BiomeIds::RIVER,
		"savanna" => BiomeIds::SAVANNA,
		"savanna_plateau" => BiomeIds::SAVANNA_PLATEAU,
		"snowy_beach" => BiomeIds::COLD_BEACH,
		"snowy_plains" => BiomeIds::ICE_PLAINS,
		"snowy_slopes" => BiomeIds::SNOWY_SLOPES,
		"snowy_taiga" => BiomeIds::COLD_TAIGA,
		"sparse_jungle" => BiomeIds::JUNGLE_EDGE,
		"stony_peaks" => BiomeIds::STONY_PEAKS,
		"stony_shore" => BiomeIds::STONE_BEACH,
		"sulfur_caves" => BiomeIds::SULFUR_CAVES,
		"sunflower_plains" => BiomeIds::SUNFLOWER_PLAINS,
		"swamp" => BiomeIds::SWAMPLAND,
		"taiga" => BiomeIds::TAIGA,
		"warm_ocean" => BiomeIds::WARM_OCEAN,
		"windswept_forest" => BiomeIds::EXTREME_HILLS_PLUS_TREES,
		"windswept_gravelly_hills" => BiomeIds::EXTREME_HILLS_MUTATED,
		"windswept_hills" => BiomeIds::EXTREME_HILLS,
		"windswept_savanna" => BiomeIds::SAVANNA_MUTATED,
		"wooded_badlands" => BiomeIds::MESA_PLATEAU_STONE,
		//Nether
		"nether_wastes" => BiomeIds::HELL,
		"soul_sand_valley" => BiomeIds::SOULSAND_VALLEY,
		"crimson_forest" => BiomeIds::CRIMSON_FOREST,
		"warped_forest" => BiomeIds::WARPED_FOREST,
		"basalt_deltas" => BiomeIds::BASALT_DELTAS,
		//End: Bedrock has a single End biome
		"the_end" => BiomeIds::THE_END,
		"end_highlands" => BiomeIds::THE_END,
		"end_midlands" => BiomeIds::THE_END,
		"end_barrens" => BiomeIds::THE_END,
		"small_end_islands" => BiomeIds::THE_END,
		//not in the BedrockData biome list shipped with this version yet (see BiomeDefinitionSupplement)
		"dappled_forest" => BiomeIds::FOREST,
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Java Edition biome ids (without namespace) known by this mapping.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getJavaNames() : array{
		return array_keys(self::MAP);
	}

	public static function toBedrock(string $javaName) : int{
		$name = str_starts_with($javaName, "minecraft:") ? substr($javaName, 10) : $javaName;
		return self::MAP[$name] ?? throw new \InvalidArgumentException("Unmapped Java biome: $name");
	}
}
