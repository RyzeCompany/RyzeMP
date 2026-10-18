<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\NetherPortal;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\world\dimension\Dimensions;
use pocketmine\world\dimension\portal\block\EndPortal;
use pocketmine\world\World;
use function floor;
use function max;
use function min;

/** Validates an exact linked arrival without moving it or modifying terrain. */
final class LinkedPortalArrival{

	public static function boundingBox(Entity $entity, PortalDestination $destination) : AxisAlignedBB{
		$position = $entity->getPosition();
		$box = clone $entity->getBoundingBox();
		$box->offset($destination->x - $position->x, $destination->y - $position->y, $destination->z - $position->z);
		return $box;
	}

	public static function isWithinBounds(Entity $entity, World $world, PortalDestination $destination, int $dimension) : bool{
		$box = self::boundingBox($entity, $destination);
		return $box->minY >= max($world->getMinY(), Dimensions::getMinY($dimension)) + 1 &&
			$box->maxY <= min($world->getMaxY(), Dimensions::getMaxY($dimension));
	}

	/** Requires the full entity footprint to have safe support, with air or portal blocks around its body. */
	public static function isSafe(Entity $entity, World $world, PortalDestination $destination, int $dimension) : bool{
		if(!self::isWithinBounds($entity, $world, $destination, $dimension)){
			return false;
		}
		$box = self::boundingBox($entity, $destination);
		$floorY = (int) floor($box->minY - 0.001);
		// A fractional feet height would otherwise hide a gap above the floor.
		if($box->minY - $floorY > 1.001){
			return false;
		}
		for($x = (int) floor($box->minX + 0.001); $x <= (int) floor($box->maxX - 0.001); ++$x){
			for($z = (int) floor($box->minZ + 0.001); $z <= (int) floor($box->maxZ - 0.001); ++$z){
				$floor = $world->getBlockAt($x, $floorY, $z);
				if(!$floor->isFullCube() || $floor->getTypeId() === BlockTypeIds::MAGMA){
					return false;
				}
				for($y = (int) floor($box->minY + 0.001); $y <= (int) floor($box->maxY - 0.001); ++$y){
					$block = $world->getBlockAt($x, $y, $z);
					if($block->getTypeId() !== BlockTypeIds::AIR && !$block instanceof NetherPortal && !$block instanceof EndPortal){
						return false;
					}
				}
			}
		}
		return true;
	}
}
