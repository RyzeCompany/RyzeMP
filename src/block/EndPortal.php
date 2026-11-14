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
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\PortalTravel;

/**
 * The inside of an activated End portal: takes entities that fall in to the End (or back to the Overworld from the
 * End) at once.
 */
class EndPortal extends Transparent{

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
		//vanilla only teleports entities that reach the lower 3/4 of the block
		$box = AxisAlignedBB::one()->trim(Facing::UP, 0.25)->offset($this->position->x, $this->position->y, $this->position->z);
		if($entity->getBoundingBox()->intersectsWith($box)){
			PortalTravel::getInstance()->onEntityInsidePortal($entity, Dimension::END, $this->position);
		}
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
