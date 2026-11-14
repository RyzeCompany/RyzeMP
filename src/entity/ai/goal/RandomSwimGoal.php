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
 * Swims to a random spot in the water (vanilla behavior.random_swim). With interval 0 (fish) the mob starts a new swim
 * as soon as the previous one ends, so it never stops; when no spot is found (a small pond, out of the water) it
 * waits {@link RandomSwimGoal::RETRY_TICKS} ticks before trying again instead of searching on every evaluation.
 */
class RandomSwimGoal extends RandomStrollGoal{
	public const RETRY_TICKS = 10;

	private int $retryTick = 0;

	public function __construct(Mob $mob, float $speedMultiplier = 1.0, int $interval = 0, int $horizontalRange = 16, int $verticalRange = 4){
		parent::__construct($mob, $speedMultiplier, $interval, $horizontalRange, $verticalRange);
	}

	public function canStart() : bool{
		$now = $this->mob->getAiTick();
		if($now < $this->retryTick){
			return false;
		}
		if(!$this->mob->isInWater()){
			$this->retryTick = $now + self::RETRY_TICKS;
			return false;
		}
		if(parent::canStart()){
			return true;
		}
		if($this->interval <= Mob::GOAL_EVALUATION_INTERVAL){
			//no spot found (with a longer interval, the random interval roll fails most of the time)
			$this->retryTick = $now + self::RETRY_TICKS;
		}
		return false;
	}
}
