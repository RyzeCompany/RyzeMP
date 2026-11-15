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

namespace pocketmine\world\mobs\spawn;

use pocketmine\world\dimension\Dimension;

/**
 * Bedrock population caps: the maximum number of naturally spawned mobs of each population type in the 9x9 chunks
 * around the chunk of a spawn attempt, separately for surface and underground ("cave") spawns, per dimension.
 * Values from https://minecraft.wiki/w/Mob_spawning (Bedrock Edition, population caps).
 */
final class PopulationCaps{
	/** The caps count the mobs within this many chunks (9x9 square) of the spawn attempt's chunk. */
	public const AREA_RADIUS_CHUNKS = 4;
	/** Bedrock global cap: naturally spawned mobs in the ticking area of a dimension. */
	public const GLOBAL_CAP = 200;

	/**
	 * dimension => population => [surface cap, underground cap]
	 */
	private const CAPS = [
		"overworld" => [
			SpawnCondition::POPULATION_AMBIENT => [0, 2],
			SpawnCondition::POPULATION_ANIMAL => [4, 0],
			SpawnCondition::POPULATION_MONSTER => [8, 16],
			SpawnCondition::POPULATION_PILLAGER => [8, 8],
			SpawnCondition::POPULATION_WATER_ANIMAL => [36, 0],
		],
		"nether" => [
			SpawnCondition::POPULATION_AMBIENT => [0, 0],
			SpawnCondition::POPULATION_ANIMAL => [0, 4],
			SpawnCondition::POPULATION_MONSTER => [0, 16],
			SpawnCondition::POPULATION_PILLAGER => [0, 0],
			SpawnCondition::POPULATION_WATER_ANIMAL => [0, 0],
		],
		"end" => [
			SpawnCondition::POPULATION_AMBIENT => [0, 2],
			SpawnCondition::POPULATION_ANIMAL => [4, 0],
			SpawnCondition::POPULATION_MONSTER => [10, 8],
			SpawnCondition::POPULATION_PILLAGER => [8, 8],
			SpawnCondition::POPULATION_WATER_ANIMAL => [36, 0],
		],
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the cap of the population type in the dimension (0 for unknown types, e.g. "cat": cats spawn in villages
	 * only, see {@link \pocketmine\world\mobs\VillageCatSpawner}).
	 */
	public static function get(Dimension $dimension, string $population, bool $surface) : int{
		$key = match($dimension){
			Dimension::OVERWORLD => "overworld",
			Dimension::NETHER => "nether",
			Dimension::END => "end",
		};
		$caps = self::CAPS[$key][$population] ?? null;
		return $caps === null ? 0 : $caps[$surface ? 0 : 1];
	}
}
