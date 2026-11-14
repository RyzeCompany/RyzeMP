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
use pocketmine\math\Vector3;
use pocketmine\world\raid\PoiType;
use function floor;
use function mt_rand;

/**
 * In the afternoon villagers meet at the nearest bell (or, without one, around another villager) and mingle (vanilla
 * behavior.mingle, priority 7, speed 0.5, mingle_distance 2, cooldown 10 seconds): they walk up to a villager, look at
 * it and gossip (see {@link VillagerV2::gossipWith()}).
 */
class GatherAtBellGoal extends VillagerGoal{
	public const SPEED = 0.5;
	public const BELL_RANGE = 32;
	/** Ticks between two talks. */
	private const MINGLE_COOLDOWN = 200;
	/** Ticks a talk lasts. */
	private const MINGLE_TICKS = 60;
	private const PARTNER_RANGE = 8.0;

	private ?Vector3 $meetingPoint = null;
	private ?VillagerV2 $partner = null;
	private int $mingleEnd = 0;
	private int $nextMingle = 0;
	private int $nextBellSearch = 0;
	private bool $talked = false;

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->villager->getActivity() === VillagerActivity::GATHER;
	}

	public function start() : void{
		$this->meetingPoint = null;
		$this->nextBellSearch = 0;
	}

	private function findBell() : ?Vector3{
		$loc = $this->villager->getLiveLocation();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y);
		$z = (int) floor($loc->z);
		$r = self::BELL_RANGE;
		$best = null;
		$bestDist = 0.0;
		foreach($this->villager->getWorld()->getPoiManager()->getPois($x - $r, $y - 8, $z - $r, $x + $r, $y + 8, $z + $r) as [$type, $pos]){
			if($type === PoiType::BELL){
				$d = $pos->distanceSquared($loc);
				if($best === null || $d < $bestDist){
					$best = $pos;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	private function findPartner() : ?VillagerV2{
		$loc = $this->villager->getLiveLocation();
		$r = self::PARTNER_RANGE;
		$best = null;
		$bestDist = 0.0;
		foreach($this->villager->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 3, $loc->z - $r, $loc->x + $r, $loc->y + 3, $loc->z + $r), $this->villager) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive() && !$entity->isBaby() && !$entity->isSleeping()){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($best === null || $d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	public function tick() : void{
		$now = $this->villager->getAiTick();
		if($now >= $this->nextBellSearch){
			$this->nextBellSearch = $now + 200;
			$bell = $this->findBell();
			$this->meetingPoint = $bell?->add(mt_rand(-2, 2), 0, mt_rand(-2, 2));
		}
		$partner = $this->partner;
		if($partner !== null){
			if($partner->isClosed() || !$partner->isAlive() || $now >= $this->mingleEnd){
				$this->partner = null;
				return;
			}
			$this->villager->getLookControl()->setLookAtEntity($partner);
			if($this->villager->getLiveLocation()->distanceSquared($partner->getLocation()) > 2.5 * 2.5){
				$this->villager->getNavigation()->moveToEntity($partner, self::SPEED, 2.0);
			}else{
				$this->villager->getNavigation()->stop();
				if(!$this->talked){
					$this->talked = true;
					$this->villager->gossipWith($partner);
				}
			}
			return;
		}
		$meetingPoint = $this->meetingPoint;
		if($meetingPoint !== null && !$this->walkTo($meetingPoint, self::SPEED, 3.0)){
			return;
		}
		if($now >= $this->nextMingle){
			$this->nextMingle = $now + self::MINGLE_COOLDOWN + mt_rand(0, 100);
			$this->partner = $this->findPartner();
			$this->talked = false;
			$this->mingleEnd = $now + self::MINGLE_TICKS;
		}
	}

	public function stop() : void{
		$this->partner = null;
		$this->meetingPoint = null;
		$this->villager->getNavigation()->stop();
	}
}
