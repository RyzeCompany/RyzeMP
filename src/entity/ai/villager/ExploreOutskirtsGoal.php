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

namespace pocketmine\entity\ai\villager;

use pocketmine\entity\ai\raid\RaidMoveGoal;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\math\Vector3;
use function cos;
use function max;
use function min;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * During wander time villagers walk to the outskirts of their village and look around there (vanilla
 * behavior.explore_outskirts in wander_schedule_villager: priority 9, speed 0.6, explore_dist 6, max_wait_time 10 s,
 * min_dist_from_target 2.5; defaults max_travel_time 60 s, next_xz 5, next_y 3, timer_ratio 2).
 *
 * The village edge is the dwelling radius around the villager's bed (see
 * {@link \pocketmine\entity\npc\VillagerV2::getHomeRadius()}): the villager heads for a random point within 6 blocks
 * inside it, in legs of {@link RaidMoveGoal::STEP} blocks, then explores spots up to 5 blocks around, waiting up to 10
 * seconds at each, for twice the time the trip took. Villagers without a bed don't explore. A leg the villager can't
 * walk (no path, or a partial one that leaves it where it was: pens, iron farms, walled villages) ends the trip, like a
 * failed path ends vanilla goals; the next exploration then waits {@link ExploreOutskirtsGoal::COOLDOWN_MAX} ticks, twice
 * as long after two failed ones in a row and four times after three or more, so that villagers which can't leave their
 * pen don't keep searching paths out of it.
 */
class ExploreOutskirtsGoal extends VillagerGoal{
	public const SPEED = 0.6;
	public const EXPLORE_DISTANCE = 6;
	private const MAX_TRAVEL_TICKS = 1200;
	private const MAX_WAIT_TICKS = 200;
	private const REACHED_DISTANCE = 2.5;
	private const NEXT_XZ = 5;
	private const NEXT_Y = 3;
	private const TIMER_RATIO = 2;
	/** Ticks between two explorations. */
	private const COOLDOWN_MIN = 400;
	private const COOLDOWN_MAX = 1200;
	/** A leg ending farther than this from its end point... */
	private const LEG_REACHED_DISTANCE = 2.0;
	/** ...after moving the villager less than this failed. */
	private const LEG_MIN_PROGRESS = 1.5;
	/** Highest power of two of the wait after failed explorations in a row. */
	private const MAX_FAILED_SHIFT = 2;

	private ?Vector3 $target = null;
	private bool $exploring = false;
	private int $startTick = 0;
	private int $endTick = 0;
	private int $nextMove = 0;
	private int $nextTry = 0;
	/** Whether a leg was requested and hasn't been checked yet. */
	private bool $onLeg = false;
	private float $legStartX = 0.0;
	private float $legStartZ = 0.0;
	private float $legEndX = 0.0;
	private float $legEndZ = 0.0;
	/** Explorations in a row that couldn't get to their target. */
	private int $failures = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextTry){
			return false;
		}
		$this->nextTry = $now + mt_rand(self::COOLDOWN_MIN, self::COOLDOWN_MAX);
		$bed = $this->villager->getBedPosition();
		if($bed === null || $this->villager->isBaby() || $this->villager->getActivity() !== VillagerActivity::WANDER){
			return false;
		}
		$radius = $this->villager->getHomeRadius();
		$distance = mt_rand(max(1, $radius - self::EXPLORE_DISTANCE), max(1, $radius - 1));
		$angle = mt_rand(0, 359) * M_PI / 180;
		$this->target = new Vector3($bed->x + 0.5 + cos($angle) * $distance, $bed->y, $bed->z + 0.5 + sin($angle) * $distance);
		return true;
	}

	public function canContinue() : bool{
		return $this->target !== null && $this->villager->getAiTick() < $this->endTick && $this->villager->getActivity() === VillagerActivity::WANDER;
	}

	public function start() : void{
		$now = $this->villager->getAiTick();
		$this->exploring = false;
		$this->startTick = $now;
		$this->endTick = $now + self::MAX_TRAVEL_TICKS;
		$this->nextMove = $now;
		$this->onLeg = false;
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){
			return;
		}
		$now = $this->villager->getAiTick();
		$navigation = $this->villager->getNavigation();
		if(!$this->exploring){
			$loc = $this->villager->getLiveLocation();
			if(($loc->x - $target->x) ** 2 + ($loc->z - $target->z) ** 2 <= self::REACHED_DISTANCE ** 2){
				//arrived: explore around for twice the time the trip took
				$this->exploring = true;
				$this->failures = 0;
				$this->endTick = $now + min(self::MAX_TRAVEL_TICKS, ($now - $this->startTick) * self::TIMER_RATIO) + 1;
				$this->nextMove = $now + mt_rand(0, self::MAX_WAIT_TICKS);
				$navigation->stop();
				return;
			}
			if(!$navigation->isDone()){
				return;
			}
			if($this->onLeg){
				$this->onLeg = false;
				if(
					($loc->x - $this->legEndX) ** 2 + ($loc->z - $this->legEndZ) ** 2 > self::LEG_REACHED_DISTANCE ** 2 &&
					($loc->x - $this->legStartX) ** 2 + ($loc->z - $this->legStartZ) ** 2 < self::LEG_MIN_PROGRESS ** 2
				){
					$this->giveUp($now); //no way there
					return;
				}
			}
			if($now >= $this->nextMove){
				$this->nextMove = $now + 20;
				$step = RaidMoveGoal::stepTowards($this->villager, $target, RaidMoveGoal::STEP);
				if($step === null || !$navigation->moveTo($step->x, $step->y, $step->z, self::SPEED, 1.0)){
					$this->giveUp($now); //nowhere to stand on the way, or no path to this leg a moment ago
					return;
				}
				$this->onLeg = true;
				$this->legStartX = $loc->x;
				$this->legStartZ = $loc->z;
				$this->legEndX = $step->x;
				$this->legEndZ = $step->z;
			}
			return;
		}
		if($navigation->isDone() && $now >= $this->nextMove){
			$pos = $this->villager->findWanderPos(self::NEXT_XZ, self::NEXT_Y);
			if($pos !== null){
				$navigation->moveTo($pos->x, $pos->y, $pos->z, self::SPEED);
			}
			$this->nextMove = $now + mt_rand(20, self::MAX_WAIT_TICKS);
		}
	}

	private function giveUp(int $now) : void{
		$this->target = null;
		$this->nextTry = max($this->nextTry, $now + (self::COOLDOWN_MAX << min($this->failures, self::MAX_FAILED_SHIFT)));
		++$this->failures;
	}

	public function stop() : void{
		$this->target = null;
		$this->exploring = false;
		$this->villager->getNavigation()->stop();
	}
}
