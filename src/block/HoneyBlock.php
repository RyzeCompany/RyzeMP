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

namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;

/**
 * Honey block. Entities falling on it only take 20% of the fall damage, like in vanilla.
 * Players are slowed down, jump lower and slide down its sides on their own (the client knows the block); there is no
 * server-side slowdown of other entities, and PocketMine has no pistons to stick it to.
 */
final class HoneyBlock extends Transparent{

	public function getFrictionFactor() : float{
		return 0.8; //from the vanilla block properties table
	}

	protected function recalculateCollisionBoxes() : array{
		//1 pixel smaller on every side but the bottom, so entities touch its sides and slide down them
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 1 / 16)->trim(Facing::UP, 1 / 16)];
	}

	public function onEntityLand(Entity $entity) : ?float{
		//vanilla multiplies the fall damage by 0.2: keep the 3 safe blocks and scale the rest of the distance
		$fallDistance = $entity->getFallDistance();
		if($fallDistance > 3){
			$entity->setFallDistance(3 + ($fallDistance - 3) * 0.2);
		}
		return null;
	}
}
