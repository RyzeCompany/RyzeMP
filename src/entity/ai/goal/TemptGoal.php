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
use pocketmine\item\Item;
use pocketmine\player\Player;
use function abs;
use function fmod;
use function intdiv;

/**
 * Follows a player holding a food item the mob likes (vanilla behavior.tempt) within $range blocks (Bedrock
 * within_radius), stopping $stopDistance blocks from the player.
 *
 * A mob that can get scared (Bedrock can_get_scared: wild cats and ocelots, foxes) stops following a player within 6
 * blocks who moves more than 0.1 blocks or turns more than 5 degrees in a tick, and isn't tempted again for 5 seconds
 * (vanilla TemptGoal::canScare()): taming an ocelot takes standing still.
 */
class TemptGoal extends Goal{
	public const DEFAULT_RANGE = 10.0;
	public const DEFAULT_STOP_DISTANCE = 2.5;
	private const COOLDOWN_TICKS = 100;
	/** Within this distance of the mob a scaring mob notices the player moving. */
	private const SCARE_DISTANCE = 6.0;

	private ?Player $player = null;
	private int $cooldown = 0;

	/** Where the player was when it came within {@link TemptGoal::SCARE_DISTANCE} blocks, and its last rotation. */
	private float $playerX = 0.0;
	private float $playerY = 0.0;
	private float $playerZ = 0.0;
	private float $playerYaw = 0.0;
	private float $playerPitch = 0.0;

	/**
	 * @param \Closure|bool $canGetScared   whether the player moving close by stops the temptation (see the class doc), or
	 *                                      a closure telling it (e.g. only while the cat is wild)
	 * @param \Closure|null $onStopTempting called when the mob stops following the player (Bedrock on_stop_tempting
	 *                                      event, e.g. set_home_position)
	 *
	 * @phpstan-param \Closure(Item) : bool $isTemptItem
	 * @phpstan-param bool|(\Closure() : bool) $canGetScared
	 * @phpstan-param (\Closure() : void)|null $onStopTempting
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier,
		protected \Closure $isTemptItem,
		protected float $range = self::DEFAULT_RANGE,
		protected float $stopDistance = self::DEFAULT_STOP_DISTANCE,
		protected \Closure|bool $canGetScared = false,
		protected ?\Closure $onStopTempting = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	private function isTempting(Player $player) : bool{
		return ($this->isTemptItem)($player->getInventory()->getItemInHand()) || ($this->isTemptItem)($player->getOffHandInventory()->getItem(0));
	}

	private function canGetScared() : bool{
		return $this->canGetScared instanceof \Closure ? ($this->canGetScared)() : $this->canGetScared;
	}

	public function canStart() : bool{
		if($this->cooldown > 0){
			--$this->cooldown;
			return false;
		}
		$this->player = $this->mob->getSensing()->getNearestPlayer($this->range, fn(Player $p) => $this->isTempting($p));
		return $this->player !== null;
	}

	public function canContinue() : bool{
		$player = $this->player;
		if($player === null || !$player->isAlive() || $player->isClosed() || $player->getWorld() !== $this->mob->getWorld()){
			return false;
		}
		$loc = Mob::getLiveLocationOf($player) ?? $player->getLocation();
		$distanceSquared = $loc->distanceSquared($this->mob->getLiveLocation());
		if($distanceSquared > $this->range * $this->range || ($this->canGetScared() && $this->isScaredBy($loc->x, $loc->y, $loc->z, $loc->yaw, $loc->pitch, $distanceSquared))){
			return false;
		}
		return $this->isTempting($player);
	}

	/**
	 * Returns whether the player scared the mob since the last tick (see the class doc), and remembers its rotation.
	 */
	private function isScaredBy(float $x, float $y, float $z, float $yaw, float $pitch, float $distanceSquared) : bool{
		if($distanceSquared < self::SCARE_DISTANCE * self::SCARE_DISTANCE){
			$dx = $x - $this->playerX;
			$dy = $y - $this->playerY;
			$dz = $z - $this->playerZ;
			if($dx * $dx + $dy * $dy + $dz * $dz > 0.01 || abs($pitch - $this->playerPitch) > 5.0 || self::angleDifference($yaw, $this->playerYaw) > 5.0){
				return true;
			}
		}else{
			$this->playerX = $x;
			$this->playerY = $y;
			$this->playerZ = $z;
		}
		$this->playerYaw = $yaw;
		$this->playerPitch = $pitch;
		return false;
	}

	private static function angleDifference(float $a, float $b) : float{
		return abs(fmod($a - $b + 540.0, 360.0) - 180.0);
	}

	public function start() : void{
		//Bedrock: a mob tempted with food no longer despawns
		$this->mob->setPersistent(true);
		$player = $this->player;
		if($player !== null){
			$loc = Mob::getLiveLocationOf($player) ?? $player->getLocation();
			$this->playerX = $loc->x;
			$this->playerY = $loc->y;
			$this->playerZ = $loc->z;
			$this->playerYaw = $loc->yaw;
			$this->playerPitch = $loc->pitch;
		}
	}

	public function stop() : void{
		$this->player = null;
		$this->cooldown = intdiv(self::COOLDOWN_TICKS, Mob::GOAL_EVALUATION_INTERVAL); //counted down in canStart()
		$this->stopMoving();
		if($this->onStopTempting !== null){
			($this->onStopTempting)();
		}
	}

	public function tick() : void{
		$player = $this->player;
		if($player === null){
			return;
		}
		$this->mob->getLookControl()->setLookAtEntity($player, 30.0);
		if((Mob::getLiveLocationOf($player) ?? $player->getLocation())->distanceSquared($this->mob->getLiveLocation()) < $this->stopDistance * $this->stopDistance){
			$this->stopMoving();
		}else{
			$this->moveTowards($player);
		}
	}

	/**
	 * Moves the mob towards the tempting player (pathfinding by default; mobs steered another way override it).
	 */
	protected function moveTowards(Player $player) : void{
		$this->mob->getNavigation()->moveToEntity($player, $this->speedMultiplier, 1.5);
	}

	/**
	 * Stops moving towards the tempting player.
	 */
	protected function stopMoving() : void{
		$this->mob->getNavigation()->stop();
	}
}
