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

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function abs;
use function mt_rand;

class Moss extends Opaque{
	protected const VERTICAL_RANGE = 5;

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!($item instanceof Fertilizer) || $this->getSide(Facing::UP)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}

		$item->pop();

		$world = $this->position->getWorld();
		$maxX = $this->getHorizontalRadius();
		$maxZ = $this->getHorizontalRadius();
		$originX = $this->position->getFloorX();
		$originY = $this->position->getFloorY();
		$originZ = $this->position->getFloorZ();

		for($dx = -$maxX; $dx <= $maxX; ++$dx){
			for($dz = -$maxZ; $dz <= $maxZ; ++$dz){
				if(
					(abs($dx) === $maxX && abs($dz) === $maxZ) ||
					((abs($dx) === $maxX || abs($dz) === $maxZ) && mt_rand(1, 100) > 75)
				){
					continue;
				}

				$foundBlock = null;
				if($dx !== 0 || $dz !== 0){
					$x = $originX + $dx;
					$z = $originZ + $dz;
					$startY = $originY + 1;
					$direction = $world->getBlockAt($x, $startY, $z)->getTypeId() === BlockTypeIds::AIR ? -1 : 1;
					$limit = $direction === -1 ? $originY - self::VERTICAL_RANGE : $originY + self::VERTICAL_RANGE;

					for($y = $startY; $direction === -1 ? $y >= $limit : $y <= $limit; $y += $direction){
						$b = $world->getBlockAt($x, $y, $z);
						if($b->getTypeId() !== BlockTypeIds::AIR && $world->getBlockAt($x, $y + 1, $z)->getTypeId() === BlockTypeIds::AIR){
							$foundBlock = $b;
							break;
						}
					}
				}else{
					$foundBlock = $this;
				}

				if(
					$foundBlock !== null && $foundBlock->hasTypeTag(BlockTypeTags::MOSS_REPLACEABLE) &&
					($foundBlock->hasSameTypeId($this) || BlockEventHelper::spread($foundBlock, (clone $this), $this)) &&
					mt_rand(1, 100) <= 60
				){
					$above = $foundBlock->getPosition();
					$this->generateVegetation($above->getFloorX(), $above->getFloorY() + 1, $above->getFloorZ(), $player);
				}
			}
		}

		return true;
	}

	protected function getHorizontalRadius() : int{
		return mt_rand(0, 1) === 0 ? 2 : 3;
	}

	protected function generateVegetation(int $x, int $y, int $z, ?Player $player) : void{
		$rand = mt_rand(1, 10000);
		if($rand <= 5208){
			$this->placeVegetation($x, $y, $z, VanillaBlocks::TALL_GRASS(), $player);
		}elseif($rand <= 7812){
			$this->placeVegetation($x, $y, $z, VanillaBlocks::MOSS_CARPET(), $player);
		}elseif($rand <= 8854){
			$this->placeDoubleTallGrass($x, $y, $z, $player);
		}elseif($rand <= 9583){
			$this->placeVegetation($x, $y, $z, VanillaBlocks::AZALEA(), $player);
		}else{
			$this->placeVegetation($x, $y, $z, VanillaBlocks::FLOWERING_AZALEA(), $player);
		}
	}

	/**
	 * Places a plant only into air, through BlockGrowEvent so that protection plugins can stop it.
	 */
	protected function placeVegetation(int $x, int $y, int $z, Block $plant, ?Player $player) : bool{
		$world = $this->position->getWorld();
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$old = $world->getBlockAt($x, $y, $z);
		return $old->getTypeId() === BlockTypeIds::AIR && BlockEventHelper::grow($old, $plant, $player);
	}

	protected function placeDoubleTallGrass(int $x, int $y, int $z, ?Player $player) : void{
		$world = $this->position->getWorld();
		if(!$world->isInWorld($x, $y + 1, $z) || $world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		if($this->placeVegetation($x, $y, $z, VanillaBlocks::DOUBLE_TALLGRASS(), $player)){
			$world->setBlockAt($x, $y + 1, $z, VanillaBlocks::DOUBLE_TALLGRASS()->setTop(true));
		}
	}

	public function getCompostabilityChance() : int{ return 65; }
}
