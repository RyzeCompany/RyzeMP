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

use pocketmine\entity\animal\Llama;
use pocketmine\world\World;
use function sqrt;

/**
 * Llama caravans (Bedrock behavior.follow_caravan of llamas off a lead, Java LlamaFollowCaravanGoal): a llama joins
 * the end of a caravan within 9 blocks (4 up or down), or the end of a llama on a lead, as long as a llama on a lead
 * leads it within the caravan's length; it then walks to 2 blocks behind the llama ahead. Beyond 26 blocks it speeds
 * up (x1.2, up to 3x) and gives up after 2 seconds more at full speed.
 *
 * Looking for a caravan to join scans the llamas around, so it is done every 2 seconds only, by llamas off a lead and
 * not in a caravan.
 */
class FollowCaravanGoal extends Goal{
	/** Ticks between two looks for a caravan to join. */
	public const SEARCH_INTERVAL = 40;
	/** Ticks between two paths to the llama ahead. */
	private const REPATH_TICKS = 10;
	private const MAX_SPEED = 3.0;
	/** Beyond this distance (26 blocks) from the llama ahead it speeds up. */
	private const FAR_DISTANCE_SQUARED = 676.0;
	/** Within this distance (2.5 blocks) of the llama ahead it doesn't move. */
	private const NEAR_DISTANCE_SQUARED = 6.25;

	private float $speed;
	private int $nextSearchTick;
	private int $distCheckCounter = 0;
	private int $repathTicks = 0;
	/** Block hash of the llama ahead when the last path to it was asked for, null if none. */
	private ?int $lastHeadBlock = null;

	/**
	 * @param float $speedMultiplier vanilla speed_multiplier (2.1)
	 * @param int   $entityCount     vanilla entity_count: how many llamas a caravan has at most behind the one on the
	 *                               lead (10)
	 */
	public function __construct(
		protected Llama $llama,
		protected float $speedMultiplier = 2.1,
		protected int $entityCount = 10
	){
		$this->speed = $speedMultiplier;
		$this->nextSearchTick = $llama->getId() % self::SEARCH_INTERVAL;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$llama = $this->llama;
		if($llama->isLeashed() || $llama->inCaravan()){
			return false;
		}
		$tick = $llama->getAiTick();
		if($tick < $this->nextSearchTick){
			return false;
		}
		$this->nextSearchTick = $tick + self::SEARCH_INTERVAL;

		$closest = null;
		$closestLeashed = null;
		$closestDistance = $closestLeashedDistance = 0.0;
		$loc = $llama->getLiveLocation();
		foreach($llama->getWorld()->getNearbyEntities($llama->getBoundingBox()->expandedCopy(9, 4, 9), $llama) as $entity){
			if(!$entity instanceof Llama || !$entity->isAlive() || $entity->hasCaravanTail()){
				continue;
			}
			$d = $entity->getLocation()->distanceSquared($loc);
			if($entity->inCaravan()){
				if($closest === null || $d < $closestDistance){
					$closest = $entity;
					$closestDistance = $d;
				}
			}elseif($entity->isLeashed() && ($closestLeashed === null || $d < $closestLeashedDistance)){
				$closestLeashed = $entity;
				$closestLeashedDistance = $d;
			}
		}
		if($closest === null){
			$closest = $closestLeashed;
			$closestDistance = $closestLeashedDistance;
		}
		if($closest === null || $closestDistance < 4.0 || (!$closest->isLeashed() && !$this->firstIsLeashed($closest, 1))){
			return false;
		}
		$llama->joinCaravan($closest);
		return true;
	}

	/**
	 * Whether a llama on a lead leads the caravan ahead of this llama within the caravan's length.
	 */
	private function firstIsLeashed(Llama $llama, int $counter) : bool{
		for($max = $this->entityCount - 2; $counter <= $max; ++$counter){
			$head = $llama->getCaravanHead();
			if($head === null){
				return false;
			}
			if($head->isLeashed()){
				return true;
			}
			$llama = $head;
		}
		return false;
	}

	public function canContinue() : bool{
		$llama = $this->llama;
		$head = $llama->getCaravanHead();
		if($head === null || $head->isClosed() || !$head->isAlive() || $llama->isLeashed() || !$this->firstIsLeashed($llama, 0)){
			return false;
		}
		if($head->getLocation()->distanceSquared($llama->getLiveLocation()) > self::FAR_DISTANCE_SQUARED){
			if($this->speed <= self::MAX_SPEED){
				$this->speed *= 1.2;
				$this->distCheckCounter = 40;
				return true;
			}
			if($this->distCheckCounter === 0){
				return false;
			}
		}
		if($this->distCheckCounter > 0){
			--$this->distCheckCounter;
		}
		return true;
	}

	public function start() : void{
		$this->repathTicks = 0;
		$this->lastHeadBlock = null;
	}

	public function stop() : void{
		$this->llama->leaveCaravan();
		$this->speed = $this->speedMultiplier;
		$this->llama->getNavigation()->stop();
	}

	public function tick() : void{
		$llama = $this->llama;
		$head = $llama->getCaravanHead();
		if($head === null || --$this->repathTicks > 0){
			return;
		}
		$this->repathTicks = self::REPATH_TICKS;
		$from = $llama->getLiveLocation();
		$to = $head->getLocation();
		$navigation = $llama->getNavigation();
		$headBlock = World::blockHash($to->getFloorX(), $to->getFloorY(), $to->getFloorZ());
		if($headBlock === $this->lastHeadBlock && $navigation->isDone()){
			return; //already there, or no way there: the llama ahead hasn't moved to another block since
		}
		$dx = $to->x - $from->x;
		$dy = $to->y - $from->y;
		$dz = $to->z - $from->z;
		$distanceSquared = $dx * $dx + $dy * $dy + $dz * $dz;
		//2 blocks behind the llama ahead: within half a block of that spot there is nowhere to go
		if($distanceSquared <= self::NEAR_DISTANCE_SQUARED){
			if(!$navigation->isDone()){
				$navigation->stop();
			}
			$this->lastHeadBlock = null;
			return;
		}
		$distance = sqrt($distanceSquared);
		$f = ($distance - 2.0) / $distance;
		$this->lastHeadBlock = $headBlock;
		$navigation->moveTo($from->x + $dx * $f, $from->y + $dy * $f, $from->z + $dz * $f, $this->speed);
	}
}
