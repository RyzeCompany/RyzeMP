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

use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;

/**
 * Follows the nearest other mob within the search range, stopping $stopDistance blocks away (vanilla
 * behavior.follow_mob: wild parrots, ghastlings). By default it follows any visible mob of another kind (Java
 * FollowMobGoal); a filter narrows it, and players can be preferred (Bedrock preferred_actor_type "player").
 *
 * The search runs on goal evaluations, and waits a second after finding nothing to follow, so idle mobs don't query
 * the world on every evaluation.
 */
class FollowMobGoal extends Goal{
	/** Ticks between searches when nothing was found. */
	private const SEARCH_COOLDOWN = 20;

	private ?Living $following = null;
	private int $searchCooldown = 0;
	private int $repathDelay = 0;

	/**
	 * @phpstan-param (\Closure(Living) : bool)|null $filter   the mobs (and players, if $includePlayers) it may follow
	 * @phpstan-param (\Closure() : bool)|null       $onlyWhen whether the mob follows anything now (before searching)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected float $stopDistance = 3.0,
		protected float $searchRange = 20.0,
		protected ?\Closure $filter = null,
		protected bool $includePlayers = false,
		protected ?\Closure $onlyWhen = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	/**
	 * Returns the mob being followed, if the goal is running.
	 */
	public function getFollowing() : ?Living{
		return $this->following;
	}

	private function accepts(Living $entity) : bool{
		if(!$entity->isAlive() || $entity->isClosed() || $entity === $this->mob){
			return false;
		}
		if($this->filter !== null){
			return ($this->filter)($entity);
		}
		return $entity::class !== $this->mob::class && !$entity->isInvisible();
	}

	private function findTarget() : ?Living{
		$loc = $this->mob->getLiveLocation();
		$stopSq = $this->stopDistance * $this->stopDistance;
		if($this->includePlayers){
			//preferred_actor_type player: the nearest player wins over any mob
			$player = $this->mob->getSensing()->getNearestPlayer($this->searchRange, fn(Player $p) => $this->accepts($p));
			if($player !== null){
				return $player->getLocation()->distanceSquared($loc) > $stopSq ? $player : null;
			}
		}
		$r = $this->searchRange;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this->mob) as $entity){
			if(!$entity instanceof Mob){
				continue;
			}
			//every mob around: its live location, not a copy (unless its class overrides getLocation())
			$d = (Mob::getLiveLocationOf($entity) ?? $entity->getLocation())->distanceSquared($loc);
			if($d < $bestDist && $this->accepts($entity)){
				$best = $entity;
				$bestDist = $d;
			}
		}
		//already close enough: nothing to do until it moves away
		return $best !== null && $bestDist > $stopSq ? $best : null;
	}

	public function canStart() : bool{
		if($this->searchCooldown > 0){
			$this->searchCooldown -= Mob::GOAL_EVALUATION_INTERVAL;
			return false;
		}
		if($this->mob->getVehicle() !== null || $this->mob->isLeashed() || ($this->onlyWhen !== null && !($this->onlyWhen)())){
			return false;
		}
		$target = $this->findTarget();
		if($target === null){
			$this->searchCooldown = self::SEARCH_COOLDOWN;
			return false;
		}
		$this->following = $target;
		return true;
	}

	public function canContinue() : bool{
		$target = $this->following;
		if($target === null || !$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->mob->getWorld() || $this->mob->isLeashed() || ($this->onlyWhen !== null && !($this->onlyWhen)())){
			return false;
		}
		$distanceSquared = $target->getLocation()->distanceSquared($this->mob->getLiveLocation());
		if($distanceSquared > ($this->searchRange * 2) ** 2){
			return false;
		}
		return $distanceSquared > $this->stopDistance ** 2 || !$this->mob->getNavigation()->isDone();
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->following = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$target = $this->following;
		if($target === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($target, 10.0, 40.0);
		if(--$this->repathDelay > 0){
			return;
		}
		$this->repathDelay = 10;
		if($target->getLocation()->distanceSquared($this->mob->getLiveLocation()) <= $this->stopDistance ** 2){
			$this->mob->getNavigation()->stop();
		}else{
			$this->mob->getNavigation()->moveToEntity($target, $this->speedMultiplier, $this->stopDistance);
		}
	}
}
