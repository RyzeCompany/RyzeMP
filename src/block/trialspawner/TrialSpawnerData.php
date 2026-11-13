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

namespace pocketmine\block\trialspawner;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Utils;
use function array_key_first;
use function count;
use function max;

/**
 * The fight state of a trial spawner (vanilla TrialSpawnerStateData): the players taking part, the mobs it spawned
 * that are still alive (by persistent entity ID), how many it spawned, and its timers.
 *
 * The timers are server ticks while the spawner is loaded; they are saved as the ticks left (PocketMine has no world
 * age), so a cooldown only runs while the spawner's chunk is loaded.
 */
final class TrialSpawnerData{
	public const TAG_REGISTERED_PLAYERS = "registered_players"; //TAG_List<TAG_String> (UUIDs)
	public const TAG_CURRENT_MOBS = "current_mobs"; //TAG_List<TAG_Long> (persistent entity IDs)
	public const TAG_TOTAL_MOBS_SPAWNED = "total_mobs_spawned"; //TAG_Int
	public const TAG_SPAWN_DATA = "spawn_data"; //TAG_Compound
	public const TAG_SELECTED_LOOT_TABLE = "selected_loot_table"; //TAG_String
	/** Ticks left before the cooldown ends (PocketMine; vanilla saves the end time as cooldown_end_at). */
	public const TAG_COOLDOWN_TICKS_LEFT = "pm_cooldown_ticks_left"; //TAG_Long
	/** Ticks left before the next mob may spawn (PocketMine; vanilla saves next_mob_spawns_at). */
	public const TAG_NEXT_MOB_SPAWN_TICKS = "pm_next_mob_spawn_ticks"; //TAG_Long

	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	public array $detectedPlayers = [];
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	public array $currentMobs = [];
	public int $cooldownEndsAt = 0;
	public int $nextMobSpawnsAt = 0;
	public int $totalMobsSpawned = 0;
	public ?TrialSpawnerSpawnData $nextSpawnData = null;
	public ?string $ejectingLootTable = null;

	/**
	 * Forgets the fight and the mob to spawn (the spawner goes back to waiting for players).
	 */
	public function reset() : void{
		$this->currentMobs = [];
		$this->nextSpawnData = null;
		$this->resetStatistics();
	}

	public function resetStatistics() : void{
		$this->detectedPlayers = [];
		$this->totalMobsSpawned = 0;
		$this->nextMobSpawnsAt = 0;
		$this->cooldownEndsAt = 0;
	}

	/**
	 * Restarts the fight with the ominous config (vanilla resetAfterBecomingOminous): the mobs spawned so far are
	 * forgotten, the mob to spawn is picked again from the ominous config, and the first ominous item spawner comes
	 * after {@link TrialSpawnerConfig::getTicksBetweenItemSpawners()} (during a fight, the cooldown end is the time of
	 * the next item spawner). The players taking part stay.
	 */
	public function resetAfterBecomingOminous(int $now, TrialSpawnerConfig $ominousConfig) : void{
		if(count($ominousConfig->getSpawnPotentials()) > 0){
			$this->nextSpawnData = null;
		}
		$this->totalMobsSpawned = 0;
		$this->currentMobs = [];
		$this->nextMobSpawnsAt = $now + $ominousConfig->getTicksBetweenSpawn();
		$this->cooldownEndsAt = $now + $ominousConfig->getTicksBetweenItemSpawners();
	}

	/**
	 * Additional players beyond the first one (the mob counts grow with them).
	 */
	public function countAdditionalPlayers() : int{
		return max(0, count($this->detectedPlayers) - 1);
	}

	public function hasFinishedSpawningAllMobs(TrialSpawnerConfig $config, int $additionalPlayers) : bool{
		return $this->totalMobsSpawned >= $config->calculateTargetTotalMobs($additionalPlayers);
	}

	public function haveAllCurrentMobsDied() : bool{
		return count($this->currentMobs) === 0;
	}

	public function isReadyToSpawnNextMob(int $now, TrialSpawnerConfig $config, int $additionalPlayers) : bool{
		return $now >= $this->nextMobSpawnsAt && count($this->currentMobs) < $config->calculateTargetSimultaneousMobs($additionalPlayers);
	}

