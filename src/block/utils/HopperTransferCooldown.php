<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

/**
 * Keeps hopper transfer intervals in server ticks, not in invocation counts.
 *
 * An early neighbour update may call the hopper many times during one tick.
 * None of those calls should shorten the transfer cooldown. A saved cooldown
 * is stored as remaining ticks, so it can be restored across a server restart
 * without persisting an absolute server tick number.
 */
final class HopperTransferCooldown{
	private ?int $nextAllowedTick = null;
	private int $pendingDelay = 0;

	public function __construct(
		private int $intervalTicks
	){
		if($intervalTicks < 1){
			throw new \InvalidArgumentException("Hopper transfer interval must be positive");
		}
	}

	public function canTransfer(int $currentTick) : bool{
		if($this->nextAllowedTick === null){
			$this->nextAllowedTick = $currentTick + $this->pendingDelay;
			$this->pendingDelay = 0;
		}

		return $currentTick >= $this->nextAllowedTick;
	}

	public function markTransferred(int $currentTick) : void{
		$this->nextAllowedTick = $currentTick + $this->intervalTicks;
		$this->pendingDelay = 0;
	}

	public function getRemainingTicks(int $currentTick) : int{
		return $this->nextAllowedTick === null ?
			$this->pendingDelay :
			max(0, $this->nextAllowedTick - $currentTick);
	}

	public function loadRemainingTicks(int $remainingTicks) : void{
		$this->nextAllowedTick = null;
		$this->pendingDelay = max(0, min($this->intervalTicks, $remainingTicks));
	}
}
