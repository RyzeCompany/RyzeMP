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

namespace pocketmine\entity\monster;

use pocketmine\entity\Living;
use pocketmine\entity\Mob;

/**
 * Helpers for mobs turning into other mobs (zombie -> drowned, husk -> zombie, piglin -> zombified piglin, hoglin ->
 * zoglin, zombie villager -> villager): what the new mob keeps from the old one (vanilla transformation component).
 */
final class MobConversion{

	private function __construct(){
		//NOOP
	}

	/**
	 * Copies the name, persistence and armor of $from to $to (vanilla "keep_level"/"preserve_equipment" keeps the
	 * worn equipment; held items are handled by the caller).
	 */
	public static function copyState(Living $from, Living $to) : void{
		if($from->getNameTag() !== ""){
			$to->setNameTag($from->getNameTag());
			$to->setNameTagAlwaysVisible($from->isNameTagAlwaysVisible());
		}
		if($from instanceof Mob && $to instanceof Mob){
			$to->setPersistent($from->isPersistent());
		}
		$to->getArmorInventory()->setContents($from->getArmorInventory()->getContents());
		$to->setRotation($from->getLocation()->yaw, $from->getLocation()->pitch);
	}

	/**
	 * Replaces $from by $to after a lightning strike (pig -> zombified piglin, villager -> witch): the new mob keeps
	 * the state of the old one (see {@link MobConversion::copyState()}), never despawns (vanilla
	 * setPersistenceRequired) and the old one's riders get off.
	 */
	public static function convertByLightning(Living $from, Living $to) : void{
		self::copyState($from, $to);
		if($to instanceof Mob){
			$to->setPersistent(true);
		}
		foreach($from->getPassengers() as $passenger){
			$passenger->dismount(true);
		}
		$to->spawnToAll();
		$from->flagForDespawn();
	}
}
