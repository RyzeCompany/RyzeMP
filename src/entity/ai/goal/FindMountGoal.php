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
use pocketmine\math\AxisAlignedBB;

/**
 * A mob looks for something to ride within a radius, walks to it and gets on (vanilla behavior.find_mount: baby
 * zombie jockeys looking for a chicken). It gives up after a number of failed attempts.
 */
class FindMountGoal extends Goal{
	private ?Mob $mount = null;
	private int $failedAttempts = 0;
	private int $repathDelay = 0;
	private int $ticks = 0;
	private int $startDelay;

	public function __construct(
		protected Mob $mob,
		protected float $radius = 16.0,
		protected int $maxFailedAttempts = 20,
		int $startDelay = 15
	){
		$this->startDelay = $startDelay;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		if($this->startDelay > 0){
			$this->startDelay -= Mob::GOAL_EVALUATION_INTERVAL;
			return false;
		}
		if($this->mob->getVehicle() !== null || $this->failedAttempts >= $this->maxFailedAttempts){
			return false;
		}
		$loc = $this->mob->getLiveLocation();
		$r = $this->radius;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->mob->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this->mob) as $entity){
			if($entity instanceof Mob && $entity->isAlive() && $entity->getVehicle() === null && $entity->canAddPassenger($this->mob)){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		if($best === null){
			++$this->failedAttempts;
			return false;
		}
		$this->mount = $best;
		return true;
	}

	public function canContinue() : bool{
		$mount = $this->mount;
		return $mount !== null && $this->mob->getVehicle() === null && $mount->isAlive() && !$mount->isClosed() && $this->ticks < 200 && $mount->canAddPassenger($this->mob);
	}

	public function start() : void{
		$this->repathDelay = 0;
		$this->ticks = 0;
	}

	public function stop() : void{
		if($this->mob->getVehicle() === null){
			++$this->failedAttempts;
		}
		$this->mount = null;
		$this->mob->getNavigation()->stop();
	}

	public function tick() : void{
		$mount = $this->mount;
		if($mount === null){
			return;
		}
		++$this->ticks;
		$reach = $mount->getSize()->getWidth() / 2 + $this->mob->getSize()->getWidth() / 2 + 0.5;
		if($mount->getLocation()->distanceSquared($this->mob->getLiveLocation()) <= $reach * $reach){
			$this->mob->mount($mount);
		}elseif(--$this->repathDelay <= 0){
			$this->repathDelay = 10;
			$this->mob->getNavigation()->moveToEntity($mount, 1.0, 0.0);
		}
	}
}
