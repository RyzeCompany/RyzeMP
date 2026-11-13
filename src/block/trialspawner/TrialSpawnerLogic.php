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

use pocketmine\block\TrialSpawner;
use function array_keys;
use function count;
use function max;
use function min;

/**
 * The trial spawner state machine (vanilla TrialSpawner.tickServer and TrialSpawnerState.tickAndGetNext):
 * - inactive: no mob to spawn (until a spawn egg is used on it);
 * - waiting for players: every 20 ticks it looks for players within 14 blocks that see it (survival or adventure);
 * - active: it spawns its mob every ticks_between_spawn ticks (the first one 40 ticks after a player is detected),
 *   keeping at most simultaneous_mobs (+ simultaneous_mobs_added_per_player per extra player) alive, until
 *   total_mobs (+ total_mobs_added_per_player per extra player) were spawned. Players who come later join the fight.
 *   Mobs that die, unload or go more than 47 blocks away stop counting;
 * - waiting for reward ejection: once all of them are dead, it waits 40 ticks and opens;
 * - ejecting reward: one roll of a loot table picked once (loot_tables_to_eject) per player who took part, 30 ticks
 *   apart;
 * - cooldown: until the 30 minutes (target_cooldown_length) from the end of the fight are over.
 *
 * Ominous mode (vanilla TrialSpawnerStateData.tryDetectPlayers): a spawner that is not ominous and sees a player with
 * Trial Omen (or Bad Omen, which turns into Trial Omen) becomes ominous: the fight restarts with the ominous config
 * (even during the cooldown), and every 160 ticks of the fight an ominous item spawner appears above a player or a
 * mob. An ominous spawner looks for no players during its cooldown and becomes normal when it ends.
 * @internal
 */
final class TrialSpawnerLogic{
	public const DETECT_PLAYER_SPAWN_BUFFER = 40;
	public const DEFAULT_TARGET_COOLDOWN_LENGTH = 36000;
	public const DEFAULT_PLAYER_SCAN_RANGE = 14;
	public const MAX_MOB_TRACKING_DISTANCE = 47;
	public const DELAY_BETWEEN_PLAYER_SCANS = 20;
	public const DELAY_BEFORE_EJECT_AFTER_KILLING_LAST_MOB = 40;
	public const TIME_BETWEEN_EACH_EJECTION = 30;

	private function __construct(){
		//NOOP
	}

