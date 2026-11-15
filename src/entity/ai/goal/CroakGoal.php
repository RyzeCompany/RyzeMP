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
use function mt_rand;

/**
 * Stands still and croaks from time to time (vanilla behavior.croak, frogs: every 10-20 seconds for 4.5 seconds, not
 * in water or lava, CROAKING flag). The mob stands still while it croaks (it stops goals of a lower priority, like
 * strolling).
 */
class CroakGoal extends Goal{
	/** -1: not worked out yet (the first croak comes 10-20 seconds after the mob starts thinking) */
	private int $nextCroakTick = -1;
	private int $endTick = 0;

	/**
	 * @phpstan-param \Closure(bool) : void $onCroakingChanged called when the croak starts (true) and ends (false)
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $onCroakingChanged,
		protected int $minIntervalTicks = 200,
		protected int $maxIntervalTicks = 400,
		protected int $durationTicks = 90
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($this->nextCroakTick === -1){
			$this->nextCroakTick = $mob->getAiTick() + mt_rand($this->minIntervalTicks, $this->maxIntervalTicks);
		}
		return $mob->getAiTick() >= $this->nextCroakTick && $mob->isOnGround() && !$mob->isInWater() && !$mob->isInLava();
	}

	public function canContinue() : bool{
		return $this->mob->getAiTick() < $this->endTick && !$this->mob->isInWater() && !$this->mob->isInLava();
	}

	/**
	 * Makes the mob croak at the next goal evaluation it can (tests, plugins).
	 */
	public function resetInterval() : void{
		$this->nextCroakTick = 0;
	}

	public function start() : void{
		$this->endTick = $this->mob->getAiTick() + $this->durationTicks;
		$this->mob->getNavigation()->stop();
		($this->onCroakingChanged)(true);
	}

	public function stop() : void{
		$this->nextCroakTick = $this->mob->getAiTick() + mt_rand($this->minIntervalTicks, $this->maxIntervalTicks);
		($this->onCroakingChanged)(false);
	}
}
