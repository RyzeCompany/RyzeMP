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
use function floor;
use function max;
use function mt_rand;

/**
 * Walks (or swims) to a block of some kind found nearby while the mob is somewhere it doesn't want to be: out of
 * water, in water, off lava (vanilla behavior.move_to_water, move_to_land, move_to_lava and move_to_liquid). The
 * search only runs while the mob wants to move, and at most once every 10-20 seconds (Java MoveToBlockGoal), the
 * first one right away. The goal ends when the mob is where it wanted to be (being within $goalRadius of the block
 * is not enough: a wide mob stops at its edge), after 60 seconds, when the path found doesn't lead there (fenced in
 * water...), or when it can't get any closer.
 */
abstract class MoveToBlockGoal extends Goal{
	/** Ticks between two searches, plus up to as many at random. */
	public const SEARCH_INTERVAL = 200;
	/** Longest run of the goal. */
	public const MAX_TICKS = 1200;
	/** Ticks between two path requests while the mob isn't there yet. */
	private const REPATH_TICKS = 40;
	/** Path requests in a row which found nothing before the goal gives up. */
	private const MAX_FAILED_PATHS = 3;
	/** Horizontal distance to the block from which the mob walks straight to its middle once its path ended. */
	private const FINAL_APPROACH_DISTANCE = 2.0;
	/** Ticks of walking straight to the block (see FINAL_APPROACH_DISTANCE) after which the goal gives up. */
	private const MAX_FINAL_APPROACH_TICKS = 60;

	private int $nextSearchTick = 0;
	private ?Vector3 $target = null;
	private int $ticks = 0;
	private int $failedPaths = 0;
	private int $finalApproachTicks = 0;
	/** Whether the path asked for by the last moveToTarget() was checked for reaching the block. */
	private bool $pathChecked = false;
	private ?MoveToBlockGoal $opposite = null;

	/**
	 * @phpstan-param (\Closure() : bool)|null $condition extra condition for the goal to start and keep running
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected int $searchRange = 16,
		protected int $searchHeight = 5,
		protected float $goalRadius = 0.5,
		protected ?\Closure $condition = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	/**
	 * Whether the mob wants to go somewhere else (out of water for move_to_water...). Called on every goal evaluation
	 * while the goal can start: must be cheap.
	 */
	abstract protected function wantsToMove() : bool;

	/**
	 * Returns the block to go to (the position of the mob's feet there), or null.
	 */
	abstract protected function findTarget(int $x, int $y, int $z) : ?Vector3;

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->nextSearchTick || !$this->wantsToMove() || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		$this->nextSearchTick = $mob->getAiTick() + self::SEARCH_INTERVAL + mt_rand(0, self::SEARCH_INTERVAL - 1);
		$loc = $mob->getLiveLocation();
		$this->target = $this->findTarget((int) floor($loc->x), (int) floor($loc->y + 0.1), (int) floor($loc->z));
		return $this->target !== null;
	}

	/**
	 * Links two goals of a mob which take it to opposite places (move_to_water and move_to_land): once one of them got
	 * the mob there, the other one waits a full search interval, so the mob stays a while instead of going straight back.
	 */
	public function linkOpposite(MoveToBlockGoal $other) : void{
		$this->opposite = $other;
		$other->opposite = $this;
	}

	/**
	 * Makes the next search happen right away (tests, plugins).
	 */
	public function resetSearchDelay() : void{
		$this->nextSearchTick = 0;
	}

	/**
	 * Returns the block the mob is going to, null when the goal isn't running.
	 */
	public function getTarget() : ?Vector3{
		return $this->target;
	}

	public function canContinue() : bool{
		$target = $this->target;
		if($target === null || $this->ticks >= self::MAX_TICKS || !$this->wantsToMove() || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		if(!$this->mob->getNavigation()->isDone()){
			return true;
		}
		//the path ended: tick() asks for a new path, or walks straight in. Within the goal radius the mob isn't there
		//yet either (it still wants to move): a wide mob's path ends at the edge of the block, a turtle on the shore
		//with half its body in the water. Ending here left it standing there until the next search (10-20 s)
		return true;
	}

	public function start() : void{
		$this->ticks = 0;
		$this->failedPaths = 0;
		$this->finalApproachTicks = 0;
		$this->moveToTarget();
	}

	public function stop() : void{
		$opposite = $this->opposite;
		if($opposite !== null && $this->target !== null && !$this->wantsToMove()){
			//got there: stay a while
			$opposite->nextSearchTick = max($opposite->nextSearchTick, $this->mob->getAiTick() + self::SEARCH_INTERVAL + mt_rand(0, self::SEARCH_INTERVAL - 1));
		}
		$this->target = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		++$this->ticks;
		$target = $this->target;
		if($target === null){
			return;
		}
		$navigation = $this->mob->getNavigation();
		if(!$navigation->isDone()){
			$path = $navigation->getPath();
			//the path to the block (not an older one still followed until the search ran)
			if(!$this->pathChecked && $path !== null && $path->getTargetY() === (int) floor($target->y) && abs($path->getTargetX() - $target->x) <= 1 && abs($path->getTargetZ() - $target->z) <= 1){
				$this->pathChecked = true;
				$end = $path->getEnd();
				if(!$path->reachesTarget() && $end !== null && ($end[0] + $navigation->getNodeOffset() - $target->x - 0.5) ** 2 + ($end[2] + $navigation->getNodeOffset() - $target->z - 0.5) ** 2 >= self::FINAL_APPROACH_DISTANCE ** 2){
					//no way there (fenced in, across a wall): don't walk towards it for nothing
					$this->target = null;
				}
			}
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$dx = $target->x + 0.5 - $loc->x;
		$dz = $target->z + 0.5 - $loc->z;
		if($dx * $dx + $dz * $dz < self::FINAL_APPROACH_DISTANCE ** 2){
			//the path ends next to the block (path nodes of mobs wider than a block are on block edges): straight there
			if(++$this->finalApproachTicks > self::MAX_FINAL_APPROACH_TICKS){
				//stuck next to it (a wall, a block in the way)
				$this->target = null;
				return;
			}
			$this->mob->getMoveControl()->setWantedPosition($target->x + 0.5, $target->y, $target->z + 0.5, $this->speedMultiplier);
			return;
		}
		if($this->ticks % self::REPATH_TICKS === 0){
			//the path ended (or none was found) before the mob got there
			if(++$this->failedPaths >= self::MAX_FAILED_PATHS){
				$this->target = null;
				return;
			}
			$this->moveToTarget();
		}
	}

	private function moveToTarget() : void{
		$target = $this->target;
		$this->pathChecked = false;
		if($target !== null && !$this->mob->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, $this->speedMultiplier)){
			++$this->failedPaths;
		}
	}
}
