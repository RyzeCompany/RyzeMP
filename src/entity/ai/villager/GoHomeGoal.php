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
 * Walks back to the claimed bed in the evening (home schedule) and stays around it.
 */
class GoHomeGoal extends VillagerGoal{
	public const SPEED = 0.6;
	/** Distance from the bed the villager may wander to while at home. */
	private const HOME_RANGE = 4.0;
	private const TIMEOUT = 600;
	private const CHECK_INTERVAL = 40;

	private int $endTick = 0;
	private int $nextCheck = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextCheck || $this->villager->getActivity() !== VillagerActivity::HOME){
			return false;
		}
		$this->nextCheck = $now + self::CHECK_INTERVAL;
		$bed = $this->villager->getBedPosition();
		return $bed !== null && !$this->isNear($bed, self::HOME_RANGE);
	}

	public function canContinue() : bool{
		$bed = $this->villager->getBedPosition();
		return $bed !== null && $this->villager->getActivity() === VillagerActivity::HOME && $this->villager->getAiTick() < $this->endTick && !$this->isNear($bed, 2.0);
	}

	public function start() : void{
		$this->endTick = $this->villager->getAiTick() + self::TIMEOUT;
	}

	public function tick() : void{
		$bed = $this->villager->getBedPosition();
		if($bed !== null){
			$this->walkTo($bed, self::SPEED, 2.0);
		}
	}

	public function stop() : void{
		$this->villager->getNavigation()->stop();
	}
}
