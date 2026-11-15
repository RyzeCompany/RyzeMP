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

use pocketmine\block\Crops;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\particle\BoneMealUseParticle;
use function floor;
use function mt_rand;

/**
 * Farmers with bone meal use it on the growing crops around them during working hours (vanilla
 * behavior.fertilize_farm_block, work_schedule_farmer priority 8, defaults: search_range 1, search_height 1,
 * goal_radius 1.5, search_cooldown_max_seconds 8, speed 0.5, max_fertilizer_usage 1). One bone meal per search.
 *
 * The search reads the 3x3x3 blocks around the farmer, at most once every 0-8 seconds; nothing runs for other
 * villagers or outside working hours.
 */
class FertilizeCropsGoal extends VillagerGoal{
	public const SPEED = 0.5;
	public const SEARCH_RANGE = 1;
	public const SEARCH_HEIGHT = 1;
	/** Most ticks between two searches (search_cooldown_max_seconds). */
	public const SEARCH_COOLDOWN_MAX = 160;
	private const REACH_TIMEOUT = 100;

	private ?Vector3 $target = null;
	private int $nextSearch = 0;
	private int $giveUpTick = 0;

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	private function canFertilize() : bool{
		return $this->villager->getProfession() === VillagerV2::PROFESSION_FARMER && $this->villager->getInventory()->contains(VanillaItems::BONE_MEAL()) &&
			$this->villager->getActivity() === VillagerActivity::WORK;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextSearch || $this->villager->getProfession() !== VillagerV2::PROFESSION_FARMER){
			return false;
		}
		$this->nextSearch = $now + mt_rand(0, self::SEARCH_COOLDOWN_MAX);
		if(!$this->canFertilize()){
			return false;
		}
		$this->target = $this->findCrop();
		return $this->target !== null;
	}

	/**
	 * Returns a crop that isn't fully grown next to the farmer, if any.
	 *
	 * @internal
	 */
	public function findCrop() : ?Vector3{
		$loc = $this->villager->getLiveLocation();
		$world = $this->villager->getWorld();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y);
		$z = (int) floor($loc->z);
		$r = self::SEARCH_RANGE;
		$h = self::SEARCH_HEIGHT;
		for($dx = -$r; $dx <= $r; ++$dx){
			for($dz = -$r; $dz <= $r; ++$dz){
				for($dy = -$h; $dy <= $h; ++$dy){
					$block = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
					if($block instanceof Crops && $block->getAge() < $block->getMaxAge()){
						return new Vector3($x + $dx, $y + $dy, $z + $dz);
					}
				}
			}
		}
		return null;
	}

	public function canContinue() : bool{
		return $this->target !== null && $this->villager->getAiTick() < $this->giveUpTick && $this->canFertilize();
	}

	public function start() : void{
		$this->giveUpTick = $this->villager->getAiTick() + self::REACH_TIMEOUT;
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null || !$this->walkTo($target, self::SPEED, 1.5)){
			return;
		}
		$this->villager->getNavigation()->stop();
		$this->villager->getLookControl()->setLookAt($target->x + 0.5, $target->y, $target->z + 0.5);
		$this->fertilize($target);
		$this->target = null;
	}

	/**
	 * Uses one bone meal of the farmer on the crop at the position. Returns whether the crop grew.
	 *
	 * @internal
	 */
	public function fertilize(Vector3 $pos) : bool{
		$world = $this->villager->getWorld();
		$block = $world->getBlock($pos);
		if(!$block instanceof Crops || $block->getAge() >= $block->getMaxAge()){
			return false;
		}
		$boneMeal = VanillaItems::BONE_MEAL();
		$block->onInteract($boneMeal, Facing::UP, new Vector3(0.5, 0.5, 0.5));
		if(!$boneMeal->isNull()){
			return false; //the growth was cancelled
		}
		$this->villager->getInventory()->removeItem(VanillaItems::BONE_MEAL());
		$world->addParticle($pos, new BoneMealUseParticle()); //like a player's bone meal (World::useItemOn())
		return true;
	}

	public function stop() : void{
		$this->target = null;
		$this->villager->getNavigation()->stop();
	}
}
