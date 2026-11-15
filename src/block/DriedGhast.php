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

namespace pocketmine\block;

use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnReason;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\BlockStateChangeSound;

/**
 * Dried ghast (https://minecraft.wiki/w/Dried_Ghast; Java 26.3 DriedGhastBlock). It can be waterlogged (water in the
 * liquid layer, like vanilla Bedrock). While it holds water, every 5000 ticks its rehydration level goes up by one
 * (state change sound); at level 3 the next step replaces it with a ghastling (baby happy ghast) facing the block's way,
 * leaving the water behind. Out of water the level goes back down by one every 5000 ticks.
 *
 * It runs on delayed block updates (saved with the chunk), scheduled when it is placed, when water is put in it and
 * while it is wet or still hydrated: no random ticks.
 */
class DriedGhast extends Transparent implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	public const MAX_REHYDRATION_LEVEL = 3;
	/** Ticks between two hydration steps (Java HYDRATION_TICK_DELAY). */
	private const HYDRATION_TICK_DELAY = 5000;

	protected int $rehydrationLevel = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(0, self::MAX_REHYDRATION_LEVEL, $this->rehydrationLevel);
	}

	public function getRehydrationLevel() : int{ return $this->rehydrationLevel; }

	/** @return $this */
	public function setRehydrationLevel(int $level) : self{
		if($level < 0 || $level > self::MAX_REHYDRATION_LEVEL){
			throw new \InvalidArgumentException("Rehydration level must be in range 0 ... " . self::MAX_REHYDRATION_LEVEL);
		}
		$this->rehydrationLevel = $level;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(3 / 16, 0, 3 / 16)->trim(Facing::UP, 6 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function onPostPlace() : void{
		$this->scheduleHydrationStep();
	}

	public function onNearbyBlockChange() : void{
		//also called for itself when water is put in it (bucket, dispenser)
		$this->scheduleHydrationStep();
	}

	/**
	 * Schedules the next hydration step if the level can change (wet, or drying out) and none is pending.
	 */
	private function scheduleHydrationStep() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(($this->rehydrationLevel > 0 || LiquidLayer::holdsWater($this)) && !$world->isBlockUpdateScheduled($x, $y, $z)){
			$world->scheduleDelayedBlockUpdate($this->position, self::HYDRATION_TICK_DELAY);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if(LiquidLayer::holdsWater($this)){
			if($this->rehydrationLevel >= self::MAX_REHYDRATION_LEVEL){
				$this->spawnGhastling();
				return;
			}
			++$this->rehydrationLevel;
			$world->setBlock($this->position, $this);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new BlockStateChangeSound($this));
		}elseif($this->rehydrationLevel > 0){
			--$this->rehydrationLevel;
			$world->setBlock($this->position, $this);
		}
		$this->scheduleHydrationStep();
	}

	/**
	 * Replaces the dried ghast with a ghastling facing the block's way; the water it held stays (Java spawnGhastling).
	 */
	private function spawnGhastling() : HappyGhast{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::WATER());
		$yaw = match($this->facing){
			Facing::SOUTH => 0,
			Facing::WEST => 90,
			Facing::NORTH => 180,
			default => 270
		};
		$ghastling = new HappyGhast(Location::fromObject($this->position->add(0.5, 0, 0.5), $world, $yaw, 0));
		$ghastling->setAge($ghastling->getBabyStartAge());
		$ghastling->setSpawnReason(SpawnReason::BREEDING);
		$ghastling->spawnToAll();
		$ghastling->playMobSound(LevelSoundEvent::SPAWN);
		return $ghastling;
	}
}
