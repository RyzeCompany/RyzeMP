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

use pocketmine\entity\npc\VillagerActivity;
use pocketmine\math\Vector3;

/**
 * Villagers without a bed seek shelter under a roof in the evening, at night and in the rain (vanilla
 * behavior.move_indoors: priority 6, speed 0.8, timeout_cooldown 8 s). Villagers with a bed go to it instead (see
 * {@link GoHomeGoal}, {@link SleepInBedGoal}). Looks among a few random spots around for one with a block above it,
 * at most once every 8 seconds.
 */
class MoveIndoorsGoal extends VillagerGoal{
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

	private function wantsShelter() : bool{
		$activity = $this->villager->getActivity();
		return $activity === VillagerActivity::HOME || $activity === VillagerActivity::SLEEP;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextTry || $this->villager->getBedPosition() !== null || $this->villager->isSleeping()){
			return false;
		}
		$this->nextTry = $now + self::COOLDOWN;
		if(!$this->wantsShelter() || !$this->villager->canSeeSky()){
			return false;
		}
		$world = $this->villager->getWorld();
		for($i = 0; $i < self::TRIES; ++$i){
			$pos = $this->villager->findWanderPos(10, 7);
			if($pos !== null && !self::isUnderSky($world, $pos)){
				$this->target = $pos;
				return true;
			}
		}
		return false;
	}

	public function canContinue() : bool{
		return $this->target !== null && $this->villager->getAiTick() < $this->endTick && !$this->villager->getNavigation()->isDone();
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
