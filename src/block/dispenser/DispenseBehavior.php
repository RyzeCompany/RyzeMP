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

namespace pocketmine\block\dispenser;

use pocketmine\item\Item;

/**
 * What a dispenser does with an item type.
 */
interface DispenseBehavior{

	/**
	 * Dispenses from the given stack, which is a copy of the contents of the chosen dispenser slot. Returns the new
	 * contents of the slot, or null if nothing could be done (the item stays and the dispenser clicks as if empty).
	 * Items produced on the side (e.g. a filled bucket from a stack of empty buckets) go through
	 * {@link DispenseContext::addOrDrop()}.
	 */
	public function dispense(DispenseContext $context, Item $stack) : ?Item;
}
