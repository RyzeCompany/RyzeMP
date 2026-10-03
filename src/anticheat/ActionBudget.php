<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use function max;
use function microtime;
use function min;

/**
 * Small token-bucket limiter used by anti-cheat checks.
 *
 * This is intentionally independent from the network packet limiter: packet
 * batches may be valid while still containing an impossible amount of one
 * particular gameplay action.
 */
final class ActionBudget{
	private float $tokens;
	private float $lastRefill;

	public function __construct(
		private readonly float $capacity,
		private readonly float $refillPerSecond
	){
		$this->tokens = $capacity;
		$this->lastRefill = microtime(true);
	}

	public function consume(float $cost = 1.0) : bool{
		$now = microtime(true);
		$elapsed = max(0.0, $now - $this->lastRefill);
		$this->lastRefill = $now;
		$this->tokens = min($this->capacity, $this->tokens + ($elapsed * $this->refillPerSecond));

		if($this->tokens < $cost){
			return false;
		}

		$this->tokens -= $cost;
		return true;
	}

	public function reset() : void{
		$this->tokens = $this->capacity;
		$this->lastRefill = microtime(true);
	}
}
