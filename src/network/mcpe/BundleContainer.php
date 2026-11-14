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

use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Bundle;

/**
 * A client-side dynamic container (ContainerEnumName DynamicContainer + dynamic ID) that shows the contents of the
 * bundle in one inventory slot. The contents are mirrored into an inventory so that ItemStackRequests can move items
 * in and out of the bundle like any other container; changes are written back to the bundle item afterwards.
 *
 * @internal
 */
final class BundleContainer{
	public readonly SimpleInventory $inventory;
	/** Set when the contents inventory was changed by a transaction and must be written back to the bundle. */
	public bool $dirty = false;

	public function __construct(
		public readonly int $id,
		public Inventory $ownerInventory,
		public int $ownerSlot
	){
		$this->inventory = new SimpleInventory(Bundle::MAX_SLOTS);
	}
}
