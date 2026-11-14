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

use pocketmine\math\Vector3;
use function intdiv;
use function max;
use function min;

/**
 * Regional (local) difficulty: how dangerous the world is at a place, used by mob spawn equipment (the Bedrock loot
 * conditions random_regional_difficulty_chance and the enchant_random_gear function).
 *
 * Port of Java DifficultyInstance: the effective difficulty grows with the time of day counter of the world (after the
 * first 3 days, over 60 days), the time players spent in the chunk and the moon phase, scaled by the difficulty. The
 * "special multiplier" maps it to 0-1 (0 below 2.0, 1 above 4.0).
 *
 * PocketMine keeps no chunk inhabited time: it is taken as 0, so the local part only comes from the moon phase (at
 * most 0.25 of the world age part). Regional difficulty is only computed when a mob spawns.
 */
final class RegionalDifficulty{
	/** Moon brightness by moon phase (0 = full moon), vanilla DimensionType.MOON_BRIGHTNESS_PER_PHASE. */
	public const MOON_BRIGHTNESS = [1.0, 0.75, 0.5, 0.25, 0.0, 0.25, 0.5, 0.75];

	private const GLOBAL_TIME_OFFSET = 72000;
	private const MAX_GLOBAL_TIME = 1440000;
	private const MAX_LOCAL_TIME = 3600000;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the effective difficulty (0 on Peaceful, up to 1.5 on Easy, 4.0 on Normal and 6.75 on Hard).
	 *
	 * @param int   $difficulty     one of the World::DIFFICULTY_* constants
	 * @param int   $dayTime        the world time counter ({@link World::getTime()})
	 * @param int   $inhabitedTime  ticks players spent in the chunk
	 * @param float $moonBrightness 0-1, see {@link RegionalDifficulty::MOON_BRIGHTNESS}
	 */
	public static function calculate(int $difficulty, int $dayTime, int $inhabitedTime, float $moonBrightness) : float{
		if($difficulty <= World::DIFFICULTY_PEACEFUL){
			return 0.0;
		}
		$hard = $difficulty === World::DIFFICULTY_HARD;
		$globalScale = self::clamp(($dayTime - self::GLOBAL_TIME_OFFSET) / self::MAX_GLOBAL_TIME) * 0.25;
		$localScale = self::clamp($inhabitedTime / self::MAX_LOCAL_TIME) * ($hard ? 1.0 : 0.75);
		$localScale += max(0.0, min($globalScale, $moonBrightness * 0.25));
		if($difficulty === World::DIFFICULTY_EASY){
			$localScale *= 0.5;
		}
		return $difficulty * (0.75 + $globalScale + $localScale);
	}

	/**
	 * Returns the clamped regional difficulty (Java DifficultyInstance.getSpecialMultiplier()): 0 below an effective
	 * difficulty of 2.0, 1 above 4.0, linear in between.
	 */
	public static function getSpecialMultiplier(float $effectiveDifficulty) : float{
		if($effectiveDifficulty < 2.0){
			return 0.0;
		}
		if($effectiveDifficulty > 4.0){
			return 1.0;
		}
		return ($effectiveDifficulty - 2.0) / 2.0;
	}

	/**
	 * Returns the effective difficulty at a position of the world.
	 */
	public static function getEffectiveAt(World $world, Vector3 $pos) : float{
		$time = $world->getTime();
		return self::calculate($world->getDifficulty(), $time, 0, self::MOON_BRIGHTNESS[intdiv(max(0, $time), World::TIME_FULL) % 8]);
	}

	/**
	 * Returns the clamped regional difficulty (0-1) at a position of the world.
	 */
	public static function getSpecialMultiplierAt(World $world, Vector3 $pos) : float{
		return self::getSpecialMultiplier(self::getEffectiveAt($world, $pos));
	}

	private static function clamp(float $value) : float{
		return max(0.0, min(1.0, $value));
	}
}
