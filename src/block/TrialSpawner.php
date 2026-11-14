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

use pocketmine\block\tile\TrialSpawner as TileTrialSpawner;
use pocketmine\block\trialspawner\TrialSpawnerLogic;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\SpawnEgg;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Trial spawner (https://minecraft.wiki/w/Trial_Spawner). When players come near, it spawns waves of the mob of its
 * config, then ejects a reward per player and cools down for 30 minutes (see {@link TrialSpawnerLogic}). A player with
 * Trial Omen or Bad Omen makes it ominous until its next cooldown ends (harder fight, ominous rewards and ominous
 * item spawners); its block entity ({@link TileTrialSpawner}) keeps the configs and the fight. It only runs in ticked
 * chunks. Using a spawn egg on it in creative mode sets its mob. Never drops, like vanilla.
 */
class TrialSpawner extends Transparent{
	public const STATE_INACTIVE = 0;
	public const STATE_WAITING_FOR_PLAYERS = 1;
	public const STATE_ACTIVE = 2;
	public const STATE_WAITING_FOR_REWARD_EJECTION = 3;
	public const STATE_EJECTING_REWARD = 4;
	public const STATE_COOLDOWN = 5;

	protected int $spawnerState = self::STATE_INACTIVE;
	protected bool $ominous = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::STATE_INACTIVE, self::STATE_COOLDOWN, $this->spawnerState);
		$w->bool($this->ominous);
	}

	public function getSpawnerState() : int{ return $this->spawnerState; }

	/** @return $this */
	public function setSpawnerState(int $state) : self{
		if($state < self::STATE_INACTIVE || $state > self::STATE_COOLDOWN){
			throw new \InvalidArgumentException("Trial spawner state must be in range " . self::STATE_INACTIVE . " ... " . self::STATE_COOLDOWN);
		}
		$this->spawnerState = $state;
		return $this;
	}

	public function isOminous() : bool{ return $this->ominous; }

	/** @return $this */
	public function setOminous(bool $ominous) : self{
		$this->ominous = $ominous;
		return $this;
	}

	public function getLightLevel() : int{
		return match($this->spawnerState){
			self::STATE_INACTIVE, self::STATE_COOLDOWN => 4,
			default => 8
		};
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	/**
	 * A spawn egg used in creative mode sets the mob (vanilla: both configs) and restarts the spawner.
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$entityTypeId = $item instanceof SpawnEgg ? $item->getEntityTypeId() : null;
		if($entityTypeId === null || $player === null || !$player->isCreative(true)){
			return false;
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileTrialSpawner){
			return false;
		}
		$tile->setEntityToSpawn($entityTypeId);
		$tile->getHost()->clearMobs();
		$this->spawnerState = self::STATE_INACTIVE;
		$world->setBlock($this->position, $this); //also resends the tile
		$world->scheduleDelayedBlockUpdate($this->position, 1);
		return true;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true;
	}

	/**
	 * Runs the spawner (its tile is the state machine's data) and schedules its next update.
	 */
	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileTrialSpawner){
			return;
		}
		//several update chains may be started (tile loaded, spawn egg...): keep a single one
		$now = $world->getServer()->getTick();
		if($tile->getLastUpdateTick() === $now){
			return;
		}
		$tile->setLastUpdateTick($now);

		$host = $tile->getHost();
		$host->setBlock($this);
		$data = $tile->getData();
		$wasOminous = $this->ominous; //the host changes it when the spawner becomes ominous or normal
		$state = TrialSpawnerLogic::tick($this->spawnerState, $data, $host);
		if($state !== $this->spawnerState || $wasOminous !== $this->ominous){
			$this->spawnerState = $state;
			$world->setBlock($this->position, $this);
		}
		$delay = TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host);
		if($delay !== null){
			$world->scheduleDelayedBlockUpdate($this->position, $delay);
		}
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
