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
use function mt_rand;

/**
 * Looks at a nearby player for a few seconds (vanilla behavior.look_at_player).
 */
class LookAtPlayerGoal extends Goal{
	private ?Player $player = null;
	private int $ticksLeft = 0;

	/**
	 * @param float $probability chance per tick to start looking at a player in range (vanilla 0.02)
	 */
	public function __construct(
		protected Mob $mob,
		protected float $range = 6.0,
		protected float $probability = 0.02
	){}

	public function getFlags() : int{
		return self::FLAG_LOOK;
	}

	public function canStart() : bool{
		if(Utils::getRandomFloat() >= $this->probability * Mob::GOAL_EVALUATION_INTERVAL){
			return false;
		}
		$this->player = $this->mob->getSensing()->getNearestPlayer($this->range);
		return $this->player !== null;
	}

	public function canContinue() : bool{
		$player = $this->player;
		return $this->ticksLeft > 0 && $player !== null && $player->isAlive() && !$player->isClosed() &&
			$player->getWorld() === $this->mob->getWorld() &&
			$player->getLocation()->distanceSquared($this->mob->getLiveLocation()) <= $this->range * $this->range;
	}

	public function start() : void{
		$this->ticksLeft = 40 + mt_rand(0, 39);
	}

	public function stop() : void{
		$this->player = null;
	}

	public function tick() : void{
		--$this->ticksLeft;
		if($this->player !== null){
			$this->mob->getLookControl()->setLookAtEntity($this->player);
		}
	}
}
