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

use pocketmine\block\Composter;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function min;

/**
 * Walks to the claimed job site during working hours and works there (vanilla behavior.work / work_composter: speed
 * 0.5, active_time 250 ticks, goal_cooldown 200 ticks). Arriving at the job site restocks the trades (on_arrival
 * resupply_trades, see {@link \pocketmine\entity\npc\VillagerV2::onArriveAtWork()}); farmers arriving at their
 * composter compost their surplus seeds (see {@link WorkAtJobSiteGoal::compost()}). Like vanilla, villagers stop
 * working in the rain and go home (see {@link \pocketmine\entity\npc\VillagerSchedule}).
 */
class WorkAtJobSiteGoal extends VillagerGoal{
	public const SPEED = 0.5;
	/** Seeds of each kind a farmer keeps, and most items it composts at once. */
	public const COMPOST_KEEP = 10;
	public const COMPOST_MAX_ITEMS = 20;
	private const ACTIVE_TICKS = 250;
	private const COOLDOWN = 200;
	/** Ticks the villager tries to reach its job site before giving up for a while. */
	private const REACH_TIMEOUT = 600;

	private int $endTick = 0;
	private int $cooldownUntil = 0;
	private bool $arrived = false;

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	private function isWorkTime() : bool{
		return $this->villager->getActivity() === VillagerActivity::WORK;
	}

	public function canStart() : bool{
		return $this->villager->getAiTick() >= $this->cooldownUntil && $this->villager->getJobSitePosition() !== null && $this->isWorkTime();
	}

	public function canContinue() : bool{
		return $this->villager->getJobSitePosition() !== null && $this->villager->getAiTick() < $this->endTick && $this->isWorkTime();
	}

	public function start() : void{
		$this->arrived = false;
		$this->endTick = $this->villager->getAiTick() + self::REACH_TIMEOUT;
	}

	public function tick() : void{
		$jobSite = $this->villager->getJobSitePosition();
		if($jobSite === null){
			return;
		}
		if(!$this->arrived){
			if($this->walkTo($jobSite, self::SPEED, 1.8)){
				$this->arrived = true;
				$this->endTick = $this->villager->getAiTick() + self::ACTIVE_TICKS;
				$this->villager->getNavigation()->stop();
				$this->villager->onArriveAtWork();
				if($this->villager->getProfession() === VillagerV2::PROFESSION_FARMER){
					$composter = $this->villager->getWorld()->getBlock($jobSite);
					if($composter instanceof Composter){
						self::compost($this->villager, $composter);
					}
				}
			}
			return;
		}
		$this->villager->getLookControl()->setLookAt($jobSite->x + 0.5, $jobSite->y + 0.5, $jobSite->z + 0.5);
	}

	/**
	 * A farmer working at its composter (vanilla work_composter; amounts of Java WorkAtComposter): it first takes the
	 * bone meal out of a full composter (dropped on top, the farmer picks it up), then composts its wheat and beetroot
	 * seeds beyond 10 of each, 20 at most, until the composter is about to be ready. Returns the number of items used.
	 *
	 * @internal
	 */
	public static function compost(VillagerV2 $villager, Composter $composter) : int{
		if($composter->getLayers() >= Composter::MAX_COMPOST_LAYERS){
			$composter->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 1, 0.5));
			$composter = $composter->getPosition()->getWorld()->getBlock($composter->getPosition());
			if(!$composter instanceof Composter){
				return 0;
			}
		}
		$inventory = $villager->getInventory();
		$budget = self::COMPOST_MAX_ITEMS;
		$used = 0;
		foreach([VanillaItems::WHEAT_SEEDS(), VanillaItems::BEETROOT_SEEDS()] as $seed){
			$count = 0;
			foreach($inventory->all($seed) as $stack){
				$count += $stack->getCount();
			}
			$toUse = min($count - self::COMPOST_KEEP, $budget);
			for($i = 0; $i < $toUse; ++$i){
				if($composter->getLayers() >= Composter::MAX_COMPOST_LAYERS - 1){
					return $used;
				}
				$item = (clone $seed)->setCount(1);
				$composter->onInteract($item, Facing::UP, new Vector3(0.5, 1, 0.5));
				if(!$item->isNull()){
					return $used; //cancelled
				}
				$inventory->removeItem((clone $seed)->setCount(1));
				++$used;
				--$budget;
				$composter = $composter->getPosition()->getWorld()->getBlock($composter->getPosition());
				if(!$composter instanceof Composter){
					return $used;
				}
			}
		}
		return $used;
	}

	public function stop() : void{
		$this->cooldownUntil = $this->villager->getAiTick() + self::COOLDOWN;
		$this->villager->getNavigation()->stop();
	}
}
