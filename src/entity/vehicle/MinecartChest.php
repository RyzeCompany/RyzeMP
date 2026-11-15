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

namespace pocketmine\entity\vehicle;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Minecart with chest (vanilla minecraft:chest_minecart): 27 slots. Generated in mineshafts with the
 * abandoned_mineshaft loot table. Broken, it drops a minecart, a chest and its contents (Bedrock).
 */
class MinecartChest extends ContainerMinecart{
	public const INVENTORY_SIZE = 27;

	public static function getNetworkTypeId() : string{ return EntityIds::CHEST_MINECART; }

	public function getName() : string{
		return "Minecart with Chest";
	}

	public function getMinecartType() : MinecartType{
		return MinecartType::CHEST;
	}

	public function getInventorySize() : int{
		return self::INVENTORY_SIZE;
	}

	public function getCartDrops() : array{
		return [VanillaItems::MINECART(), VanillaBlocks::CHEST()->asItem()];
	}
}
