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

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\math\Facing;
use function count;
use function min;

class TrappedChest extends Chest{

	public function isRedstoneSource() : bool{
		return true;
	}

	/**
	 * The output is the number of players looking into the chest (both halves of a double chest share it).
	 */
	public function getWeakPower(int $face) : int{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileChest ? min(15, count($tile->getInventory()->getViewers())) : 0;
	}

	public function getStrongPower(int $face) : int{
		return $face === Facing::DOWN ? $this->getWeakPower($face) : 0;
	}

	/**
	 * Called when a player opens or closes the chest.
	 */
	public function onViewerCountChange() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$positions = [$this->position];
		$tile = $world->getTile($this->position);
		if($tile instanceof TileChest && ($pair = $tile->getPair()) !== null){
			$positions[] = $pair->getPosition();
		}
		foreach($positions as $pos){
			$redstone->updateAround($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			$redstone->updateAroundSide($pos, Facing::DOWN);
		}
	}
}
