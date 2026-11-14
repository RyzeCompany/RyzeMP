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

namespace pocketmine\entity\golem;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\math\Vector3;
use pocketmine\world\raid\Village;
use pocketmine\world\World;
use function array_shift;
use function count;
use function in_array;

/**
 * At night an iron golem in a village patrols it, walking from one point of interest (bed, bell, job site) to the
 * nearest one it didn't visit lately (vanilla behavior.move_through_village: only_at_night, speed 0.6, priority 3; Java
 * MoveThroughVillageGoal remembers the last 15 places).
 *
 * The village comes from {@link IronGolem::getVillage()}, refreshed every few seconds; the goal itself tries at most
 * once every two seconds.
 */
class IronGolemMoveThroughVillageGoal extends Goal{
	public const SPEED = 0.6;
	/** Ticks between two tries to start. */
	private const INTERVAL = 40;
	/** Ticks after which a walk is given up (stuck). */
	private const TIMEOUT = 400;
	private const REMEMBERED = 15;
	/** Points closer than this (squared) count as reached already. */
	private const MIN_DISTANCE_SQUARED = 9.0;

	private ?Vector3 $target = null;
	/**
	 * Block hashes of the places visited last.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $visited = [];
	private int $nextTry = 0;
	private int $ticks = 0;

	public function __construct(
		private IronGolem $golem
	){}

	public static function isNight(World $world) : bool{
		return !TakeFlowerGoal::isDaytime($world);
	}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$tick = $this->golem->getAiTick();
		if($tick < $this->nextTry){
			return false;
		}
		$this->nextTry = $tick + self::INTERVAL;
		if($this->golem->getAttackTarget() !== null || !self::isNight($this->golem->getWorld())){
			return false;
		}
		$village = $this->golem->getVillage();
		if($village === null){
			return false;
		}
		$loc = $this->golem->getLiveLocation();
		if(!$village->contains($loc)){
			return false;
		}
		$this->target = $this->pickTarget($village, $loc);
		return $this->target !== null;
	}

	/**
	 * Returns the nearest point of interest of the village not visited lately (forgetting them all once every one
	 * was visited).
	 */
	public function pickTarget(Village $village, Vector3 $from) : ?Vector3{
		$best = null;
		$bestDistance = 0.0;
		$any = false;
		foreach([$village->getBeds(), $village->getBells(), $village->getJobSites()] as $list){
			foreach($list as $pos){
				$distance = $pos->distanceSquared($from);
				if($distance < self::MIN_DISTANCE_SQUARED){
					continue;
				}
				$any = true;
				if(in_array(World::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z), $this->visited, true)){
					continue;
				}
				if($best === null || $distance < $bestDistance){
					$best = $pos;
					$bestDistance = $distance;
				}
			}
		}
		if($best === null && $any && count($this->visited) > 0){
			$this->visited = [];
			return $this->pickTarget($village, $from);
		}
		return $best;
	}

	public function canContinue() : bool{
		return
			++$this->ticks <= self::TIMEOUT && !$this->golem->getNavigation()->isDone() &&
			$this->golem->getAttackTarget() === null && self::isNight($this->golem->getWorld());
	}

	public function start() : void{
		$this->ticks = 0;
		$target = $this->target;
		if($target !== null){
			$this->golem->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, self::SPEED, 1.5);
		}
	}

	public function stop() : void{
		$target = $this->target;
		if($target !== null){
			$this->visited[] = World::blockHash((int) $target->x, (int) $target->y, (int) $target->z);
			if(count($this->visited) > self::REMEMBERED){
				array_shift($this->visited);
			}
		}
		$this->target = null;
		$this->golem->getNavigation()->stop();
	}
}
