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

/**
 * Walks to the claimed bed at night and sleeps in it until morning (vanilla behavior.sleep, priority 3, speed 0.6,
 * timeout cooldown 10 seconds). The villager wakes up when its schedule moves on, when it is hurt or when its bed is
 * broken or used by a player. A villager that can't reach its bed before giving up releases it and looks for
 * another one.
 */
class SleepInBedGoal extends VillagerGoal{
	public const SPEED = 0.6;
	/** Ticks the villager tries to reach its bed before giving up for a while. */
	private const REACH_TIMEOUT = 1200;
	/** Ticks before trying again after giving up (timeout_cooldown). */
	private const COOLDOWN = 200;

	private int $giveUpTick = 0;
	private int $cooldownUntil = 0;
	/** The bed was reached but someone else was in it. */
	private bool $bedTaken = false;

	public function getFlags() : int{
		//no FLAG_JUMP: FloatGoal must keep the villager afloat on its way through water
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->villager->getActivity() === VillagerActivity::SLEEP && $this->villager->getBedPosition() !== null &&
			$this->villager->getAiTick() >= $this->cooldownUntil;
	}

	public function canContinue() : bool{
		if($this->villager->getActivity() !== VillagerActivity::SLEEP || $this->villager->getBedPosition() === null){
			return false;
		}
		return $this->villager->isSleeping() || $this->villager->getAiTick() < $this->giveUpTick;
	}

	public function start() : void{
		$this->giveUpTick = $this->villager->getAiTick() + self::REACH_TIMEOUT;
	}

	public function tick() : void{
		if($this->villager->isSleeping()){
			return;
		}
		$bed = $this->villager->getBedPosition();
		if($bed === null){
			return;
		}
		if($this->walkTo($bed, self::SPEED, 1.5)){
			$this->villager->getNavigation()->stop();
			if(!$this->villager->startSleeping()){
				//someone else is in the bed
				$this->giveUpTick = 0;
				$this->bedTaken = true;
			}
		}
	}

	public function stop() : void{
		if($this->villager->isSleeping()){
			$this->villager->wakeUp();
		}else{
			$this->cooldownUntil = $this->villager->getAiTick() + self::COOLDOWN;
			$bed = $this->villager->getBedPosition();
			if($bed !== null && !$this->bedTaken && $this->giveUpTick > 0 && $this->villager->getAiTick() >= $this->giveUpTick){
				//couldn't get there all this time: let another villager have it, and look for a reachable one
				$this->villager->markBedUnreachable($bed);
			}
		}
		$this->bedTaken = false;
		$this->villager->getNavigation()->stop();
	}
}
