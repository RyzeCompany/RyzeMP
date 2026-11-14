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

use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Bedrock values (entities/fish.json): 3 health, 0.6x0.3, underwater speed 0.1, flees players within 6 blocks.
 * Loot (loot_tables/entities/fish.json): 1 raw cod (cooked when burning), 25% bone.
 */
class Cod extends AbstractFish{

	public static function getNetworkTypeId() : string{ return EntityIds::COD; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.3, 0.6, 0.2);
	}

	public function getName() : string{
		return "Cod";
	}

	protected function getSwimSpeed() : float{
		return 0.1;
	}

	protected function getBucketItem() : Item{
		return VanillaItems::COD_BUCKET();
	}

	protected function getFishDrops() : array{
		return [$this->isOnFire() ? VanillaItems::COOKED_FISH() : VanillaItems::RAW_FISH()];
	}
}
