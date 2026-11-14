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

namespace pocketmine\block\utils;

/**
 * A block that can share its space with a water source (kelp, seagrass, sea pickles under water).
 *
 * Like vanilla Bedrock, the water is stored in the second block layer of the subchunk (the "liquid layer"), which the
 * client renders around the block; World keeps that layer in sync when such a block is set or replaced. On the server
 * the block itself stands in for the water: neighbouring water treats it as a source, it spreads water into empty
 * neighbours, and it leaves a water source behind when broken.
 */
interface Waterlogged{

	/**
	 * Returns whether the block currently holds a water source.
	 */
	public function isWaterlogged() : bool;
}
