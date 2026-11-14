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
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function cos;
use function deg2rad;
use function sin;

/**
 * Swims along with a swimming player (Bedrock behavior.swim_with_entity, dolphins): every state_check_interval the
 * mob without a companion picks the nearest player swimming within search_range with success_rate, and a mob swimming
 * with one stops with chance_to_stop. It swims to the player at speed_multiplier, at catch_up_multiplier when farther
 * than catch_up_threshold, stops within stop_distance and, within match_direction_threshold, looks where the player
 * looks (swims alongside).
 */
class SwimWithEntityGoal extends Goal{
	/** Ticks between two path updates towards the player. */
	private const REPATH_TICKS = 10;

	private ?Player $player = null;
	/** AI tick of the next state check (picking a player, or the chance to stop). */
	private int $nextCheckTick = 0;
	private int $repathDelay = 0;

	/**
	 * @param int $stateCheckInterval ticks between two state checks (Bedrock state_check_interval 0.5 s)
	 * @phpstan-param (\Closure(Player) : bool)|null $filter extra condition on the player
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.5,
		protected float $searchRange = 20.0,
		protected float $successRate = 0.1,
		protected float $chanceToStop = 0.0333,
		protected int $stateCheckInterval = 10,
		protected float $catchUpThreshold = 12.0,
		protected float $catchUpMultiplier = 2.5,
		protected float $matchDirectionThreshold = 2.0,
		protected float $stopDistance = 5.0,
		protected ?\Closure $filter = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	/**
	 * Returns the player the mob swims with, if any.
	 */
	public function getPlayer() : ?Player{
		return $this->player;
	}

	/**
	 * Returns whether the player is in the water (swimming or under it) and accepted by the filter.
	 */
	private function isSwimmingCompanion(Player $player) : bool{
		return ($player->isSwimming() || $player->isUnderwater()) && !$player->isSpectator() && $this->mob->getAttackTarget() !== $player &&
			($this->filter === null || ($this->filter)($player));
	}

	public function canStart() : bool{
		$now = $this->mob->getAiTick();
		if($now < $this->nextCheckTick){
			return false;
		}
		$this->nextCheckTick = $now + $this->stateCheckInterval;
		if(!$this->mob->isInWater() || Utils::getRandomFloat() >= $this->successRate){
			return false;
		}
		$this->player = $this->mob->getSensing()->getNearestPlayer($this->searchRange, $this->isSwimmingCompanion(...));
		return $this->player !== null;
	}

	public function canContinue() : bool{
		$player = $this->player;
		if($player === null || !$player->isAlive() || $player->isClosed() || $player->getWorld() !== $this->mob->getWorld() || !$this->mob->isInWater()){
			return false;
		}
		$now = $this->mob->getAiTick();
		if($now >= $this->nextCheckTick){
			$this->nextCheckTick = $now + $this->stateCheckInterval;
			if(Utils::getRandomFloat() < $this->chanceToStop || !$this->isSwimmingCompanion($player) ||
				$player->getLocation()->distanceSquared($this->mob->getLiveLocation()) > $this->searchRange ** 2
			){
				return false;
			}
		}
		return true;
	}

	public function start() : void{
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->player = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$player = $this->player;
		if($player === null){
			return;
		}
		$playerLoc = $player->getLocation();
		$distanceSq = $playerLoc->distanceSquared($this->mob->getLiveLocation());
		if($distanceSq <= $this->matchDirectionThreshold ** 2){
			//alongside: look (and so swim) the way the player looks
			$yaw = deg2rad($playerLoc->yaw);
			$this->mob->getLookControl()->setLookAt($playerLoc->x - sin($yaw) * 4, $playerLoc->y + $player->getEyeHeight(), $playerLoc->z + cos($yaw) * 4);
		}else{
			$this->mob->getLookControl()->setLookAtEntity($player);
		}
		if($distanceSq <= $this->stopDistance ** 2){
			if(!$this->mob->getNavigation()->isDone()){
				$this->mob->getNavigation()->stop();
			}
			$this->repathDelay = 0;
			return;
		}
		if(--$this->repathDelay <= 0){
			$this->repathDelay = self::REPATH_TICKS;
			$speed = $distanceSq > $this->catchUpThreshold ** 2 ? $this->catchUpMultiplier : $this->speedMultiplier;
			$this->mob->getNavigation()->moveToEntity($player, $speed, $this->stopDistance);
		}
	}
}
