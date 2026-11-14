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

use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\Mob;
use pocketmine\player\Player;
use function intdiv;
use function max;
use function mt_rand;

/**
 * Targets the nearest survival/adventure player in range (vanilla behavior.nearest_attackable_target). Looking for a
 * target only happens on average once every $interval ticks (vanilla random interval: 10; Bedrock attack_interval), and
 * the target is dropped after it has been out of sight for $forgetTicks (when $mustSee) or once it is farther than the
 * mob follows targets: $maxDistance, or the mob's follow range ({@link Mob::getFollowRange()}) if larger.
 *
 * Invisible players are only noticed from closer (see {@link Sensing::canDetect()}).
 */
class NearestAttackableTargetGoal extends Goal{
	/** Ticks an unseen target is remembered (Bedrock must_see_forget_duration default: 3 seconds). */
	public const DEFAULT_FORGET_TICKS = 60;

	private ?Player $target = null;
	private int $unseenTicks = 0;
	private float $keepRange = 0.0;
	private int $reselectTicks = 0;

	/**
	 * @param int        $forgetTicks ticks an unseen target is remembered (Bedrock must_see_forget_duration)
	 * @param bool       $reselect    whether a closer target replaces the current one, looked for every $interval ticks
	 *                                (Bedrock reselect_targets)
	 * @param float|null $maxDistance how far targets are followed when farther than $range (Bedrock entity_types max_dist
	 *                                with a smaller within_radius, e.g. zombies notice players within 25 blocks and follow
	 *                                them up to 35)
	 *
	 * @phpstan-param (\Closure() : bool)|null $condition extra condition for the mob to look for targets (e.g. spiders only in the dark)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $range = 16.0,
		protected bool $mustSee = true,
		protected int $interval = 10,
		protected ?\Closure $condition = null,
		protected int $forgetTicks = self::DEFAULT_FORGET_TICKS,
		protected bool $reselect = false,
		protected ?float $maxDistance = null
	){}

	public function getFlags() : int{
		return self::FLAG_TARGET;
	}

	public function canStart() : bool{
		$evaluations = intdiv($this->interval, Mob::GOAL_EVALUATION_INTERVAL);
		if($evaluations > 1 && mt_rand(0, $evaluations - 1) !== 0){
			return false;
		}
		if($this->condition !== null && !($this->condition)()){
			return false;
		}
		$this->target = $this->findTarget();
		return $this->target !== null;
	}

	/**
	 * Returns the nearest player the mob notices, other than $current and at most $maxDistanceSquared away when given.
	 */
	private function findTarget(?Player $current = null, ?float $maxDistanceSquared = null) : ?Player{
		$sensing = $this->mob->getSensing();
		return $sensing->getNearestPlayer($this->range, fn(Player $p) => $p !== $current && Sensing::isTargetablePlayer($p) && (!$this->mustSee || $sensing->canSee($p)), true, $maxDistanceSquared);
	}

	public function canContinue() : bool{
		$target = $this->mob->getAttackTarget();
		if($target === null || $target !== $this->target || !$this->mob->getSensing()->isValidTarget($target, $this->keepRange)){
			return false;
		}
		if($this->mustSee){
			if($this->mob->getSensing()->canSee($target)){
				$this->unseenTicks = 0;
			}elseif(++$this->unseenTicks > $this->forgetTicks){
				return false;
			}
		}
		//counted down on goal evaluation ticks only: within the AI budget, with fresh line of sight results
		if($this->reselect && $this->mob->getSensing()->isEvaluationTick() && ($this->reselectTicks -= Mob::GOAL_EVALUATION_INTERVAL) <= 0){
			$this->reselectTicks = max($this->reselectTicks + $this->interval, 0);
			$this->reselectCloser($target);
		}
		return true;
	}

	/**
	 * Switches to the nearest target if it is closer than the current one (Bedrock reselect_targets). Only closer
	 * players are looked at.
	 */
	private function reselectCloser(Player $current) : void{
		if($this->condition !== null && !($this->condition)()){
			return;
		}
		$loc = $this->mob->getLiveLocation();
		$currentDistance = (Mob::getLiveLocationOf($current) ?? $current->getLocation())->distanceSquared($loc);
		$closer = $this->findTarget($current, $currentDistance);
		if($closer !== null && (Mob::getLiveLocationOf($closer) ?? $closer->getLocation())->distanceSquared($loc) < $currentDistance){
			$this->target = $closer;
			$this->unseenTicks = 0;
			$this->mob->setAttackTarget($closer);
		}
	}

	public function start() : void{
		$this->mob->setAttackTarget($this->target);
		$this->unseenTicks = 0;
		$this->reselectTicks = $this->interval;
		$this->keepRange = max($this->maxDistance ?? $this->range, $this->mob->getFollowRange());
	}

	public function stop() : void{
		if($this->mob->getAttackTarget() === $this->target){
			$this->mob->setAttackTarget(null);
		}
		$this->target = null;
	}
}
