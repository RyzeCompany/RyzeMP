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
use function intdiv;
use function max;
use function mt_rand;

/**
 * Walks to a random spot nearby from time to time (vanilla behavior.random_stroll).
 */
class RandomStrollGoal extends Goal{
	private float $x = 0.0;
	private float $y = 0.0;
	private float $z = 0.0;

	/**
	 * @param int $interval average number of ticks between two strolls (vanilla 120)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected int $interval = 120,
		protected int $horizontalRange = 10,
		protected int $verticalRange = 7
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if(mt_rand(0, max(1, intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL)) - 1) !== 0){
			return false;
		}
		$pos = $this->mob->findWanderPos($this->horizontalRange, $this->verticalRange);
		if($pos === null){
			return false;
		}
		$this->x = $pos->x;
		$this->y = $pos->y;
		$this->z = $pos->z;
		return true;
	}

	public function canContinue() : bool{
		return !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		$this->mob->getNavigation()->moveTo($this->x, $this->y, $this->z, $this->speedMultiplier);
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}
}
