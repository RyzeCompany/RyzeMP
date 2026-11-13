<?php

/*
 * VanillaMine/PocketMine-MP-derived powder snow bucket item.
 * LGPL-3.0-or-later.
 */
declare(strict_types=1);

namespace pocketmine\item;

/**
 * Valid Bedrock powder snow bucket identity and stack size.
 * Placement outside cauldrons awaits the separate powder-snow world block mechanic.
 */
class PowderSnowBucket extends Item{
	public function getMaxStackSize() : int{
		return 1;
	}
}
