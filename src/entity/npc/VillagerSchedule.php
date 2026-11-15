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

namespace pocketmine\entity\npc;

use pocketmine\world\World;

/**
 * The daily schedules of villager_v2.json ("minecraft:scheduler", hourly_clock_time = time of day, 0 = 6:00):
 *
 * - work_schedule (employed adults; farmers, fishermen and librarians have their own work goals): work 0-8000,
 *   gather 8000-10000, work 10000-11000, home 11000-12000, sleep 12000-24000.
 * - basic_schedule (unemployed adults): the same with wander instead of work.
 * - jobless_schedule (nitwits): sleep 0-2000, wander 2000-13000, home 13000-14000, sleep 14000-24000.
 * - child_schedule (babies): play 0-11000, home 11000-12000, sleep 12000-24000.
 *
 * Vanilla switches with a random delay of up to 10 seconds (max_delay_secs); here the switch is immediate.
 */
final class VillagerSchedule{

	private function __construct(){
		//NOOP
	}

	/**
	 * Activity of a villager at the given time of day. In the rain villagers go home instead of working, gathering,
	 * wandering or playing (they shelter at their bed).
	 */
	public static function getActivity(int $timeOfDay, int $profession, bool $baby, bool $raining = false) : VillagerActivity{
		$activity = self::getScheduledActivity($timeOfDay, $profession, $baby);
		if($raining && $activity !== VillagerActivity::SLEEP){
			return VillagerActivity::HOME;
		}
		return $activity;
	}

	private static function getScheduledActivity(int $timeOfDay, int $profession, bool $baby) : VillagerActivity{
		$t = (($timeOfDay % World::TIME_FULL) + World::TIME_FULL) % World::TIME_FULL;
		if($baby){
			return match(true){
				$t < 11000 => VillagerActivity::PLAY,
				$t < 12000 => VillagerActivity::HOME,
				default => VillagerActivity::SLEEP
			};
		}
		if($profession === VillagerV2::PROFESSION_NITWIT){
			return match(true){
				$t < 2000 => VillagerActivity::SLEEP,
				$t < 13000 => VillagerActivity::WANDER,
				$t < 14000 => VillagerActivity::HOME,
				default => VillagerActivity::SLEEP
			};
		}
		$work = $profession === VillagerV2::PROFESSION_UNEMPLOYED ? VillagerActivity::WANDER : VillagerActivity::WORK;
		return match(true){
			$t < 8000 => $work,
			$t < 10000 => VillagerActivity::GATHER,
			$t < 11000 => $work,
			$t < 12000 => VillagerActivity::HOME,
			default => VillagerActivity::SLEEP
		};
	}
}
