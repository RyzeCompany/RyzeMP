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

use pocketmine\entity\Mob;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\AxisAlignedBB;
use function count;
use function intdiv;
use function mt_rand;

/**
 * Baby villagers chase each other during play time (vanilla behavior.play, priority 8, speed 0.6, friends: baby
 * villagers within 16 blocks).
 */
class PlayGoal extends VillagerGoal{
	public const SPEED = 0.6;
	private const RANGE = 16.0;
	/** Average ticks between two chases. */
	private const INTERVAL = 100;
	private const CHASE_TICKS = 200;

	private ?VillagerV2 $friend = null;
	private int $endTick = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if($this->villager->getActivity() !== VillagerActivity::PLAY || mt_rand(0, intdiv(self::INTERVAL, Mob::GOAL_EVALUATION_INTERVAL)) !== 0){
			return false;
		}
		$loc = $this->villager->getLiveLocation();
		$r = self::RANGE;
		$friends = [];
		foreach($this->villager->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this->villager) as $entity){
			if($entity instanceof VillagerV2 && $entity->isBaby() && $entity->isAlive()){
				$friends[] = $entity;
			}
		}
		if($friends === []){
			return false;
		}
		$this->friend = $friends[mt_rand(0, count($friends) - 1)];
		return true;
	}

	public function canContinue() : bool{
		$friend = $this->friend;
		return $friend !== null && $friend->isAlive() && !$friend->isClosed() && $this->villager->getActivity() === VillagerActivity::PLAY &&
			$this->villager->getAiTick() < $this->endTick;
	}

	public function start() : void{
		$this->endTick = $this->villager->getAiTick() + self::CHASE_TICKS;
	}

	public function tick() : void{
		if($this->friend !== null){
			$this->villager->getNavigation()->moveToEntity($this->friend, self::SPEED, 1.0);
		}
	}

	public function stop() : void{
		$this->friend = null;
		$this->villager->getNavigation()->stop();
	}
}
