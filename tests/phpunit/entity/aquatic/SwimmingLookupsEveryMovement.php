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

use pocketmine\entity\Entity;
use pocketmine\entity\Mob;

/**
 * The block lookups of {@link Mob} after every movement, without the water kept by
 * {@link \pocketmine\entity\ai\SwimmingMobTrait} (see {@link SwimmingWaterCacheEquivalenceTest}).
 */
trait SwimmingLookupsEveryMovement{
	protected function getBlocksAroundWithEntityInsideActions() : array{
		return Entity::getBlocksAroundWithEntityInsideActions();
	}

	protected function checkBlockIntersections() : void{
		Mob::checkBlockIntersections();
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		Mob::move($dx, $dy, $dz);
	}

	public function onNearbyBlockChange() : void{
		Entity::onNearbyBlockChange();
	}
}
