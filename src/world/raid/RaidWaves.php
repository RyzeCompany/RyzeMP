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

namespace pocketmine\world\raid;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;

/**
 * Raid waves of Bedrock Edition (https://minecraft.wiki/w/Raid#Raid_wave_composition, Bedrock table): 3 waves on
 * Easy, 5 on Normal and 7 on Hard, whatever the level of the omen (Bedrock has no bonus wave), with a fixed
 * composition per wave and no random extra raiders (13, 26 and 45 mobs in total).
 */
final class RaidWaves{
	/**
	 * Wave number => list of [mob, rider or null, count]. A ravager with a rider counts as one mob of the wave.
	 *
	 * @phpstan-var array<int, list<array{string, string|null, int}>>
	 */
	private const WAVES = [
		1 => [
			[EntityIds::PILLAGER, null, 4],
		],
		2 => [
			[EntityIds::PILLAGER, null, 3],
			[EntityIds::VINDICATOR, null, 2],
		],
		3 => [
			[EntityIds::PILLAGER, null, 3],
			[EntityIds::RAVAGER, null, 1],
		],
		4 => [
			[EntityIds::PILLAGER, null, 3],
			[EntityIds::WITCH, null, 3],
		],
		5 => [
			[EntityIds::VINDICATOR, null, 4],
			[EntityIds::RAVAGER, null, 1],
			[EntityIds::EVOCATION_ILLAGER, null, 1],
			[EntityIds::RAVAGER, EntityIds::PILLAGER, 1],
		],
		6 => [
			[EntityIds::PILLAGER, null, 5],
			[EntityIds::VINDICATOR, null, 2],
			[EntityIds::EVOCATION_ILLAGER, null, 1],
		],
		7 => [
			[EntityIds::VINDICATOR, null, 6],
			[EntityIds::WITCH, null, 1],
			[EntityIds::EVOCATION_ILLAGER, null, 2],
			[EntityIds::RAVAGER, EntityIds::PILLAGER, 1],
			[EntityIds::RAVAGER, EntityIds::EVOCATION_ILLAGER, 1],
		],
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Number of waves of a raid started in this difficulty (none in peaceful).
	 */
	public static function getWaveCount(int $difficulty) : int{
		return match($difficulty){
			World::DIFFICULTY_EASY => 3,
			World::DIFFICULTY_NORMAL => 5,
			World::DIFFICULTY_HARD => 7,
			default => 0
		};
	}

	/**
	 * Returns the mobs of a wave (1-based): list of [entity ID, rider entity ID or null, count].
	 *
	 * @phpstan-return list<array{string, string|null, int}>
	 */
	public static function getWave(int $wave) : array{
		return self::WAVES[$wave] ?? throw new \InvalidArgumentException("There is no raid wave $wave");
	}

	/**
	 * Number of mobs of a wave as shown by the raid bar (ravagers with riders count once).
	 */
	public static function getWaveSize(int $wave) : int{
		$total = 0;
		foreach(self::getWave($wave) as [, , $count]){
			$total += $count;
		}
		return $total;
	}
}
