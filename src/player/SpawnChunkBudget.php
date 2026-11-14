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

namespace pocketmine\player;

use function max;

/**
 * Main thread time per tick, shared by all joining players, for the work that gets their spawn chunks ready and sent
 * faster than chunk-sending.per-tick (chunk-sending.fast-spawn): with many players joining at once (e.g. after a
 * restart) the tick still gets at most this much of it, on top of the usual per-tick chunk requests.
 *
 * @internal
 */
final class SpawnChunkBudget{
	public const TIME_PER_TICK_NS = 10_000_000;

	private static int $tick = -1;
	private static int $usedNs = 0;

	/**
	 * Returns how much of the budget of the given server tick is left (nanoseconds, 0 when used up).
	 */
	public static function remaining(int $tick) : int{
		if($tick !== self::$tick){
			self::$tick = $tick;
			self::$usedNs = 0;
		}
		return max(0, self::TIME_PER_TICK_NS - self::$usedNs);
	}

	/**
	 * Records time used in the given server tick.
	 */
	public static function use(int $tick, int $ns) : void{
		if($tick !== self::$tick){
			self::$tick = $tick;
			self::$usedNs = 0;
		}
		self::$usedNs += $ns;
	}
}
