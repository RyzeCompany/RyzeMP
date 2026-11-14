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

use pocketmine\block\utils\SupportType;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\world\dimension\EndGatewayTravel;

/**
 * End gateway: the beam block in a small bedrock frame that appears in the End each time the ender dragon is killed
 * (and on the outer islands). Entities that enter it (and the owners of ender pearls thrown into it) are taken to
 * its exit, see {@link EndGatewayTravel}. The exit is kept in its block entity ({@link tile\EndGateway}).
 */
class EndGateway extends Transparent{

	public function getLightLevel() : int{
		return 15;
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		EndGatewayTravel::getInstance()->onEntityInsideGateway($entity, $this->position);
		return true;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function canBeReplaced() : bool{
		return false;
	}

	public function getDrops(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}
}
