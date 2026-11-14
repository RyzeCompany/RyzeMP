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

namespace pocketmine\entity\ai\villager;

use pocketmine\math\Vector3;

/**
 * Celebrating villagers go outdoors, where they can launch their fireworks (vanilla minecraft:celebrate
 * behavior.move_outdoors: priority 2, speed 0.8, timeout_cooldown 8 s). Looks for a spot under the open sky among a few
 * random spots around; tries again 8 seconds later if there was none or it couldn't get there.
 */
class MoveOutdoorsGoal extends VillagerGoal{
	public const SPEED = 0.8;
	private const COOLDOWN = 160;
	private const TIMEOUT = 200;
	private const TRIES = 10;

	private ?Vector3 $target = null;
	private int $nextTry = 0;
	private int $endTick = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if(!$this->villager->isCelebrating() || $now < $this->nextTry || $this->villager->isSleeping()){
			return false;
		}
		if($this->villager->canSeeSky()){
			$this->nextTry = $now + 20; //outdoors already: look again in a second
			return false;
		}
		$this->nextTry = $now + self::COOLDOWN;
		$this->target = $this->findSpot(true);
		return $this->target !== null;
	}

	/**
	 * Returns a random spot around where the villager can stand under the open sky ($outdoors) or under a roof.
	 */
	protected function findSpot(bool $outdoors) : ?Vector3{
		$world = $this->villager->getWorld();
		for($i = 0; $i < self::TRIES; ++$i){
			$pos = $this->villager->findWanderPos(10, 7);
			if($pos !== null && self::isUnderSky($world, $pos) === $outdoors){
				return $pos;
			}
		}
		return null;
	}

	public function canContinue() : bool{
		return $this->target !== null && $this->villager->isCelebrating() && $this->villager->getAiTick() < $this->endTick && !$this->villager->getNavigation()->isDone();
	}

	public function start() : void{
		$this->endTick = $this->villager->getAiTick() + self::TIMEOUT;
		$target = $this->target;
		if($target !== null){
			$this->villager->getNavigation()->moveTo($target->x, $target->y, $target->z, self::SPEED);
		}
	}

	public function stop() : void{
		$this->target = null;
		$this->villager->getNavigation()->stop();
	}
}
