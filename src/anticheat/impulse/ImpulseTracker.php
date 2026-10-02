<?php

declare(strict_types=1);

namespace pocketmine\anticheat\impulse;

use pocketmine\math\Vector3;
use function array_pop;
use function array_shift;
use function count;
use function microtime;

final class ImpulseTracker{
	private const MAX_PENDING = 16;
	private const ACTIVE_WINDOW_SECONDS = 1.5;
	private const MIN_MAGNITUDE_SQUARED = 0.000001;

	/** @var list<ExpectedImpulse> */
	private array $pending = [];

	/** @var list<string> */
	private array $sourceStack = [];

	public function pushSource(string $source) : void{
		$this->sourceStack[] = $source;
	}

	public function popSource() : void{
		if(count($this->sourceStack) > 0){
			array_pop($this->sourceStack);
		}
	}

	public function record(Vector3 $motion, Vector3 $origin, int $serverTick, int $protocolId, ?int $clientTickAtSend) : void{
		if($motion->lengthSquared() < self::MIN_MAGNITUDE_SQUARED){
			return;
		}

		$source = count($this->sourceStack) > 0 ? $this->sourceStack[count($this->sourceStack) - 1] : "server_motion";
		$this->pending[] = new ExpectedImpulse(
			$motion->x,
			$motion->y,
			$motion->z,
			$origin->x,
			$origin->y,
			$origin->z,
			$source,
			$serverTick,
			$protocolId,
			$clientTickAtSend,
			microtime(true)
		);

		if(count($this->pending) > self::MAX_PENDING){
			array_shift($this->pending);
		}
	}

	public function onTeleport() : void{
		$this->pending = [];
		$this->sourceStack = [];
	}

	public function hasActiveImpulse() : bool{
		$this->pruneExpired();
		return count($this->pending) > 0;
	}

	public function getOldestPending() : ?ExpectedImpulse{
		$this->pruneExpired();
		return $this->pending[0] ?? null;
	}

	public function discardOldest() : void{
		$this->pruneExpired();
		if(count($this->pending) > 0){
			array_shift($this->pending);
		}
	}

	/**
	 * @return list<ExpectedImpulse>
	 */
	public function getPending() : array{
		$this->pruneExpired();
		return $this->pending;
	}

	public function clear() : void{
		$this->pending = [];
		$this->sourceStack = [];
	}

	private function pruneExpired() : void{
		$now = microtime(true);
		while(count($this->pending) > 0 && ($now - $this->pending[0]->createdAt) > self::ACTIVE_WINDOW_SECONDS){
			array_shift($this->pending);
		}
	}
}
