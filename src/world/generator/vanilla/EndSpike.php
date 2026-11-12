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

namespace pocketmine\world\generator\vanilla;

/**
 * One obsidian spike of the End (see {@link EndSpikes}): its centre column, radius and height (the y of the block
 * the End crystal's bedrock sits on). Guarded spikes have an iron bar cage around the crystal.
 */
final class EndSpike{

	public function __construct(
		public readonly int $centerX,
		public readonly int $centerZ,
		public readonly int $radius,
		public readonly int $height,
		public readonly bool $guarded
	){}

	public function isCenterWithinChunk(int $chunkX, int $chunkZ) : bool{
		return ($this->centerX >> 4) === $chunkX && ($this->centerZ >> 4) === $chunkZ;
	}
}
