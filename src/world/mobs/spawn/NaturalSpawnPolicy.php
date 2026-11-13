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
}
