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

namespace pocketmine\entity\ai\raid;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Raider;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use function mt_rand;

/**
 * Patrol movement (vanilla pillager.json): the captain walks to a random block up to 512 blocks away at 0.55x speed
 * (behavior.move_to_random_block), picking a new one when it gets within 8 blocks; the other members follow the
 * nearest captain within 64 blocks, staying about 5 blocks from it at 0.8x speed (behavior.follow_target_captain).
 * Long walks are split in steps of {@link RaidMoveGoal::STEP} blocks.
 */
class PatrolGoal extends Goal{
	public const TARGET_RANGE = 512;
	public const LEADER_SPEED = 0.55;
	public const FOLLOWER_SPEED = 0.8;
	public const FOLLOW_RANGE = 64.0;
	public const FOLLOW_DISTANCE = 5.0;
	private const REACHED_DISTANCE = 8.0;
	private const COOLDOWN_TICKS = 200;

	private ?Vector3 $step = null;
	private int $cooldownUntil = 0;

	public function __construct(
		protected Mob&Raider $mob
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if(!$mob->isPatrolling() || $mob->getRaidId() !== null || $mob->getAttackTarget() !== null || $mob->getAiTick() < $this->cooldownUntil){
			return false;
		}
		$pos = $mob->getLiveLocation();
		if($mob->isPatrolLeader()){
			$target = $mob->getPatrolTarget();
			if($target === null || ($pos->x - $target->x) ** 2 + ($pos->z - $target->z) ** 2 <= self::REACHED_DISTANCE ** 2){
				$target = new Vector3($pos->x + mt_rand(-self::TARGET_RANGE, self::TARGET_RANGE), $pos->y, $pos->z + mt_rand(-self::TARGET_RANGE, self::TARGET_RANGE));
				$mob->setPatrolTarget($target);
			}
		}else{
			$leader = $this->findLeader();
			if($leader === null || $leader->getLocation()->distanceSquared($pos) <= self::FOLLOW_DISTANCE ** 2){
				return false;
			}
			$target = $leader->getLocation();
		}
		$this->step = RaidMoveGoal::stepTowards($mob, $target, RaidMoveGoal::STEP);
		if($this->step === null){
			//blocked: wander for a while, and let the captain pick another target
			$this->cooldownUntil = $mob->getAiTick() + self::COOLDOWN_TICKS;
			if($mob->isPatrolLeader()){
				$mob->setPatrolTarget(null);
			}
			return false;
		}
		return true;
	}

	public function canContinue() : bool{
		return $this->mob->isPatrolling() && $this->mob->getRaidId() === null && $this->mob->getAttackTarget() === null && !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		if($this->step !== null){
			$this->mob->getNavigation()->moveTo($this->step->x, $this->step->y, $this->step->z, $this->mob->isPatrolLeader() ? self::LEADER_SPEED : self::FOLLOWER_SPEED, 1.0);
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Returns the nearest patrol captain within {@link PatrolGoal::FOLLOW_RANGE} blocks.
	 */
	private function findLeader() : ?Mob{
		$pos = $this->mob->getLiveLocation();
		$r = self::FOLLOW_RANGE;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($pos->x - $r, $pos->y - 16, $pos->z - $r, $pos->x + $r, $pos->y + 16, $pos->z + $r), $this->mob) as $entity){
			if($entity instanceof Raider && $entity instanceof Mob && $entity->isAlive() && $entity->isPatrolLeader() && $entity->getRaidId() === null){
				$d = $entity->getLocation()->distanceSquared($pos);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}
}
