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

namespace pocketmine;

use function max;
use function min;

/**
 * Decides the view distance cap while the main thread is short of memory (see memory.max-chunks.chunk-radius).
 *
 * Every low memory trigger lowers the cap by {@link MemoryPressure::STEP_DOWN} chunks, down to the configured floor.
 * The cap is kept after the memory goes back under the limit: only after {@link MemoryPressure::RECOVERY_CHECKS}
 * consecutive memory checks under the recovery level is it raised by one chunk (and removed once it reaches the
 * server's view distance). Removing the cap as soon as the memory is under the limit, like before, made the radius
 * jump between the floor and the full view distance: every jump unloads and resends thousands of chunks.
 *
 * @internal
 */
final class MemoryPressure{
	public const STEP_DOWN = 2;
	public const RECOVERY_CHECKS = 60;

	/** 0 = no cap */
	private int $cap = 0;
	private int $calmChecks = 0;

	/**
	 * @param int $floor smallest cap (memory.max-chunks.chunk-radius); 0 or less disables the cap
	 */
	public function __construct(
		private int $floor
	){}

	public function getCap() : int{
		return $this->cap;
	}

	/**
	 * Returns the view distance allowed for the given distance with the current cap.
	 */
	public function capViewDistance(int $distance) : int{
		return $this->cap > 0 ? min($this->cap, $distance) : $distance;
	}

	/**
	 * Called on every low memory trigger (the first one and the continuous ones).
	 *
	 * @param int $maxViewDistance the server's view distance (the largest any player can have)
	 * @return bool whether the cap changed
	 */
	public function onLowMemory(int $maxViewDistance) : bool{
		$this->calmChecks = 0;
		if($this->floor <= 0){
			return false;
		}
		$current = $this->cap > 0 ? $this->cap : $maxViewDistance;
		$new = max(min($this->floor, $maxViewDistance), $current - self::STEP_DOWN);
		if($new >= $maxViewDistance){
			return false; //the floor is the view distance already: nothing to lower
		}
		if($new === $this->cap){
			return false;
		}
		$this->cap = $new;
		return true;
	}

	/**
	 * Called on every memory check while the memory is under the limit.
	 *
	 * @param bool $belowRecoveryLevel whether the memory is well under the limit (see MemoryManager)
	 * @return bool whether the cap changed
	 */
	public function onMemoryCheck(bool $belowRecoveryLevel, int $maxViewDistance) : bool{
		if($this->cap === 0){
			return false;
		}
		if(!$belowRecoveryLevel){
			$this->calmChecks = 0;
			return false;
		}
		if(++$this->calmChecks < self::RECOVERY_CHECKS){
			return false;
		}
		$this->calmChecks = 0;
		$this->cap++;
		if($this->cap >= $maxViewDistance){
			$this->cap = 0;
		}
		return true;
	}
}
