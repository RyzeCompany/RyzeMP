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

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use function ceil;
use function floor;
use function max;
use function mt_rand;

/**
 * Walks from under the open sky into the shade (vanilla behavior.find_cover of foxes: by day, and at once during
 * thunderstorms; Java FoxSeekShelterGoal): picks one of 10 random spots up to 10 blocks away horizontally and 3
 * vertically with no sky above it, and walks there.
 *
 * The goal tries at most once every $cooldown ticks (and at most once a second), and only while $condition allows it
 * and the mob sees the sky: a sky light lookup when it is already in the shade.
 */
class FindCoverGoal extends Goal{
	/** Tries, and the farthest spot horizontally and vertically (Java FleeSunGoal.getHidePos()) */
	private const TRIES = 10;
	private const HORIZONTAL = 10;
	private const VERTICAL = 3;
	/** Ticks a mob which found no shade waits before looking again */
	private const RETRY_TICKS = 20;

	private float $x = 0.0;
	private float $y = 0.0;
	private float $z = 0.0;
	private int $nextTry = 0;

	/**
	 * @phpstan-param \Closure() : bool $condition
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $condition,
		protected float $speedMultiplier = 1.0,
		protected int $cooldown = 100
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->nextTry){
			return false;
		}
		//the condition and the sky are checked at most once a second
		$this->nextTry = $mob->getAiTick() + max(self::RETRY_TICKS, $this->cooldown);
		if(!($this->condition)()){
			return false;
		}
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$eye = (int) floor($mob->getEyeHeight());
		$bx = (int) floor($loc->x);
		$by = (int) floor($loc->y + 0.01);
		$bz = (int) floor($loc->z);
		if(!FleeSunGoal::seesSky($world, $bx, $by + $eye, $bz)){
			return false;
		}
		$headBlocks = max(0, (int) ceil($mob->getSize()->getHeight()) - 1);
		for($try = 0; $try < self::TRIES; ++$try){
			$x = $bx + mt_rand(-self::HORIZONTAL, self::HORIZONTAL - 1);
			$y = $by + mt_rand(-self::VERTICAL, self::VERTICAL - 1);
			$z = $bz + mt_rand(-self::HORIZONTAL, self::HORIZONTAL - 1);
			if(FleeSunGoal::seesSky($world, $x, $y + $eye, $z)){
				continue;
			}
			//settle on the ground below the picked spot
			for($down = 0; $down < self::VERTICAL && !PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false); ++$down){
				--$y;
			}
			if(PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false) && !FleeSunGoal::seesSky($world, $x, $y + $eye, $z)){
				$this->x = $x + 0.5;
				$this->y = $y;
				$this->z = $z + 0.5;
				return true;
			}
		}
		return false;
	}

	public function canContinue() : bool{
		return !$this->mob->getNavigation()->isDone() && ($this->condition)();
	}

	public function start() : void{
		$this->mob->getNavigation()->moveTo($this->x, $this->y, $this->z, $this->speedMultiplier);
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}
}
