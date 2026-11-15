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

use pocketmine\block\utils\LiquidLayer;
use pocketmine\entity\Mob;
use pocketmine\player\Player;
use function floor;

/**
 * A mob flies or walks to the nearest player with a free seat for it and gets on (vanilla behavior.find_mount with the
 * player as the mount: tamed parrots landing on a shoulder; within_radius 16, mount_distance 2, start_delay 100,
 * avoid_water). Players flying, gliding, swimming, sleeping or standing in water are not mounted.
 *
 * The start delay runs again every time the mob gets off (see {@link FindPlayerMountGoal::restartDelay()}), and the
 * search waits a second after finding nobody.
 */
class FindPlayerMountGoal extends Goal{
	private const SEARCH_COOLDOWN = 20;
	/** Gives up walking to a player after this many ticks. */
	private const MAX_TICKS = 200;

	private ?Player $player = null;
	private int $delay;
	private int $searchCooldown = 0;
	private int $repathDelay = 0;
	private int $ticks = 0;

	/**
	 * @phpstan-param (\Closure() : bool)|null $canMount whether the mob may look for a player now (e.g. tamed, not sitting)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $radius = 16.0,
		protected float $mountDistance = 2.0,
		protected int $startDelay = 100,
		protected ?\Closure $canMount = null
	){
		$this->delay = $startDelay;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	/**
	 * Waits the start delay again before looking for a player (called when the mob got off one).
	 */
	public function restartDelay() : void{
		$this->delay = $this->startDelay;
	}

	/**
	 * Returns whether the mob may get on the player now.
	 */
	public static function canRide(Mob $mob, Player $player) : bool{
		if(!$player->isAlive() || $player->isSpectator() || $player->isFlying() || $player->isGliding() || $player->isSwimming() || $player->isSleeping()){
			return false;
		}
		$pos = $player->getPosition();
		//avoid_water
		if(LiquidLayer::hasWaterAt($player->getWorld(), (int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))){
			return false;
		}
		return $player->canAddPassenger($mob);
	}

	private function allowed() : bool{
		return $this->mob->getVehicle() === null && !$this->mob->isLeashed() && ($this->canMount === null || ($this->canMount)());
	}

	public function canStart() : bool{
		if($this->delay > 0){
			$this->delay -= Mob::GOAL_EVALUATION_INTERVAL;
			return false;
		}
		if($this->searchCooldown > 0){
			$this->searchCooldown -= Mob::GOAL_EVALUATION_INTERVAL;
			return false;
		}
		if(!$this->allowed()){
			return false;
		}
		$player = $this->mob->getSensing()->getNearestPlayer($this->radius, fn(Player $p) => self::canRide($this->mob, $p));
		if($player === null){
			$this->searchCooldown = self::SEARCH_COOLDOWN;
			return false;
		}
		$this->player = $player;
		return true;
	}

	public function canContinue() : bool{
		$player = $this->player;
		return $player !== null && !$player->isClosed() && $player->getWorld() === $this->mob->getWorld() && $this->ticks < self::MAX_TICKS &&
			$this->allowed() && self::canRide($this->mob, $player);
	}

	public function start() : void{
		$this->ticks = 0;
		$this->repathDelay = 0;
	}

	public function stop() : void{
		$this->player = null;
		$this->mob->getNavigation()->stop();
		if($this->mob->getVehicle() === null){
			$this->searchCooldown = self::SEARCH_COOLDOWN;
		}
	}

	public function tick() : void{
		$player = $this->player;
		if($player === null){
			return;
		}
		++$this->ticks;
		$this->mob->getLookControl()->setLookAtEntity($player, 10.0, 40.0);
		$pos = $player->getPosition();
		//measured to the middle of the player, where the mob flies to
		$target = $pos->add(0, $player->getSize()->getHeight() / 2, 0);
		if($target->distanceSquared($this->mob->getLiveLocation()) <= $this->mountDistance ** 2){
			$this->mob->mount($player);
		}elseif(--$this->repathDelay <= 0){
			$this->repathDelay = 10;
			$this->mob->getNavigation()->moveTo($target->x, $target->y, $target->z, 1.0);
		}
	}
}
