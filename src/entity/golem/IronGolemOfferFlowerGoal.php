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
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\AxisAlignedBB;
use function mt_rand;

/**
 * During the day an iron golem now and then holds out a poppy to a villager within 6 blocks for 20 seconds (vanilla
 * OfferFlowerGoal: 1 in 8000 per tick, 400 ticks), shown with the IRON_GOLEM_OFFER_FLOWER and
 * IRON_GOLEM_WITHDRAW_FLOWER actor events. Baby villagers and copper golems nearby may take it ({@link TakeFlowerGoal}).
 */
class IronGolemOfferFlowerGoal extends Goal{
	public const OFFER_TICKS = 400;
	public const CHANCE = 8000;
	private const RANGE = 6.0;

	private ?VillagerV2 $villager = null;
	private int $ticks = 0;

	public function __construct(
		private IronGolem $golem
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		if(!TakeFlowerGoal::isDaytime($this->golem->getWorld())){
			return false;
		}
		if($this->golem->getAttackTarget() !== null || mt_rand(0, self::CHANCE - 1) !== 0){
			return false;
		}
		$this->villager = $this->findVillager();
		return $this->villager !== null;
	}

	private function findVillager() : ?VillagerV2{
		$loc = $this->golem->getLiveLocation();
		$r = self::RANGE;
		$best = null;
		$bestDistance = $r * $r;
		foreach($this->golem->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 2, $loc->z - $r, $loc->x + $r, $loc->y + 2, $loc->z + $r), $this->golem) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive()){
				$distance = $entity->getPosition()->distanceSquared($loc);
				if($distance < $bestDistance){
					$best = $entity;
					$bestDistance = $distance;
				}
			}
		}
		return $best;
	}

	public function canContinue() : bool{
		//the flower is gone once a baby villager or a copper golem took it (see TakeFlowerGoal)
		return $this->ticks > 0 && $this->golem->isOfferingFlower() && $this->villager !== null && $this->villager->isAlive() && !$this->villager->isClosed() && $this->golem->getAttackTarget() === null;
	}

	public function start() : void{
		$this->ticks = self::OFFER_TICKS;
		$this->golem->getNavigation()->stop();
		$this->golem->setOfferingFlower(true);
	}

	public function stop() : void{
		$this->villager = null;
		$this->ticks = 0;
		$this->golem->setOfferingFlower(false);
	}

	public function tick() : void{
		--$this->ticks;
		if($this->villager !== null){
			$pos = $this->villager->getPosition();
			$this->golem->getLookControl()->setLookAt($pos->x, $pos->y + $this->villager->getEyeHeight(), $pos->z);
		}
	}
}