	/**
	 * Runs the spawner for the current tick and returns its new state (one of the TrialSpawner::STATE_* constants).
	 */
	public static function tick(int $state, TrialSpawnerData $data, TrialSpawnerHost $host) : int{
		$now = $host->getGameTime();
		$config = $host->getActiveConfig();

		$untracked = false;
		foreach($data->currentMobs as $mobId => $_){
			if($host->shouldMobBeUntracked($mobId)){
				unset($data->currentMobs[$mobId]);
				$untracked = true;
			}
		}
		if($untracked){
			$data->nextMobSpawnsAt = $now + $config->getTicksBetweenSpawn();
		}

		switch($state){
			case TrialSpawner::STATE_INACTIVE:
				return self::getOrCreateNextSpawnData($data, $host)?->getEntityId() !== null ? TrialSpawner::STATE_WAITING_FOR_PLAYERS : $state;

			case TrialSpawner::STATE_WAITING_FOR_PLAYERS:
				if(!$host->canSpawnInLevel()){
					$data->resetStatistics();
					return $state;
				}
				if(!self::hasMobToSpawn($data, $host)){
					return TrialSpawner::STATE_INACTIVE;
				}
				self::tryDetectPlayers($data, $host, $state);
				return count($data->detectedPlayers) > 0 ? TrialSpawner::STATE_ACTIVE : $state;

			case TrialSpawner::STATE_ACTIVE:
				if(!$host->canSpawnInLevel()){
					$data->resetStatistics();
					return TrialSpawner::STATE_WAITING_FOR_PLAYERS;
				}
				if(!self::hasMobToSpawn($data, $host)){
					return TrialSpawner::STATE_INACTIVE;
				}
				$additionalPlayers = $data->countAdditionalPlayers();
				self::tryDetectPlayers($data, $host, $state);
				if($host->isOminous() && $now >= $data->cooldownEndsAt && $host->spawnOminousItemSpawner(array_keys($data->detectedPlayers), array_keys($data->currentMobs))){
					$data->cooldownEndsAt = $now + $host->getActiveConfig()->getTicksBetweenItemSpawners();
				}
				if($data->hasFinishedSpawningAllMobs($config, $additionalPlayers)){
					if($data->haveAllCurrentMobsDied()){
						$data->cooldownEndsAt = $now + $host->getTargetCooldownLength();
						$data->totalMobsSpawned = 0;
						$data->nextMobSpawnsAt = 0;
						return TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION;
					}
				}elseif($data->isReadyToSpawnNextMob($now, $config, $additionalPlayers)){
					$spawnData = self::getOrCreateNextSpawnData($data, $host);
					$mobId = $spawnData !== null ? $host->spawnMob($spawnData, $config) : null;
					if($mobId !== null){
						$data->currentMobs[$mobId] = true;
						++$data->totalMobsSpawned;
						$data->nextMobSpawnsAt = $now + $config->getTicksBetweenSpawn();
						$next = $config->pickSpawnData();
						if($next !== null){
							$data->nextSpawnData = $next;
							$host->onSpawnDataChanged();
						}
					}
				}
				return $state;

			case TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION:
				if($data->isReadyToOpenShutter($now, self::DELAY_BEFORE_EJECT_AFTER_KILLING_LAST_MOB, $host->getTargetCooldownLength())){
					$host->onShutterOpened();
					return TrialSpawner::STATE_EJECTING_REWARD;
				}
				return $state;

			case TrialSpawner::STATE_EJECTING_REWARD:
				if(!$data->isReadyToEjectItems($now, self::TIME_BETWEEN_EACH_EJECTION, $host->getTargetCooldownLength())){
					return $state;
				}
				if(count($data->detectedPlayers) === 0){
					$host->onShutterClosed();
					$data->ejectingLootTable = null;
					return TrialSpawner::STATE_COOLDOWN;
				}
				$data->ejectingLootTable ??= $config->pickLootTableToEject();
				if($data->ejectingLootTable !== null){
					$host->ejectReward($data->ejectingLootTable);
				}
				$data->takeRewardedPlayer();
				return $state;

			case TrialSpawner::STATE_COOLDOWN:
				self::tryDetectPlayers($data, $host, $state);
				if(count($data->detectedPlayers) > 0){
					$data->totalMobsSpawned = 0;
					$data->nextMobSpawnsAt = 0;
					return TrialSpawner::STATE_ACTIVE;
				}
				if($data->isCooldownFinished($now)){
					if($host->isOminous()){
						$host->removeOminous();
					}
					$data->reset();
					return TrialSpawner::STATE_WAITING_FOR_PLAYERS;
				}
				return $state;
		}
		return $state;
	}

	/**
	 * Returns in how many ticks the spawner must be ticked again in this state, or null if only an outside change (a
	 * spawn egg) can wake it up. Between these ticks the spawner would do nothing.
	 */
	public static function getNextUpdateDelay(int $state, TrialSpawnerData $data, TrialSpawnerHost $host) : ?int{
		$now = $host->getGameTime();
		switch($state){
			case TrialSpawner::STATE_INACTIVE:
				return null;
			case TrialSpawner::STATE_WAITING_FOR_PLAYERS:
				return self::ticksToNextScan($now, $host->getScanOffset());
			case TrialSpawner::STATE_ACTIVE:
				return 1; //mobs dying and spawning
			case TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION:
				return max(1, $data->cooldownEndsAt - $host->getTargetCooldownLength() + self::DELAY_BEFORE_EJECT_AFTER_KILLING_LAST_MOB - $now);
			case TrialSpawner::STATE_EJECTING_REWARD:
				$elapsed = ($now - ($data->cooldownEndsAt - $host->getTargetCooldownLength())) % self::TIME_BETWEEN_EACH_EJECTION;
				if($elapsed < 0){
					$elapsed += self::TIME_BETWEEN_EACH_EJECTION;
				}
				return self::TIME_BETWEEN_EACH_EJECTION - $elapsed;
			case TrialSpawner::STATE_COOLDOWN:
				$untilEnd = max(1, $data->cooldownEndsAt - $now);
				//ominous mode: players only matter at the end of the cooldown; normal mode: players with an omen too
				return $host->isOminous() ? $untilEnd : min($untilEnd, self::ticksToNextScan($now, $host->getScanOffset()));
		}
		return null;
	}

