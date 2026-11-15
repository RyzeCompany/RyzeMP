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
use function intdiv;
use function max;
use function mt_rand;
use function sqrt;

/**
 * Goes back to the mob's home position (see {@link \pocketmine\entity\HomeTrait}) from time to time (vanilla
 * behavior.go_home): with a 1 in $interval chance per tick while farther than $goalRadius blocks from it, the mob
 * walks (swims, flies) there; once within $goalRadius $onHome runs (Bedrock on_home). A mob that is already home when
 * the goal gets its chance runs $onHome straight away.
 *
 * A home farther than {@link GoHomeGoal::LEG_LENGTH} blocks is reached in legs of that length towards it (a turtle
 * swims back to the beach it hatched on from hundreds of blocks away): each leg is one bounded path search. When a leg
 * ends without getting the mob at least a block closer (no way there), the goal fails: $onFailed runs (Bedrock
 * on_failed) and the goal waits for its next chance.
 */
class GoHomeGoal extends Goal{
	/** Blocks of each leg towards a distant home. */
	public const LEG_LENGTH = 24.0;
	/** Ticks after which a leg is given up. */
	private const LEG_TIMEOUT = 400;

	private bool $finished = false;
	private int $legTicks = 0;
	private float $legStartDistance = 0.0;

	/**
	 * @param int           $interval   vanilla interval: 1 in $interval chance per tick to start (default 120)
	 * @param float         $goalRadius vanilla goal_radius: distance from the home that counts as home
	 * @param \Closure|null $onHome     called when the mob got home
	 * @param \Closure|null $onFailed   called when the mob can't get any closer
	 * @param \Closure|null $condition  the goal only runs while this returns true (e.g. while pregnant)
	 *
	 * @phpstan-param (\Closure() : void)|null $onHome
	 * @phpstan-param (\Closure() : void)|null $onFailed
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected int $interval = 120,
		protected float $goalRadius = 0.5,
		protected ?\Closure $onHome = null,
		protected ?\Closure $onFailed = null,
		protected ?\Closure $condition = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	/**
	 * Returns the distance from the mob to the middle of the home block, or null without a home.
	 */
	private function getHomeDistance() : ?float{
		$loc = $this->mob->getLiveLocation();
		$distanceSquared = $this->mob->getHomeDistanceSquared($loc->x, $loc->y, $loc->z);
		return $distanceSquared !== null ? sqrt($distanceSquared) : null;
	}

	public function canStart() : bool{
		if($this->mob->getHomePos() === null || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		if($this->interval > 1 && mt_rand(0, max(1, intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL)) - 1) !== 0){
			return false;
		}
		$distance = $this->getHomeDistance();
		if($distance === null){
			return false;
		}
		if($distance <= $this->goalRadius){
			//already home: nothing to walk, but on_home still fires (a turtle bred on its home beach lays there)
			if($this->onHome !== null){
				($this->onHome)();
			}
			return false;
		}
		return true;
	}

	public function canContinue() : bool{
		return !$this->finished && $this->mob->getHomePos() !== null && ($this->condition === null || ($this->condition)());
	}

	public function start() : void{
		$this->finished = false;
		$this->startLeg();
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Heads for the home, or for a point {@link GoHomeGoal::LEG_LENGTH} blocks towards it.
	 */
	private function startLeg() : void{
		$home = $this->mob->getHomePos();
		$distance = $this->getHomeDistance();
		if($home === null || $distance === null){
			return;
		}
		$this->legTicks = 0;
		$this->legStartDistance = $distance;
		$x = $home->x + 0.5;
		$y = $home->y;
		$z = $home->z + 0.5;
		if($distance > self::LEG_LENGTH){
			$loc = $this->mob->getLiveLocation();
			$f = self::LEG_LENGTH / $distance;
			$x = $loc->x + ($x - $loc->x) * $f;
			$y = $loc->y + ($y - $loc->y) * $f;
			$z = $loc->z + ($z - $loc->z) * $f;
		}
		$this->mob->getNavigation()->moveTo($x, $y, $z, $this->speedMultiplier, $distance > self::LEG_LENGTH ? 2.0 : $this->goalRadius);
	}

	public function tick() : void{
		$distance = $this->getHomeDistance();
		if($distance === null){
			return;
		}
		if($distance <= $this->goalRadius){
			$this->finished = true;
			if($this->onHome !== null){
				($this->onHome)();
			}
			return;
		}
		if(!$this->mob->getNavigation()->isDone() && ++$this->legTicks < self::LEG_TIMEOUT){
			return;
		}
		if($distance < $this->legStartDistance - 1.0){
			//got closer: next leg
			$this->startLeg();
			return;
		}
		$this->finished = true;
		if($this->onFailed !== null){
			($this->onFailed)();
		}
	}
}
