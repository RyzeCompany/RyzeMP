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

namespace pocketmine\world\light;

//Class added by the chunkutils2 extension of PocketMine-MP Reborn (0.3.13+). PocketMine uses it only when it exists
//(class_exists()), so it is declared here for static analysis with PHP builds that don't have it. Scanned by PHPStan
//only (phpstan.neon.dist scanFiles), never loaded.

use pocketmine\world\format\LightArray;
use pocketmine\world\format\PalettedBlockArray;

/**
 * chunkutils2 >= 0.3.13: the light population of one chunk, see LightPopulationTask::computeLight().
 */
final class NativeLightPopulation{
	/**
	 * @param int[]                $emptyBlockIds
	 * @param PalettedBlockArray[] $layers
	 * @param int[]                $lightFilters
	 * @param int[]                $lightEmitters
	 * @param true[]               $directSkyLightBlockers
	 * @phpstan-param array<int, int>                $emptyBlockIds
	 * @phpstan-param array<int, PalettedBlockArray> $layers
	 * @phpstan-param array<int, int>                $lightFilters
	 * @phpstan-param array<int, int>                $lightEmitters
	 * @phpstan-param array<int, true>               $directSkyLightBlockers
	 * @phpstan-return array{list<int>, array<int, LightArray>, array<int, LightArray>}|null
	 */
	public static function populate(int $minSubChunkIndex, int $maxSubChunkIndex, array $emptyBlockIds, array $layers, array $lightFilters, array $lightEmitters, array $directSkyLightBlockers) : ?array{ return null; }
}
