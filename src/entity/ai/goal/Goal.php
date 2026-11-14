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

/**
 * A behaviour of a mob (wander, panic, attack...). Goals are run by a {@link GoalSelector}: a goal starts when
 * {@link Goal::canStart()} returns true and no running goal with a higher priority holds any of its control flags,
 * and stops when {@link Goal::canContinue()} returns false or a more important goal needs one of its flags.
 */
abstract class Goal{
	/** The goal moves the mob (pathfinding, move control). */
	public const FLAG_MOVE = 1;
	/** The goal turns the mob's head (look control). */
	public const FLAG_LOOK = 2;
	/** The goal makes the mob jump. */
	public const FLAG_JUMP = 4;
	/** The goal selects the mob's attack target. */
	public const FLAG_TARGET = 8;

	/**
	 * Returns a bitmask of the FLAG_* constants this goal needs exclusively while it runs.
	 */
	public function getFlags() : int{
		return 0;
	}

	/**
	 * Returns whether the goal should start now. Called only every few ticks (see {@link GoalSelector}), so it may do
	 * moderately expensive checks.
	 */
	abstract public function canStart() : bool;

	/**
	 * Returns whether the running goal should keep running. Called every AI tick.
	 */
	public function canContinue() : bool{
		return $this->canStart();
	}

	/**
	 * Returns whether a goal with a higher priority may stop this goal to take its flags.
	 */
	public function isInterruptible() : bool{
		return true;
	}

	public function start() : void{

	}

	public function stop() : void{

	}

	/**
	 * Called every AI tick while the goal runs.
	 */
	public function tick() : void{

	}
}
