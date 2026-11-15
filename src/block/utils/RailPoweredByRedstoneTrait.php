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

namespace pocketmine\block\utils;

use pocketmine\block\BaseRail;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\math\Facing;
use pocketmine\world\redstone\RedstoneEngine;

/**
 * Powered and activator rails: powered directly, or through a line of up to 8 rails of the same type leading to a
 * directly powered one.
 */
trait RailPoweredByRedstoneTrait{
	use PoweredByRedstoneTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->powered);
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null || !$world->getBlock($this->position)->hasSameTypeId($this)){
			return;
		}
		$powered = $this->isReceivingPower($redstone);
		if($powered !== $this->powered){
			$this->powered = $powered;
			$world->setBlock($this->position, $this);
			//rails on slopes are not direct neighbours
			$redstone->updateAroundSide($this->position, Facing::UP);
			$redstone->updateAroundSide($this->position, Facing::DOWN);
		}
	}

	private function isReceivingPower(RedstoneEngine $redstone) : bool{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if($redstone->isPowered($x, $y, $z)){
			return true;
		}
		foreach($this->getCurrentShapeConnections() as $connection){
			if($this->searchPoweredRail($redstone, $x, $y, $z, $connection)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Follows the line of connected rails of this type starting from x/y/z in the given connection direction, and
	 * returns whether one of them is directly powered.
	 */
	private function searchPoweredRail(RedstoneEngine $redstone, int $x, int $y, int $z, int $connection) : bool{
		$world = $this->position->getWorld();
		for($i = 0; $i < RedstoneEngine::RAIL_POWER_RANGE; ++$i){
			$direction = $connection & ~RailConnectionInfo::FLAG_ASCEND;
			[$dx, , $dz] = Facing::OFFSET[$direction];
			$nx = $x + $dx;
			$nz = $z + $dz;
			$ny = $y + (($connection & RailConnectionInfo::FLAG_ASCEND) !== 0 ? 1 : 0);
			$next = $world->getBlockAt($nx, $ny, $nz);
			if(!$next->hasSameTypeId($this)){
				//the next rail may slope down towards us
				$ny = $y - 1;
				$next = $world->getBlockAt($nx, $ny, $nz);
				if(!$next->hasSameTypeId($this)){
					return false;
				}
			}
			if(!$next instanceof BaseRail){
				return false;
			}
			$back = Facing::opposite($direction);
			$connectedBack = false;
			$forward = null;
			foreach($next->getCurrentShapeConnections() as $nextConnection){
				if(($nextConnection & ~RailConnectionInfo::FLAG_ASCEND) === $back){
					$connectedBack = true;
				}else{
					$forward = $nextConnection;
				}
			}
			if(!$connectedBack){
				return false;
			}
			if($redstone->isPowered($nx, $ny, $nz)){
				return true;
			}
			if($forward === null){
				return false;
			}
			$x = $nx;
			$y = $ny;
			$z = $nz;
			$connection = $forward;
		}
		return false;
	}
}
