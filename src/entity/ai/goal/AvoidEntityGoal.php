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
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;

/**
 * Runs away from nearby entities accepted by the filter (vanilla behavior.avoid_mob_type: fish from players, rabbits
 * from wolves, villagers from zombies...): walks at $walkSpeed, and sprints at $sprintSpeed when the threat is
 * within 7 blocks. Spectators and creative players are ignored.
 */
class AvoidEntityGoal extends Goal{
	private const PLAYER_HEIGHT = 1.8;

	private ?Entity $threat = null;
	private int $checkCounter = 0;
	/** Y range of the player search ($playersOnly), set before each search. */
	private float $searchMinY = 0.0;
	private float $searchMaxY = 0.0;
	/** @phpstan-var (\Closure(Player) : bool)|null */
	private ?\Closure $playerFilter = null;

	/**
	 * @param bool $playersOnly whether only players are avoided (wild cats and ocelots): the world's players are looked
	 *                          at instead of the entities around the mob
	 *
	 * @phpstan-param \Closure(Entity) : bool $filter
	 * @phpstan-param (\Closure() : bool)|null $condition extra condition for the mob to look for threats at all (e.g. only
	 * while wild)
	 * @phpstan-param (\Closure() : bool)|null $onlyPlayersNow whether, for this search, the filter can only accept
	 * players (fish avoid players and axolotls: without axolotls around, only the world's players are looked at)
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $filter,
		protected float $maxDistance,
		protected float $walkSpeed = 1.0,
		protected float $sprintSpeed = 1.0,
		protected ?\Closure $condition = null,
		protected bool $playersOnly = false,
		protected ?\Closure $onlyPlayersNow = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if(++$this->checkCounter % 3 !== 0){
			return false; //look for threats every ~9 ticks
		}
		if($this->condition !== null && !($this->condition)()){
			return false;
		}
		$threat = $this->playersOnly || ($this->onlyPlayersNow !== null && ($this->onlyPlayersNow)()) ? $this->findPlayerThreat() : $this->findThreat();
		if($threat === null){
			return false;
		}
		$pos = $this->mob->findWanderPos(16, 7, $threat->getPosition());
		if($pos === null || $pos->distanceSquared($threat->getPosition()) < $threat->getPosition()->distanceSquared($this->mob->getLiveLocation())){
			return false;
		}
		$this->threat = $threat;
		$this->mob->getNavigation()->moveTo($pos->x, $pos->y, $pos->z, $this->walkSpeed);
		return true;
	}

	private function findThreat() : ?Entity{
		$loc = $this->mob->getLiveLocation();
		$r = $this->maxDistance;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 3, $loc->z - $r, $loc->x + $r, $loc->y + 3, $loc->z + $r), $this->mob) as $entity){
			$live = Mob::getLiveLocationOf($entity);
			if($live !== null){
				//the distance from the live location (no copy) first, then the other checks (no side effects): most
				//entities around are farther than the nearest threat found so far, or no threat at all
				$d = $live->distanceSquared($loc);
				if($d >= $bestDist || !$entity->isAlive() || ($entity instanceof Player && ($entity->isSpectator() || $entity->isCreative())) || !($entity instanceof Living) || Sensing::isNeverTargeted($entity)){
					continue;
				}
			}else{
				//a class overriding getLocation(): the original order
				if(!$entity->isAlive() || ($entity instanceof Player && ($entity->isSpectator() || $entity->isCreative())) || !($entity instanceof Living) || Sensing::isNeverTargeted($entity)){
					continue;
				}
				$d = $entity->getLocation()->distanceSquared($loc);
			}
			//invisible threats are only noticed from closer (vanilla AvoidEntityGoal uses combat targeting conditions)
			if($d < $bestDist && ($this->filter)($entity) && Sensing::canDetect($this->mob, $entity, $r, $d)){
				$best = $entity;
				$bestDist = $d;
			}
		}
		return $best;
	}

	/**
	 * {@link AvoidEntityGoal::findThreat()} for players only: the same search area (within $maxDistance, a standing
	 * player's box within 3 blocks above or below the mob), from the world's player list.
	 */
	private function findPlayerThreat() : ?Player{
		$y = $this->mob->getLiveLocation()->y;
		$this->searchMinY = $y - 3 - self::PLAYER_HEIGHT;
		$this->searchMaxY = $y + 3;
		$this->playerFilter ??= function(Player $player) : bool{
			$feet = $player->getLocation()->y;
			return $feet < $this->searchMaxY && $feet > $this->searchMinY && !$player->isCreative() && ($this->filter)($player);
		};
		return $this->mob->getSensing()->getNearestPlayer($this->maxDistance, $this->playerFilter, true);
	}

	public function canContinue() : bool{
		return !$this->mob->getNavigation()->isDone();
	}

	public function stop() : void{
		$this->threat = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$threat = $this->threat;
		if($threat !== null && !$threat->isClosed()){
			$close = $threat->getLocation()->distanceSquared($this->mob->getLiveLocation()) < 49;
			$this->mob->getNavigation()->setSpeedMultiplier($close ? $this->sprintSpeed : $this->walkSpeed);
		}
	}
}
