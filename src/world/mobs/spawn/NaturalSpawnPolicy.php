<?php
declare(strict_types=1);

namespace pocketmine\world\mobs\spawn;

use pocketmine\world\World;

/** Pure rules, independent of worlds and network protocol, for regression testing. */
final class NaturalSpawnPolicy{
	public const MIN_PLAYER_DISTANCE = 24;
	public const MAX_PLAYER_DISTANCE = 48;
	public const DEFAULT_TOTAL_CAP = 72;
	public const DEFAULT_MONSTER_CAP = 40;
	public const DEFAULT_ANIMAL_CAP = 24;
	public const DEFAULT_WATER_CAP = 12;
	private function __construct(){}

	public static function withinPlayerRange(float $distanceSquared) : bool{
		return $distanceSquared >= self::MIN_PLAYER_DISTANCE ** 2 &&
			$distanceSquared <= self::MAX_PLAYER_DISTANCE ** 2;
	}
	public static function underCap(int $count, int $cap) : bool{
		return $cap > 0 && $count < $cap;
	}
	public static function canSpawnZombie(int $difficulty,int $light) : bool{
		return $difficulty !== World::DIFFICULTY_PEACEFUL && $light <= 7;
	}
	public static function canSpawnAnimal(int $light) : bool{
		return $light >= 9;
	}

	/**
	 * Current catalog has only squid as an aquatic natural mob. Avoid inventing
	 * squids on land or in unrelated water features.
	 */
	public static function canSpawnSquidBiome(?string $biomeName) : bool{
		return $biomeName !== null &&
			(str_contains($biomeName, "ocean") || str_contains($biomeName, "river"));
	}

	/**
	 * Cows, pigs, sheep and chickens should not replace missing habitat-specific
	 * mobs in deserts, oceans, mushroom islands, beaches or other dimensions.
	 * Unknown biome IDs are rejected until data is available for them.
	 */
	public static function canSpawnFarmAnimalBiome(?string $biomeName) : bool{
		if($biomeName === null){return false;}
		foreach(["ocean", "river", "desert", "mesa", "badlands", "mushroom", "beach", "hell", "the_end", "nether", "basalt"] as $excluded){
			if(str_contains($biomeName, $excluded)){return false;}
		}
		return true;
	}

	/** @param array<string, int> $populationByChunk */
	public static function populationInChunkRadius(array $populationByChunk, int $cx, int $cz, int $radius = 4) : int{
		$result = 0;
		$radius = max(0, min(4, $radius));
		for($x = $cx - $radius; $x <= $cx + $radius; ++$x){
			for($z = $cz - $radius; $z <= $cz + $radius; ++$z){
				$result += $populationByChunk[$x . ":" . $z] ?? 0;
			}
		}
		return $result;
	}
}
