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

use function count;
use function spl_object_id;
use function usort;

/**
 * Runs a set of prioritised goals (lower number = more important), giving each control flag (move, look, jump,
 * target) to at most one running goal.
 *
 * Starting goals is the expensive part (every candidate's canStart() is checked), so it only happens every
 * {@link GoalSelector::$evaluateInterval} ticks; running goals are ticked every time.
 */
final class GoalSelector{

	/**
	 * @var PrioritizedGoal[]
	 * @phpstan-var list<PrioritizedGoal>
	 */
	private array $goals = [];

	/**
	 * Running goals by object ID.
	 * @var PrioritizedGoal[]
	 * @phpstan-var array<int, PrioritizedGoal>
	 */
	private array $running = [];

	/**
	 * Control flag => goal holding it.
	 * @var PrioritizedGoal[]
	 * @phpstan-var array<int, PrioritizedGoal>
	 */
	private array $flagOwners = [];

	/** Bitmask of flags no goal may take (e.g. FLAG_MOVE while the mob is ridden). */
	private int $disabledFlags = 0;
	/** Bitmask of the flags held by running goals. */
	private int $heldFlags = 0;

	private int $ticker;

	public function __construct(
		private int $evaluateInterval = 2,
		int $offset = 0
	){
		$this->ticker = $offset;
	}

	public function addGoal(int $priority, Goal $goal) : void{
		$this->goals[] = new PrioritizedGoal($priority, $goal);
		usort($this->goals, fn(PrioritizedGoal $a, PrioritizedGoal $b) => $a->priority <=> $b->priority);
	}

	public function removeGoal(Goal $goal) : void{
		$kept = [];
		foreach($this->goals as $entry){
			if($entry->goal === $goal){
				if($entry->running){
					$this->stopGoal($entry);
				}
			}else{
				$kept[] = $entry;
			}
		}
		$this->goals = $kept;
	}

	/**
	 * @return Goal[]
	 * @phpstan-return list<Goal>
	 */
	public function getRunningGoals() : array{
		$result = [];
		foreach($this->running as $entry){
			$result[] = $entry->goal;
		}
		return $result;
	}

	public function isRunning(Goal $goal) : bool{
		foreach($this->running as $entry){
			if($entry->goal === $goal){
				return true;
			}
		}
		return false;
	}

	public function setFlagDisabled(int $flag, bool $disabled) : void{
		if($disabled){
			$this->disabledFlags |= $flag;
		}else{
			$this->disabledFlags &= ~$flag;
		}
	}

	/**
	 * Stops all running goals.
	 */
	public function stopAll() : void{
		foreach($this->running as $entry){
			$this->stopGoal($entry);
		}
	}

	/**
	 * @param bool $evaluate whether new goals may start on this tick (false when the mob is over the AI budget)
	 */
	public function tick(bool $evaluate = true) : void{
		//stop goals which no longer want to run or whose flags were disabled
		foreach($this->running as $entry){
			if(($entry->flags & $this->disabledFlags) !== 0 || !$entry->goal->canContinue()){
				$this->stopGoal($entry);
			}
		}

		if($evaluate && ++$this->ticker >= $this->evaluateInterval){
			$this->ticker = 0;
			foreach($this->goals as $entry){
				if($entry->running || ($entry->flags & $this->disabledFlags) !== 0){
					continue;
				}
				//most candidates need no flag held by a running goal. For those that do: whether each of these flags can be
				//taken from the goal holding it (a less important one which can be interrupted), written out here (every
				//evaluation of every mob, for each candidate wanting a flag held by a running goal)
				$flags = $entry->flags & $this->heldFlags;
				for($flag = 1; $flags !== 0; $flag <<= 1){
					if(($flags & $flag) === 0){
						continue;
					}
					$flags &= ~$flag;
					$owner = $this->flagOwners[$flag] ?? null;
					if($owner !== null && ($owner->priority <= $entry->priority || !$owner->goal->isInterruptible())){
						continue 2;
					}
				}
				if($entry->goal->canStart()){
					$this->startGoal($entry);
				}
			}
		}

		foreach($this->running as $entry){
			$entry->goal->tick();
		}
	}

	private function startGoal(PrioritizedGoal $entry) : void{
		$flags = $entry->flags;
		for($flag = 1; $flags !== 0; $flag <<= 1){
			if(($flags & $flag) === 0){
				continue;
			}
			$flags &= ~$flag;
			$owner = $this->flagOwners[$flag] ?? null;
			if($owner !== null){
				$this->stopGoal($owner);
			}
			$this->flagOwners[$flag] = $entry;
		}
		$this->heldFlags |= $entry->flags;
		$entry->running = true;
		$this->running[spl_object_id($entry)] = $entry;
		$entry->goal->start();
	}

	private function stopGoal(PrioritizedGoal $entry) : void{
		if(!$entry->running){
			return;
		}
		$entry->running = false;
		unset($this->running[spl_object_id($entry)]);
		foreach($this->flagOwners as $flag => $owner){
			if($owner === $entry){
				unset($this->flagOwners[$flag]);
				$this->heldFlags &= ~$flag;
			}
		}
		$entry->goal->stop();
	}

	public function count() : int{
		return count($this->goals);
	}
}
