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
use pocketmine\math\Vector3;
use pocketmine\world\raid\PoiType;
use pocketmine\world\World;
use function count;
use function floor;
use function mt_getrandmax;
use function mt_rand;

/**
 * Walks to a village nearby (vanilla behavior.stroll_towards_village of foxes at night: search_range 32, start_chance
 * 0.005, cooldown 10 s, goal_radius 3; that is how foxes end up among the chickens of villages): a random point of
 * interest of a village (a bell first, else a bed or a job site) within $searchRange blocks.
 *
 * The points of interest come from the world's cached POI data ({@link World::getPoiManager()}), asked only when the
 * start chance is met (about once every 10 seconds per mob), and only while $condition allows it (night).
 */
class StrollTowardsVillageGoal extends Goal{
	/** Longest walk, in ticks. */
	private const MAX_TICKS = 600;
	/** Height searched above and below the mob. */
	private const SEARCH_HEIGHT = 16;

	private ?Vector3 $target = null;
	private int $nextTry = 0;
	private int $ticks = 0;

	/**
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected int $searchRange = 32,
		protected float $startChance = 0.005,
		protected int $cooldown = 200,
		protected float $goalRadius = 3.0,
		protected ?\Closure $condition = null
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->nextTry){
			return false;
		}
		//start_chance is per tick; goals are evaluated every GOAL_EVALUATION_INTERVAL ticks
		if(mt_rand() / mt_getrandmax() >= $this->startChance * Mob::GOAL_EVALUATION_INTERVAL){
			return false;
		}
		if(($this->condition !== null && !($this->condition)()) || $mob->getAttackTarget() !== null){
			return false;
		}
		$this->nextTry = $mob->getAiTick() + $this->cooldown;
		$this->target = $this->pickTarget($mob->getWorld(), $mob->getLiveLocation());
		return $this->target !== null;
	}

	/**
	 * Returns a random point of interest of a village within the search range farther than the goal radius: a bell if
	 * there is one, else a bed or a job site.
	 */
	public function pickTarget(World $world, Vector3 $from) : ?Vector3{
		$x = (int) floor($from->x);
		$y = (int) floor($from->y);
		$z = (int) floor($from->z);
		$r = $this->searchRange;
		$bells = [];
		$others = [];
		$minDistance = $this->goalRadius * $this->goalRadius;
		foreach($world->getPoiManager()->getPois($x - $r, $y - self::SEARCH_HEIGHT, $z - $r, $x + $r, $y + self::SEARCH_HEIGHT, $z + $r) as [$type, $pos]){
			if($pos->distanceSquared($from) <= $minDistance){
				continue;
			}
			if($type === PoiType::BELL){
				$bells[] = $pos;
			}else{
				$others[] = $pos;
			}
		}
		$list = count($bells) > 0 ? $bells : $others;
		return count($list) > 0 ? $list[mt_rand(0, count($list) - 1)] : null;
	}

	public function canContinue() : bool{
		$target = $this->target;
		if($target === null || $this->ticks >= self::MAX_TICKS || $this->mob->getNavigation()->isDone() || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		return $this->mob->getLiveLocation()->distanceSquared($target) > $this->goalRadius * $this->goalRadius;
	}

	public function start() : void{
		$this->ticks = 0;
		$target = $this->target;
		if($target !== null){
			$this->mob->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, $this->speedMultiplier, $this->goalRadius);
		}
	}

	public function tick() : void{
		++$this->ticks;
	}

	public function stop() : void{
		$this->target = null;
		$this->mob->getNavigation()->stop();
		$this->nextTry = $this->mob->getAiTick() + $this->cooldown;
	}

	/**
	 * Makes the next try happen right away and always pass the start chance (tests).
	 *
	 * @internal
	 */
	public function forceNextTry() : void{
		$this->nextTry = 0;
		$this->startChance = 1.0;
	}
}
