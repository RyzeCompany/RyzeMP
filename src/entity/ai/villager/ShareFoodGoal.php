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
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\AxisAlignedBB;

/**
 * A villager with a surplus of food (6 bread, or 24 carrots, potatoes or beetroots) walks to a villager that needs
 * some and throws it half of the stack (vanilla behavior.share_items, priority 10, speed 0.5, max_dist 3). The other
 * villager picks it up.
 */
class ShareFoodGoal extends VillagerGoal{
	public const SPEED = 0.5;
	private const RANGE = 8.0;
	private const CHECK_INTERVAL = 100;
	private const TIMEOUT = 200;

	private ?VillagerV2 $receiver = null;
	private int $nextCheck = 0;
	private int $endTick = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextCheck){
			return false;
		}
		$this->nextCheck = $now + self::CHECK_INTERVAL;
		$activity = $this->villager->getActivity();
		if($activity === VillagerActivity::SLEEP || $this->villager->getShareableFood() === null){
			return false;
		}
		$loc = $this->villager->getLiveLocation();
		$r = self::RANGE;
		foreach($this->villager->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 3, $loc->z - $r, $loc->x + $r, $loc->y + 3, $loc->z + $r), $this->villager) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive() && $entity->wantsFood()){
				$this->receiver = $entity;
				return true;
			}
		}
		return false;
	}

	public function canContinue() : bool{
		$receiver = $this->receiver;
		return $receiver !== null && $receiver->isAlive() && !$receiver->isClosed() && $this->villager->getAiTick() < $this->endTick;
	}

	public function start() : void{
		$this->endTick = $this->villager->getAiTick() + self::TIMEOUT;
	}

	public function tick() : void{
		$receiver = $this->receiver;
		if($receiver === null){
			return;
		}
		$this->villager->getLookControl()->setLookAtEntity($receiver);
		if($this->villager->getLiveLocation()->distanceSquared($receiver->getLocation()) > 3 * 3){
			$this->villager->getNavigation()->moveToEntity($receiver, self::SPEED, 2.0);
			return;
		}
		$this->villager->getNavigation()->stop();
		$this->villager->throwFoodTo($receiver);
		$this->receiver = null;
	}

	public function stop() : void{
		$this->receiver = null;
		$this->villager->getNavigation()->stop();
	}
}
