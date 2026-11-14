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

namespace pocketmine\world;

use pocketmine\entity\monster\Monster;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function ceil;
use function max;

/**
 * When players may sleep and when the night is skipped (vanilla Bedrock numbers, see minecraft.wiki "Bed").
 *
 * @internal
 */
final class SleepRules{
	/** Sleeping is allowed from this time of day... */
	public const SLEEP_START = 12542;
	/** ...up to this one (inclusive). */
	public const SLEEP_END = 23459;
	/** The window while it rains (thunderstorms allow sleeping at any time). */
	public const RAIN_SLEEP_START = 12010;
	public const RAIN_SLEEP_END = 23991;

	/** Monsters this close to the bed (horizontally / vertically) keep players awake (Java ServerPlayer). */
	private const MONSTER_RANGE_H = 8.0;
	private const MONSTER_RANGE_V = 5.0;

	private function __construct(){
		//NOOP
	}

	public static function canSleepAtTime(int $timeOfDay, bool $raining, bool $thundering) : bool{
		if($thundering){
			return true;
		}
		if($raining){
			return $timeOfDay >= self::RAIN_SLEEP_START && $timeOfDay <= self::RAIN_SLEEP_END;
		}
		return $timeOfDay >= self::SLEEP_START && $timeOfDay <= self::SLEEP_END;
	}

	/**
	 * Whether players may sleep in this world now (time of day and weather).
	 */
	public static function canSleepNow(World $world) : bool{
		$weather = $world->getWeather();
		return self::canSleepAtTime($world->getTimeOfDay(), $weather->isRaining(), $weather->isThundering());
	}

	/**
	 * Number of sleeping players needed to skip the night (playerssleepingpercentage): at least one, more than the
	 * players present when the percentage is over 100 (the night is never skipped).
	 */
	public static function getRequiredSleepers(int $players, int $percentage) : int{
		return max(1, (int) ceil($players * $percentage / 100));
	}

	/**
	 * Returns a monster near the bed that keeps the player awake, or null. Zombified piglins only count when they
	 * are angry at the player (Java Monster.isPreventingPlayerRest).
	 */
	public static function findMonsterPreventingSleep(World $world, Vector3 $bed, Player $player) : ?Monster{
		$x = $bed->getFloorX() + 0.5;
		$y = (float) $bed->getFloorY();
		$z = $bed->getFloorZ() + 0.5;
		$box = new AxisAlignedBB(
			$x - self::MONSTER_RANGE_H, $y - self::MONSTER_RANGE_V, $z - self::MONSTER_RANGE_H,
			$x + self::MONSTER_RANGE_H, $y + self::MONSTER_RANGE_V, $z + self::MONSTER_RANGE_H
		);
		foreach($world->getNearbyEntities($box) as $entity){
			if(!($entity instanceof Monster) || !$entity->isAlive() || $entity->isClosed()){
				continue;
			}
			if($entity instanceof ZombifiedPiglin && $entity->getAngerTarget() !== $player){
				continue;
			}
			return $entity;
		}
		return null;
	}
}
