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

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\Crops;
use pocketmine\block\Farmland;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\GameRules;
use pocketmine\world\raid\VillageScanner;
use function floor;

/**
 * Farmers harvest grown wheat, carrots, potatoes and beetroots around them during working hours and replant the
 * farmland with seeds from their inventory (vanilla behavior.harvest_farm_block, priority 7, speed 0.5). The harvest
 * goes into the farmer's inventory (what doesn't fit is dropped). Bone meal is used by {@link FertilizeCropsGoal}.
 *
 * Crops are looked for every {@link HarvestCropsGoal::SEARCH_INTERVAL} ticks in a box of
 * {@link HarvestCropsGoal::SEARCH_RANGE} blocks around, reading only the sub-chunks that have crops or farmland.
 */
class HarvestCropsGoal extends VillagerGoal{
	public const SPEED = 0.5;
	public const SEARCH_RANGE = 8;
	public const SEARCH_INTERVAL = 100;
	/** Ticks the farmer tries to reach a crop before looking for another one. */
	private const REACH_TIMEOUT = 300;

	/**
	 * @var true[]|null
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $searchTypes = null;

	private ?Vector3 $target = null;
	private int $nextSearch = 0;
	private int $giveUpTick = 0;

	public function __construct(VillagerV2 $villager){
		parent::__construct($villager);
		$this->nextSearch = $villager->getAiTick() + $villager->getId() % self::SEARCH_INTERVAL;
	}

	/**
	 * @phpstan-return array<int, true>
	 */
	private static function getSearchTypes() : array{
		if(self::$searchTypes === null){
			self::$searchTypes = [];
			foreach([VanillaBlocks::WHEAT(), VanillaBlocks::CARROTS(), VanillaBlocks::POTATOES(), VanillaBlocks::BEETROOTS(), VanillaBlocks::FARMLAND()] as $block){
				self::$searchTypes[$block->getTypeId()] = true;
			}
		}
		return self::$searchTypes;
	}

	/**
	 * Seed item that plants the crop, keyed by crop block type ID.
	 */
	public static function getSeedFor(Block $crop) : ?Item{
		return match($crop->getTypeId()){
			VanillaBlocks::WHEAT()->getTypeId() => VanillaItems::WHEAT_SEEDS(),
			VanillaBlocks::CARROTS()->getTypeId() => VanillaItems::CARROT(),
			VanillaBlocks::POTATOES()->getTypeId() => VanillaItems::POTATO(),
			VanillaBlocks::BEETROOTS()->getTypeId() => VanillaItems::BEETROOT_SEEDS(),
			default => null
		};
	}

	/**
	 * Crop block planted by a seed item in the farmer's inventory, or null.
	 */
	private function findPlantableCrop() : ?Block{
		foreach([
			[VanillaItems::WHEAT_SEEDS(), VanillaBlocks::WHEAT()],
			[VanillaItems::CARROT(), VanillaBlocks::CARROTS()],
			[VanillaItems::POTATO(), VanillaBlocks::POTATOES()],
			[VanillaItems::BEETROOT_SEEDS(), VanillaBlocks::BEETROOTS()],
		] as [$seed, $crop]){
			if($this->villager->getInventory()->contains($seed)){
				return $crop;
			}
		}
		return null;
	}

	private function isFarmTime() : bool{
		return $this->villager->getProfession() === VillagerV2::PROFESSION_FARMER && $this->villager->getActivity() === VillagerActivity::WORK;
	}

	/**
	 * Returns the nearest grown crop, or empty farmland if the farmer has something to plant.
	 */
	public function findTarget() : ?Vector3{
		$world = $this->villager->getWorld();
		$loc = $this->villager->getLiveLocation();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y);
		$z = (int) floor($loc->z);
		$r = self::SEARCH_RANGE;
		$canPlant = $this->findPlantableCrop() !== null;
		$best = null;
		$bestDist = 0.0;
		foreach(VillageScanner::findBlocks($world, $x - $r, $y - 2, $z - $r, $x + $r, $y + 1, $z + $r, self::getSearchTypes()) as [$bx, $by, $bz]){
			$block = $world->getBlockAt($bx, $by, $bz);
			if($block instanceof Crops){
				if($block->getAge() < $block->getMaxAge()){
					continue;
				}
				$pos = new Vector3($bx, $by, $bz);
			}elseif($block instanceof Farmland && $canPlant && $world->getBlockAt($bx, $by + 1, $bz) instanceof Air){
				$pos = new Vector3($bx, $by + 1, $bz);
			}else{
				continue;
			}
			$d = $pos->distanceSquared($loc);
			if($best === null || $d < $bestDist){
				$best = $pos;
				$bestDist = $d;
			}
		}
		return $best;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$now = $this->villager->getAiTick();
		if($now < $this->nextSearch || !$this->isFarmTime() || !GameRules::allowsMobGriefing($this->villager->getWorld())){
			return false;
		}
		$this->nextSearch = $now + self::SEARCH_INTERVAL;
		$this->target = $this->findTarget();
		return $this->target !== null;
	}

	public function canContinue() : bool{
		return $this->target !== null && $this->isFarmTime();
	}

	public function start() : void{
		$this->giveUpTick = $this->villager->getAiTick() + self::REACH_TIMEOUT;
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){
			return;
		}
		if($this->villager->getAiTick() >= $this->giveUpTick){
			$this->target = null;
			return;
		}
		if(!$this->walkTo($target, self::SPEED, 1.5)){
			return;
		}
		$this->villager->getNavigation()->stop();
		$this->villager->getLookControl()->setLookAt($target->x + 0.5, $target->y, $target->z + 0.5);
		$this->work($target);
		//next crop right away if there is one
		$this->target = $this->findTarget();
		$this->giveUpTick = $this->villager->getAiTick() + self::REACH_TIMEOUT;
	}

	/**
	 * Harvests the grown crop at the position and replants it, or plants on the empty farmland below it.
	 */
	public function work(Vector3 $pos) : void{
		$world = $this->villager->getWorld();
		$block = $world->getBlock($pos);
		if($block instanceof Crops && $block->getAge() >= $block->getMaxAge()){
			foreach($block->getDrops(VanillaItems::AIR()) as $drop){
				$this->villager->addToInventory($drop);
			}
			$world->setBlock($pos, VanillaBlocks::AIR());
			$block = $world->getBlock($pos);
		}
		if($block instanceof Air && $world->getBlock($pos->down()) instanceof Farmland){
			$crop = $this->findPlantableCrop();
			$seed = $crop !== null ? self::getSeedFor($crop) : null;
			if($crop !== null && $seed !== null){
				$this->villager->getInventory()->removeItem($seed);
				$world->setBlock($pos, $crop);
			}
		}
	}

	public function stop() : void{
		$this->target = null;
		$this->villager->getNavigation()->stop();
	}
}
