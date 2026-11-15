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

namespace pocketmine\network\mcpe;

use pocketmine\world\dimension\Dimension;

/**
 * @internal
 * A ChangeDimension the network session must send now, as decided by {@link ClientDimensionTracker}.
 */
final class ClientDimensionChange{
	public function __construct(
		public readonly Dimension $dimension,
		/** True when the client is sent to the dying/dead-player variant of the change (respawn screen, no loading screen ID). */
		public readonly bool $respawn,
		/** ID of the loading screen the client shows for this change, or null when it doesn't get one. */
		public readonly ?int $loadingScreenId,
		/**
		 * True for the first half of a same-dimension world switch: the client only passes through this dimension to
		 * drop the terrain of the previous world, and gets empty terrain and an immediate ack instead of world chunks.
		 */
		public readonly bool $detour
	){}
}
