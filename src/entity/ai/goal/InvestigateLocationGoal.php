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
use pocketmine\math\Vector3;

/**
 * Walks to a suspicious location (vanilla behavior.investigate_suspicious_location): the location comes from the
 * callback (e.g. where the warden last heard a vibration) and is forgotten once reached or unreachable.
 */
class InvestigateLocationGoal extends Goal{
	private const ACCEPT_DISTANCE = 1.5;
	private const MAX_TICKS = 400;

	private ?Vector3 $location = null;
	private int $ticks = 0;

	/**
	 * @phpstan-param \Closure() : ?Vector3 $getLocation
	 * @phpstan-param \Closure(Vector3) : void $onDone called when the location was reached or given up
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected \Closure $getLocation,
		protected \Closure $onDone
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return ($this->getLocation)() !== null && $this->mob->getAttackTarget() === null;
	}

	public function canContinue() : bool{
		$current = ($this->getLocation)();
		return $current !== null && $this->location !== null && $this->ticks < self::MAX_TICKS && $this->mob->getAttackTarget() === null;
	}

	public function start() : void{
		$this->ticks = 0;
		$this->location = ($this->getLocation)();
		if($this->location !== null && !$this->mob->getNavigation()->moveTo($this->location->x, $this->location->y, $this->location->z, $this->speedMultiplier, self::ACCEPT_DISTANCE)){
			$this->finish();
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
		$this->location = null;
	}

	private function finish() : void{
		if($this->location !== null){
			($this->onDone)($this->location);
		}
		$this->location = null;
	}

	public function tick() : void{
		++$this->ticks;
		$current = ($this->getLocation)();
		if($current !== null && $this->location !== null && !$current->equals($this->location)){
			//a newer disturbance: go there instead
			$this->location = $current;
			if(!$this->mob->getNavigation()->moveTo($current->x, $current->y, $current->z, $this->speedMultiplier, self::ACCEPT_DISTANCE)){
				$this->finish();
				return;
			}
		}
		if($this->location === null){
			return;
		}
		$this->mob->getLookControl()->setLookAt($this->location->x, $this->location->y + 1, $this->location->z);
		if($this->mob->getNavigation()->isDone()){
			$this->finish();
		}
	}
}
