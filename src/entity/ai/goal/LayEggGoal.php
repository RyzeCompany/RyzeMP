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
use function abs;

/**
 * A pregnant animal walks to a suitable block and lays its eggs there (vanilla behavior.lay_egg: frogs lay frogspawn
 * on water, turtles lay eggs on sand). The callbacks decide where and what.
 */
class LayEggGoal extends Goal{
	private ?Vector3 $target = null;
	private int $ticks = 0;
	private int $searchCooldown = 0;

	/**
	 * @phpstan-param \Closure() : bool $isPregnant
	 * @phpstan-param \Closure() : ?Vector3 $findTarget returns the position of the block where the eggs go
	 * @phpstan-param \Closure(Vector3) : bool $lay places the eggs; returns false if it can't (the block changed)
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $isPregnant,
		protected \Closure $findTarget,
		protected \Closure $lay,
		protected float $speedMultiplier = 1.0,
		protected float $reach = 1.7
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if(!($this->isPregnant)()){
			return false;
		}
		if(--$this->searchCooldown > 0){
			return false;
		}
		$this->searchCooldown = 10;
		$this->target = ($this->findTarget)();
		return $this->target !== null;
	}

	public function canContinue() : bool{
		return $this->target !== null && ($this->isPregnant)() && $this->ticks < 600;
	}

	public function start() : void{
		$this->ticks = 0;
	}

	public function stop() : void{
		$this->target = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){
			return;
		}
		++$this->ticks;
		$loc = $this->mob->getLiveLocation();
		$dx = $target->x + 0.5 - $loc->x;
		$dz = $target->z + 0.5 - $loc->z;
		if($dx * $dx + $dz * $dz <= $this->reach * $this->reach && abs($target->y - $loc->y) < 2.5){
			if(!($this->lay)($target)){
				$this->target = null;
			}
			return;
		}
		if($this->ticks % 20 === 1){
			$this->mob->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, $this->speedMultiplier, 1.0);
		}
	}
}
