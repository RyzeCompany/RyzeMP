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
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\data\runtime\RuntimeDataDescriber;

class RedstoneLamp extends Opaque implements PoweredByRedstone, Lightable{
	use PoweredByRedstoneTrait;

	public function onNearbyBlockChange() : void{
		$power = RedstoneSignal::getReceivedPower($this);
		if($power > 0 && !$this->powered){
			if(RedstoneSignal::filterChange($this, 0, 15) > 0){
				$this->position->getWorld()->setBlock($this->position, $this->setPowered(true));
			}
		}elseif($power === 0 && $this->powered){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 4);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->powered && RedstoneSignal::getReceivedPower($this) === 0 && RedstoneSignal::filterChange($this, 15, 0) === 0){
			$this->position->getWorld()->setBlock($this->position, $this->setPowered(false));
		}
	}

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
}

