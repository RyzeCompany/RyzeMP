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

use pocketmine\network\mcpe\protocol\types\DimensionIds;

/**
 * World state shared by every spawn rule check of one spawn pass.
 */
final class SpawnContext{
	/**
	 * @param int $difficulty          World::DIFFICULTY_*
	 * @param int $skyReductionWeather sky light subtracted by the time of day and the weather
	 *                                 ("adjust_for_weather": true)
	 * @param int $skyReductionTime    sky light subtracted by the time of day only
	 * @param int $minDistance         default minimum distance to players (vanilla 24)
	 * @param int $maxDistance         default maximum distance to players (vanilla 44 with simulation distance 4)
	 */
	public function __construct(
		public readonly int $difficulty,
		public readonly int $skyReductionWeather,
		public readonly int $skyReductionTime,
		public readonly int $dimension = DimensionIds::OVERWORLD,
		public readonly int $minDistance = SpawnCondition::DEFAULT_MIN_DISTANCE,
		public readonly int $maxDistance = SpawnCondition::DEFAULT_MAX_DISTANCE
	){}
}
