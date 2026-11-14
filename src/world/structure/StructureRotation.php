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

namespace pocketmine\world\structure;

/**
 * Rotation of a structure when it is loaded, clockwise seen from above (values of the structure block's "rotation"
 * byte and of the network structure settings).
 */
enum StructureRotation : int{
	case NONE = 0;
	case CLOCKWISE_90 = 1;
	case CLOCKWISE_180 = 2;
	case CLOCKWISE_270 = 3;

	/**
	 * Number of clockwise quarter turns.
	 */
	public function getQuarterTurns() : int{
		return $this->value;
	}
}
