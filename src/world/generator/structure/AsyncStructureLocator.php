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

namespace pocketmine\world\generator\structure;

use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * A {@link StructureLocator} whose searches can be slow (seconds), so /locate structure runs them off the main thread
 * through {@link findNearestAsync()} instead of calling {@link findNearest()}.
 */
interface AsyncStructureLocator extends StructureLocator{

	/**
	 * Like {@link findNearest()}, but the result is passed to $onCompletion later, on the main thread: the position
	 * found (or null), $failed = true if the search crashed, $timedOut = true if it gave up after $timeoutSeconds.
	 *
	 * @param int $timeoutSeconds 0 = no time limit
	 * @phpstan-param \Closure(?Vector3 $position, bool $failed, bool $timedOut) : void $onCompletion
	 */
	public function findNearestAsync(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly, int $timeoutSeconds, \Closure $onCompletion) : void;
}
