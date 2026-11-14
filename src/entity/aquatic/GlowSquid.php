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

namespace pocketmine\entity\aquatic;

use pocketmine\entity\Squid;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (entities/glow_squid.json): 10 health, 0.8x0.8, the same squid behaviours, leads, despawning and
 * babies (5%) as the squid. The glow is drawn by the client. Loot (loot_tables/entities/glow_squid.json): 1-3 glow ink
 * sacs (+0-1 per Looting level); XP 1-3 when an adult is killed by a player.
 */
class GlowSquid extends Squid{

	public static function getNetworkTypeId() : string{ return EntityIds::GLOW_SQUID; }

	public function getName() : string{
		return "Glow Squid";
	}

	public function getDrops() : array{
		return [VanillaItems::GLOW_INK_SAC()->setCount(mt_rand(1, 3) + $this->lootingBonus())];
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::GLOW_SQUID_SPAWN_EGG();
	}
}
