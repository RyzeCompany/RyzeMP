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

namespace pocketmine\entity\ai;

use function count;
use function ksort;
use function min;
use const PHP_INT_MIN;

/**
 * @internal
 * A number of units of work per tick (see {@link AiBudget}) handed out oldest request first, instead of to whoever asks
 * first on each tick: entities always ask in the same order (the world's update order), so in a crowd the same late
 * ones would never get any.
 *
 * A refused request comes back on the next ticks with the tick it was first refused on (its "waiting since" tick). Each
 * tick, the requests refused on the previous tick that waited longest, up to the budget, are served before anything
 * else: the budget is reserved for them until they come back, and newer requests only get what is left. Requests
 * refused on the same tick are served in the order they ask, so every request is served after at most
 * (requests waiting before it) / budget ticks.
 */
final class FairTickBudget{
	private int $tick = -1;
	private int $taken = 0;
	/**
	 * Requests refused on the current tick, by the tick they wait since.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $refused = [];
	/** Requests waiting since this tick or earlier are served first on the current tick (PHP_INT_MIN: no queue). */
	private int $cutoff = PHP_INT_MIN;
	/** Units kept on the current tick for the served requests that haven't come back yet. */
	private int $reserved = 0;

	public function __construct(
		private int $max
	){}

	public function getMax() : int{
		return $this->max;
	}

	/**
	 * Sets the budget per tick and forgets the requests waiting.
	 */
	public function reset(int $max) : void{
		$this->max = $max;
		$this->tick = -1;
		$this->taken = 0;
		$this->refused = [];
		$this->cutoff = PHP_INT_MIN;
		$this->reserved = 0;
	}

	/**
	 * Takes one unit of this tick's budget. Returns false if the request must wait: the caller then asks again on a
	 * later tick with $waitingSince set to the tick of the first refusal (-1 for a request not refused yet).
	 */
	public function tryTake(int $currentTick, int $waitingSince = -1) : bool{
		if($currentTick !== $this->tick){
			$this->startTick($currentTick);
		}
		if($waitingSince >= 0 && $waitingSince <= $this->cutoff){
			//one of the requests waiting longest
			if($this->reserved > 0){
				--$this->reserved;
			}
			if($this->taken < $this->max){
				++$this->taken;
				return true;
			}
		}elseif($this->taken + $this->reserved < $this->max){
			++$this->taken;
			return true;
		}
		$since = $waitingSince >= 0 ? $waitingSince : $currentTick;
		$this->refused[$since] = ($this->refused[$since] ?? 0) + 1;
		return false;
	}

	/**
	 * Gives back a unit taken on the current tick by a request that couldn't use it (another budget refused it): the
	 * request counts as refused, it keeps its place in the queue with $waitingSince (see {@link FairTickBudget::tryTake()}).
	 */
	public function refund(int $currentTick, int $waitingSince = -1) : void{
		if($currentTick !== $this->tick || $this->taken === 0){
			return;
		}
		--$this->taken;
		$since = $waitingSince >= 0 ? $waitingSince : $currentTick;
		$this->refused[$since] = ($this->refused[$since] ?? 0) + 1;
	}

	private function startTick(int $currentTick) : void{
		$refused = $this->refused;
		$consecutive = $currentTick === $this->tick + 1;
		$this->tick = $currentTick;
		$this->taken = 0;
		$this->reserved = 0;
		$this->cutoff = PHP_INT_MIN;
		if(count($refused) === 0){
			return;
		}
		$this->refused = [];
		if(!$consecutive){
			//nobody asked on the previous tick: the requests refused before have gone
			return;
		}
		ksort($refused);
		$waiting = 0;
		foreach($refused as $since => $requests){
			$waiting += $requests;
			$this->cutoff = $since;
			if($waiting >= $this->max){
				break;
			}
		}
		$this->reserved = min($waiting, $this->max);
	}
}
