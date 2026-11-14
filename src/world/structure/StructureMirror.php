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
 * Mirror of a structure when it is loaded (values of the structure block's "mirror" byte and of the network structure
 * settings). Like Bedrock, mirroring across the X axis flips the Z coordinates (north and south swap) and mirroring
 * across the Z axis flips the X coordinates (east and west swap).
 */
enum StructureMirror : int{
	case NONE = 0;
	case X = 1;
	case Z = 2;
	case XZ = 3;

	public function flipsZ() : bool{
		return $this === self::X || $this === self::XZ;
	}

	public function flipsX() : bool{
		return $this === self::Z || $this === self::XZ;
	}

	/**
	 * Whether the mirror changes handedness (left becomes right), i.e. it flips exactly one axis.
	 */
	public function swapsHandedness() : bool{
		return $this === self::X || $this === self::Z;
	}
}
