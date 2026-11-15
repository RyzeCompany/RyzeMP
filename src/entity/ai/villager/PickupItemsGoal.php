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

/**
 * Walks to a wanted item lying a few blocks away (vanilla behavior.pickup_items: priority 4, max_dist 3, speed 0.5).
 * The item is found by the villager's pickup scan (every 10 ticks, see
 * {@link \pocketmine\entity\npc\VillagerV2::getPickupTarget()}), which also picks it up once it is within reach.
 */
class PickupItemsGoal extends VillagerGoal{
	public const SPEED = 0.5;
	/** Ticks spent walking to an item before giving up. */
	private const TIMEOUT = 100;

	/** Ticks between two path requests to the item. */
	private const REPATH_INTERVAL = 20;
	/** Ticks an item the villager couldn't get to is ignored. */
	private const IGNORE_TICKS = 300;

	private int $endTick = 0;
	private int $nextPath = 0;
	private int $ignoredId = -1;
	private int $ignoredUntil = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$target = $this->villager->getPickupTarget();
		return $target !== null && ($target->getId() !== $this->ignoredId || $this->villager->getAiTick() >= $this->ignoredUntil) && !$this->villager->isSleeping();
	}

	public function canContinue() : bool{
		$now = $this->villager->getAiTick();
		$target = $this->villager->getPickupTarget();
		if($target === null){
			return false;
		}
		if($now >= $this->endTick){
			$this->ignoredId = $target->getId();
			$this->ignoredUntil = $now + self::IGNORE_TICKS;
			return false;
		}
		return true;
	}

	public function start() : void{
		$now = $this->villager->getAiTick();
		$this->endTick = $now + self::TIMEOUT;
		$this->nextPath = $now;
		$this->tick();
	}

	public function tick() : void{
		$target = $this->villager->getPickupTarget();
		$navigation = $this->villager->getNavigation();
		$now = $this->villager->getAiTick();
		if($target !== null && $navigation->isDone() && $now >= $this->nextPath){
			$this->nextPath = $now + self::REPATH_INTERVAL;
			$pos = $target->getPosition();
			$navigation->moveTo($pos->x, $pos->y, $pos->z, self::SPEED, 0.5);
		}
	}

	public function stop() : void{
		$this->villager->getNavigation()->stop();
	}
}
