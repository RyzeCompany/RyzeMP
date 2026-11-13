<?php

declare(strict_types=1);

namespace pocketmine\item;

/** Keeps generated crossbows, their damage and enchantments in loot and LevelDB. */
class Crossbow extends Tool{
	public function getMaxDurability() : int{
		return 465;
	}
}