	public static function ticksToNextScan(int $now, int $scanOffset) : int{
		$phase = ($scanOffset + $now) % self::DELAY_BETWEEN_PLAYER_SCANS;
		if($phase < 0){
			$phase += self::DELAY_BETWEEN_PLAYER_SCANS;
		}
		return self::DELAY_BETWEEN_PLAYER_SCANS - $phase;
	}

	private static function hasMobToSpawn(TrialSpawnerData $data, TrialSpawnerHost $host) : bool{
		return self::getOrCreateNextSpawnData($data, $host)?->getEntityId() !== null || count($host->getActiveConfig()->getSpawnPotentials()) > 0;
	}

	/**
	 * The mob the spawner spawns next (and shows), picked from the config the first time.
	 */
	private static function getOrCreateNextSpawnData(TrialSpawnerData $data, TrialSpawnerHost $host) : ?TrialSpawnerSpawnData{
		if($data->nextSpawnData === null){
			$data->nextSpawnData = $host->getActiveConfig()->pickSpawnData();
			if($data->nextSpawnData !== null){
				$host->onSpawnDataChanged();
			}
		}
		return $data->nextSpawnData;
	}

	/**
	 * Every 20 ticks (staggered by spawner), adds the players in range to the fight: the first ones must see the
	 * spawner, those who come once the fight started need not. New players delay the next spawn by 40 ticks at least.
	 * A spawner that is not ominous becomes ominous when it sees a player with an omen; during the cooldown, only such
	 * a player starts a fight.
	 */
	private static function tryDetectPlayers(TrialSpawnerData $data, TrialSpawnerHost $host, int $state) : void{
		$now = $host->getGameTime();
		if(($host->getScanOffset() + $now) % self::DELAY_BETWEEN_PLAYER_SCANS !== 0){
			return;
		}
		$ominous = $host->isOminous();
		$omenPossible = !$ominous && $host->hasPlayerWithOmen();
		if($state === TrialSpawner::STATE_COOLDOWN && !$omenPossible){
			return;
		}
		$range = $host->getRequiredPlayerRange();
		$searchingFirst = count($data->detectedPlayers) === 0;
		//players who see the spawner: needed for the first ones and for omens (vanilla always looks for them)
		$inSight = $searchingFirst || $omenPossible ? $host->detectPlayers($range, true) : [];
		$becameOminous = false;
		if($omenPossible && count($inSight) > 0 && $host->tryConsumeOmen($inSight)){
			$host->applyOminous(array_keys($data->currentMobs));
			$data->resetAfterBecomingOminous($now, $host->getActiveConfig());
			$becameOminous = true;
		}
		if($state === TrialSpawner::STATE_COOLDOWN && !$becameOminous){
			return;
		}
		$added = false;
		foreach($searchingFirst ? $inSight : $host->detectPlayers($range, false) as $player){
			if(!isset($data->detectedPlayers[$player])){
				$data->detectedPlayers[$player] = true;
				$added = true;
			}
		}
		if($added){
			$data->nextMobSpawnsAt = max($now + self::DETECT_PLAYER_SPAWN_BUFFER, $data->nextMobSpawnsAt);
			if(!$becameOminous){
				$host->onPlayersDetected(count($data->detectedPlayers));
			}
		}
	}
}
