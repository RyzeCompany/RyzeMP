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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;

/**
 * Walks away from a block the mob is afraid of (vanilla behavior.avoid_block: hoglins avoiding warped fungus, portals
 * and respawn anchors, piglins avoiding soul fire). The finder looks for the block and should cache its result, since
 * it is asked every goal evaluation.
 */
class FleeBlockGoal extends Goal{
	private const MAX_TICKS = 100;

	private ?Vector3 $danger = null;
	private int $ticks = 0;

	/**
	 * @phpstan-param \Closure() : ?Vector3 $finder returns the position of the nearest block to avoid, if any
	 * @phpstan-param (\Closure() : void)|null $onFlee called when the mob starts walking away
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $finder,
		protected float $speedMultiplier,
		protected ?\Closure $onFlee = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$this->danger = ($this->finder)();
		return $this->danger !== null;
	}

	public function canContinue() : bool{
		//give up after a while (stuck on the way): a new escape route is picked on the next evaluation
		return $this->danger !== null && !$this->mob->getNavigation()->isDone() && ++$this->ticks < self::MAX_TICKS;
	}

	public function start() : void{
		$this->ticks = 0;
		if($this->danger === null){
			return;
		}
		$pos = RandomPos::getLandPos($this->mob, 10, 4, $this->danger->add(0.5, 0, 0.5));
		if($pos !== null && $this->mob->getNavigation()->moveTo($pos->x + 0.5, $pos->y, $pos->z + 0.5, $this->speedMultiplier)){
			if($this->onFlee !== null){
				($this->onFlee)();
			}
		}else{
			$this->danger = null;
		}
	}

	public function stop() : void{
		$this->danger = null;
		$this->mob->getNavigation()->stop();
	}
}
