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

use pocketmine\entity\Mob;

/**
 * A ridden mob walks towards the attack target of its rider (vanilla behavior.mount_pathing, "track_target"): the
 * spider of a spider jockey, the chicken of a chicken jockey...
 */
class MountPathingGoal extends Goal{
	private int $repathDelay = 0;

	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.25
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$rider = $this->mob->getControllingPassenger();
		return $rider instanceof Mob && $rider->getAttackTarget() !== null;
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$rider = $this->mob->getControllingPassenger();
		$target = $rider instanceof Mob ? $rider->getAttackTarget() : null;
		if($target !== null && --$this->repathDelay <= 0){
			$this->repathDelay = 10;
			$this->mob->getNavigation()->moveToEntity($target, $this->speedMultiplier, 0.0);
		}
	}
}
