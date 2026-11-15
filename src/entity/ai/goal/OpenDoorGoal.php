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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\ai\villager\DoorInteraction;
use pocketmine\entity\Mob;

/**
 * Opens the wooden doors on the mob's path and closes them behind it (vanilla minecraft:annotation.open_door and
 * navigation can_open_doors: piglins, piglin brutes, copper golems, evokers, zombified piglins; see
 * {@link DoorInteraction}). Its paths go through closed doors (this sets
 * {@link \pocketmine\entity\ai\navigation\PathFinder::setCanOpenDoors()}).
 *
 * Runs only while a door is in the next nodes of the mob's path or it still has doors to close behind it, and holds
 * no control flag.
 */
final class OpenDoorGoal extends Goal{
	private DoorInteraction $doors;

	public function __construct(Mob $mob){
		$this->doors = new DoorInteraction($mob);
		$mob->getNavigation()->getPathFinder()->setCanOpenDoors(true);
	}

	public function canStart() : bool{
		return $this->doors->hasWork();
	}

	public function tick() : void{
		$this->doors->tick();
	}
}
