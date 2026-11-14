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

namespace pocketmine\entity\ai\raid;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\Vector3;
use pocketmine\world\raid\PoiType;
use function floor;

/**
 * Villagers hide during a raid (vanilla villager_v2 behavior.hide, poi_type "bed", speed 0.8): they run to the nearest
 * bed of the raided village and stay there until the raid is over: their own bed if they claimed one, otherwise the
 * nearest bed of the village. A villager that heard a bell (minecraft:hide, see {@link VillagerV2::hearBell()}) hides
 * the same way for 30 seconds, in its own bed or the nearest bed around.
 *
 * The way to the bed is walked in legs of {@link RaidMoveGoal::STEP} blocks. When a leg fails (no path, or a partial
 * one that leaves the mob where it was) the next one is tried a second later; a villager hiding from a bell gives up
 * instead and hides again 8 seconds later at the earliest (behavior.hide timeout_cooldown).
 */
class HideFromRaidGoal extends Goal{
	public const SPEED = 0.8;
	private const MAX_BED_DISTANCE = 48.0;
	private const CHECK_INTERVAL = 20;
	/** Ticks between two legs (at most one path search a second). */
	private const RETRY_TICKS = 20;
	/** Bedrock behavior.hide timeout_cooldown: 8 seconds without hiding from a bell after a failed path. */
	private const BELL_TIMEOUT_COOLDOWN = 160;
	/** A leg ending farther than this from its end point... */
	private const LEG_REACHED_DISTANCE = 2.0;
	/** ...after moving the mob less than this failed. */
	private const LEG_MIN_PROGRESS = 1.5;

	private ?Vector3 $bed = null;
	private int $nextCheck = 0;
	/** Whether the mob hides from a bell (not from a raid) right now. */
	private bool $fromBell = false;
	private bool $gaveUp = false;
	private int $nextMove = 0;
	/** Whether a leg was requested and hasn't been checked yet. */
	private bool $onLeg = false;
	private float $legStartX = 0.0;
	private float $legStartZ = 0.0;
	private float $legEndX = 0.0;
	private float $legEndZ = 0.0;

	public function __construct(
		protected Mob $mob
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	private function isHidingFromBell() : bool{
		return $this->mob instanceof VillagerV2 && $this->mob->isHidingFromBell();
	}

	private function findBed() : ?Vector3{
		$pos = $this->mob->getLiveLocation();
		$bell = $this->isHidingFromBell();
		$raid = $bell ? null : $this->mob->getWorld()->getRaidManager()->getRaidAt($pos);
		if($raid === null && !$bell){
			return null;
		}
		$this->fromBell = $bell;
		$own = $this->mob instanceof VillagerV2 ? $this->mob->getBedPosition() : null;
		if($own !== null && $own->distanceSquared($pos) < self::MAX_BED_DISTANCE ** 2){
			return $own;
		}
		if($raid !== null){
			$beds = $raid->getBeds();
		}else{
			//a bell rang: the nearest bed around
			$beds = [];
			$x = (int) floor($pos->x);
			$y = (int) floor($pos->y);
			$z = (int) floor($pos->z);
			$r = (int) self::MAX_BED_DISTANCE;
			foreach($this->mob->getWorld()->getPoiManager()->getPois($x - $r, $y - 16, $z - $r, $x + $r, $y + 16, $z + $r) as [$type, $poiPos]){
				if($type === PoiType::BED){
					$beds[] = $poiPos;
				}
			}
		}
		$best = null;
		$bestDist = self::MAX_BED_DISTANCE ** 2;
		foreach($beds as $bed){
			$d = $bed->distanceSquared($pos);
			if($d < $bestDist){
				$best = $bed;
				$bestDist = $d;
			}
		}
		return $best;
	}

	public function canStart() : bool{
		$now = $this->mob->getAiTick();
		if($now < $this->nextCheck){
			return false;
		}
		$this->nextCheck = $now + self::CHECK_INTERVAL;
		$this->bed = $this->findBed();
		return $this->bed !== null;
	}

	public function canContinue() : bool{
		if($this->gaveUp){
			return false;
		}
		if($this->isHidingFromBell()){
			return true;
		}
		$now = $this->mob->getAiTick();
		if($now >= $this->nextCheck){
			$this->nextCheck = $now + self::CHECK_INTERVAL;
			if($this->mob->getWorld()->getRaidManager()->getRaidAt($this->mob->getLiveLocation()) === null){
				return false;
			}
		}
		return true;
	}

	public function start() : void{
		$this->gaveUp = false;
		$this->onLeg = false;
		$this->nextMove = 0;
		$this->tick();
	}

	public function tick() : void{
		$bed = $this->bed;
		if($bed === null){
			return;
		}
		$pos = $this->mob->getLiveLocation();
		if(($pos->x - $bed->x - 0.5) ** 2 + ($pos->z - $bed->z - 0.5) ** 2 <= 1.5 ** 2){
			$this->onLeg = false;
			return;
		}
		$navigation = $this->mob->getNavigation();
		if(!$navigation->isDone()){
			return;
		}
		$now = $this->mob->getAiTick();
		if($this->onLeg){
			$this->onLeg = false;
			if(
				($pos->x - $this->legEndX) ** 2 + ($pos->z - $this->legEndZ) ** 2 > self::LEG_REACHED_DISTANCE ** 2 &&
				($pos->x - $this->legStartX) ** 2 + ($pos->z - $this->legStartZ) ** 2 < self::LEG_MIN_PROGRESS ** 2
			){
				$this->legFailed($now);
				return;
			}
		}
		if($now < $this->nextMove){
			return;
		}
		$this->nextMove = $now + self::RETRY_TICKS;
		$step = RaidMoveGoal::stepTowards($this->mob, $bed->add(0.5, 0, 0.5), RaidMoveGoal::STEP);
		if($step === null || !$navigation->moveTo($step->x, $step->y, $step->z, self::SPEED, 1.0)){
			$this->legFailed($now);
			return;
		}
		$this->onLeg = true;
		$this->legStartX = $pos->x;
		$this->legStartZ = $pos->z;
		$this->legEndX = $step->x;
		$this->legEndZ = $step->z;
	}

	/**
	 * No way along this leg: during a raid the next leg is tried a second later (nextMove), a villager hiding from a bell
	 * gives up.
	 */
	private function legFailed(int $now) : void{
		if($this->fromBell){
			$this->gaveUp = true;
			$this->nextCheck = $now + self::BELL_TIMEOUT_COOLDOWN;
		}
	}

	public function stop() : void{
		$this->mob->getNavigation()->stop();
		$this->bed = null;
		$this->gaveUp = false;
		$this->onLeg = false;
	}
}