	public function isReadyToOpenShutter(int $now, int $delayBeforeOpen, int $targetCooldownLength) : bool{
		return $now >= $this->cooldownEndsAt - $targetCooldownLength + $delayBeforeOpen;
	}

	public function isReadyToEjectItems(int $now, int $timeBetweenEjections, int $targetCooldownLength) : bool{
		return ($now - ($this->cooldownEndsAt - $targetCooldownLength)) % $timeBetweenEjections === 0;
	}

	public function isCooldownFinished(int $now) : bool{
		return $now >= $this->cooldownEndsAt;
	}

	/**
	 * Removes and returns one of the players still waiting for a reward, or null.
	 */
	public function takeRewardedPlayer() : ?string{
		$key = array_key_first($this->detectedPlayers);
		if($key === null){
			return null;
		}
		unset($this->detectedPlayers[$key]);
		return (string) $key;
	}

	public function readSaveData(CompoundTag $nbt, int $now) : void{
		$this->detectedPlayers = [];
		$players = $nbt->getListTag(self::TAG_REGISTERED_PLAYERS);
		if($players !== null){
			foreach($players as $tag){
				if($tag instanceof StringTag && $tag->getValue() !== ""){
					$this->detectedPlayers[$tag->getValue()] = true;
				}
			}
		}
		$this->currentMobs = [];
		$mobs = $nbt->getListTag(self::TAG_CURRENT_MOBS);
		if($mobs !== null){
			foreach($mobs as $tag){
				if($tag instanceof LongTag){
					$this->currentMobs[$tag->getValue()] = true;
				}
			}
		}
		$this->totalMobsSpawned = max(0, $nbt->getInt(self::TAG_TOTAL_MOBS_SPAWNED, 0));
		$spawnData = $nbt->getCompoundTag(self::TAG_SPAWN_DATA);
		$this->nextSpawnData = $spawnData !== null ? TrialSpawnerSpawnData::fromNbt($spawnData) : null;
		$lootTable = $nbt->getTag(self::TAG_SELECTED_LOOT_TABLE);
		$this->ejectingLootTable = $lootTable instanceof StringTag && $lootTable->getValue() !== "" ? $lootTable->getValue() : null;
		$cooldown = $nbt->getTag(self::TAG_COOLDOWN_TICKS_LEFT);
		$this->cooldownEndsAt = $cooldown instanceof LongTag ? $now + $cooldown->getValue() : 0;
		$nextSpawn = $nbt->getTag(self::TAG_NEXT_MOB_SPAWN_TICKS);
		$this->nextMobSpawnsAt = $nextSpawn instanceof LongTag ? $now + max(0, $nextSpawn->getValue()) : 0;
	}

	public function writeSaveData(CompoundTag $nbt, int $now) : void{
		$players = [];
		foreach(Utils::stringifyKeys($this->detectedPlayers) as $uuid => $_){
			$players[] = new StringTag($uuid);
		}
		$mobs = [];
		foreach($this->currentMobs as $id => $_){
			$mobs[] = new LongTag($id);
		}
		$nbt->setTag(self::TAG_REGISTERED_PLAYERS, new ListTag($players, NBT::TAG_String));
		$nbt->setTag(self::TAG_CURRENT_MOBS, new ListTag($mobs, NBT::TAG_Long));
		$nbt->setInt(self::TAG_TOTAL_MOBS_SPAWNED, $this->totalMobsSpawned);
		if($this->nextSpawnData !== null){
			$nbt->setTag(self::TAG_SPAWN_DATA, $this->nextSpawnData->toNbt());
		}
		if($this->ejectingLootTable !== null){
			$nbt->setString(self::TAG_SELECTED_LOOT_TABLE, $this->ejectingLootTable);
		}
		if($this->cooldownEndsAt !== 0){
			$nbt->setLong(self::TAG_COOLDOWN_TICKS_LEFT, $this->cooldownEndsAt - $now);
		}
		if($this->nextMobSpawnsAt > $now){
			$nbt->setLong(self::TAG_NEXT_MOB_SPAWN_TICKS, $this->nextMobSpawnsAt - $now);
		}
	}
}
