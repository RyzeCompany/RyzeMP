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

use pocketmine\entity\ai\hostile\FreeMoveControl;
use pocketmine\entity\Mob;
use function sqrt;

/**
 * Brings a mob that is outside its home restriction (see {@link \pocketmine\entity\HomeTrait}) back towards its home
 * (vanilla behavior.move_towards_home_restriction, Java MoveTowardsRestrictionGoal): it heads for a point up to
 * {@link MoveTowardsHomeRestrictionGoal::STEP} blocks towards the home, which is again inside the radius once the mob
 * is no farther than that, and starts over from there if it is still outside.
 *
 * Mobs moving with a navigation walk, fly or swim there with it; free swimmers (guardians) pass their
 * {@link FreeMoveControl} and swim straight there.
 * Checking whether it may start is one distance comparison (evaluated every few ticks like every goal).
 */
class MoveTowardsHomeRestrictionGoal extends Goal{
	/** Blocks towards home of each leg (Java DefaultRandomPos.getPosTowards(mob, 16, 7, ...)). */
	public const STEP = 16.0;
	/** Ticks after which a leg is given up (stuck against a wall). */
	private const TIMEOUT = 200;

	private int $ticks = 0;
	private int $retryTick = 0;

	/**
	 * @param float                $speedMultiplier vanilla speed_multiplier (1.0)
	 * @param FreeMoveControl|null $control         the control of a free swimmer, null for mobs using their navigation
	 * @param float                $controlSpeed    speed in blocks per tick of the free swimmer at multiplier 1
	 * @param int                  $retryCooldown   ticks to wait after a leg before the next one (a mob that can't get
	 *                                              back doesn't search paths over and over), 0 for none
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected ?FreeMoveControl $control = null,
		protected float $controlSpeed = 0.1,
		protected int $retryCooldown = 0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if($this->mob->getHomeRadius() <= 0 || ($this->retryCooldown > 0 && $this->mob->getAiTick() < $this->retryTick)){
			return false;
		}
		$loc = $this->mob->getLiveLocation();
		return !$this->mob->isWithinHome($loc->x, $loc->y, $loc->z);
	}

	public function canContinue() : bool{
		if(++$this->ticks > self::TIMEOUT || $this->mob->getHomePos() === null){
			return false;
		}
		return $this->control !== null ? $this->control->hasWanted() : !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		$this->ticks = 0;
		$home = $this->mob->getHomePos();
		if($home === null){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$x = $home->x + 0.5;
		$y = $home->y;
		$z = $home->z + 0.5;
		$dx = $x - $loc->x;
		$dy = $y - $loc->y;
		$dz = $z - $loc->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance > self::STEP){
			$f = self::STEP / $distance;
			$x = $loc->x + $dx * $f;
			$y = $loc->y + $dy * $f;
			$z = $loc->z + $dz * $f;
		}
		if($this->control !== null){
			$this->control->setWantedPosition($x, $y, $z, $this->controlSpeed * $this->speedMultiplier);
		}else{
			$this->mob->getNavigation()->moveTo($x, $y, $z, $this->speedMultiplier);
		}
	}

	public function stop() : void{
		if($this->control !== null){
			$this->control->stop();
		}else{
			$this->mob->getNavigation()->stop();
		}
		if($this->retryCooldown > 0){
			$this->retryTick = $this->mob->getAiTick() + $this->retryCooldown;
		}
	}
}
