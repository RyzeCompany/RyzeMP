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

use pocketmine\block\utils\Lightable;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;

class RedstoneLamp extends Opaque implements PoweredByRedstone, Lightable{
	use PoweredByRedstoneTrait;

	public const TURN_OFF_DELAY_TICKS = 4;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->powered);
	}

	public function getLightLevel() : int{
		return $this->powered ? 15 : 0;
	}

	public function isLit() : bool{
		return $this->powered;
	}

	/** @return $this */
	public function setLit(bool $lit = true) : self{
		$this->powered = $lit;
		return $this;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$powered = $redstone->isPowered($x, $y, $z);
		if($powered && !$this->powered){
			$this->powered = true;
			$world->setBlock($this->position, $this);
		}elseif(!$powered && $this->powered && !$world->isBlockUpdateScheduled($x, $y, $z)){
			//lamps turn off two redstone ticks after losing power
			$world->scheduleDelayedBlockUpdate($this->position, self::TURN_OFF_DELAY_TICKS);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if($this->powered && !$world->isBlockPowered($this->position)){
			$this->powered = false;
			$world->setBlock($this->position, $this);
		}
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
