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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;

/**
 * Walks away from a dangerous entity when it comes closer than $distance (vanilla behavior.avoid_mob_type: evokers
 * keeping their distance from players, piglins fleeing zombified piglins).
 */
class FleeEntityGoal extends Goal{
	private ?Entity $danger = null;

	/**
	 * @phpstan-param \Closure() : ?Entity $finder returns the entity to flee from, if any
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $finder,
		protected float $distance,
		protected float $speedMultiplier
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$danger = ($this->finder)();
		if($danger === null || (Mob::getLiveLocationOf($danger) ?? $danger->getLocation())->distanceSquared($this->mob->getLiveLocation()) > $this->distance ** 2){
			return false;
		}
		$this->danger = $danger;
		return true;
	}

	public function canContinue() : bool{
		return $this->danger !== null && !$this->danger->isClosed() && !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		if($this->danger === null){
			return;
		}
		$pos = RandomPos::getLandPos($this->mob, 8, 4, $this->danger->getPosition());
		if($pos !== null){
			$this->mob->getNavigation()->moveTo($pos->x + 0.5, $pos->y, $pos->z + 0.5, $this->speedMultiplier);
		}
	}

	public function stop() : void{
		$this->danger = null;
		$this->mob->getNavigation()->stop();
	}
}
