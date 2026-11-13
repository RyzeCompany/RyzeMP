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

namespace pocketmine\block\vault;

use pocketmine\block\utils\VaultState;
use pocketmine\item\Item;
use function count;
use function max;
use function min;

/**
 * The vault state machine (vanilla VaultBlockEntity.Server and VaultState):
 * - inactive: every 20 ticks it looks for players within 4 blocks that it didn't reward yet;
 * - active: while such players stay within 4.5 blocks; it shows an item of its loot table, a new one every 20 ticks.
 *   Using the key item on it takes the key, rolls the loot table for the player and remembers the player for good;
 * - unlocking: 14 ticks after the key was taken, its shutter opens;
 * - ejecting: 20 ticks later it ejects the items of the reward one by one, 20 ticks apart, then closes and turns on or
 *   off again (the rewarded player no longer counts).
 * @internal
 */
final class VaultLogic{
	/** Ticks between two player scans (vanilla UPDATE_CONNECTED_PLAYERS_TICK_RATE). */
	public const UPDATE_CONNECTED_PLAYERS_TICK_RATE = 20;
	/** Ticks between two items shown by an active vault (vanilla DISPLAY_CYCLE_TICK_RATE). */
	public const DISPLAY_CYCLE_TICK_RATE = 20;
	/** Ticks between the key being taken and the shutter opening (vanilla UNLOCKING_DELAY_TICKS). */
	public const UNLOCKING_DELAY_TICKS = 14;
	public const DELAY_BEFORE_FIRST_EJECTION_TICKS = 20;
	public const DELAY_BETWEEN_EJECTIONS_TICKS = 20;
	/** Ticks during which refused items make no new sound (vanilla INSERT_FAIL_SOUND_BUFFER_TICKS). */
	public const INSERT_FAIL_SOUND_BUFFER_TICKS = 15;

	private function __construct(){
		//NOOP
	}

	/**
	 * Runs the vault for the current tick (vanilla VaultBlockEntity.Server.tick). The block state changes through
	 * {@link VaultHost::setVaultState()}.
	 */
	public static function tick(VaultState $state, VaultConfig $config, VaultData $data, VaultHost $host) : void{
		$now = $host->getGameTime();
		if($state === VaultState::ACTIVE && $now % self::DISPLAY_CYCLE_TICK_RATE === 0){
			self::cycleDisplayItem($state, $config, $data, $host);
		}
		if($now >= $data->stateUpdatingResumesAt){
			$next = self::tickAndGetNext($state, $config, $data, $host);
			if($next !== $state){
				self::setVaultState($state, $next, $config, $data, $host);
			}
		}
	}

	/**
	 * Ticks until the next {@link VaultLogic::tick()} that may do something: the next state update, or the next item
	 * shown while active.
	 */
	public static function getNextUpdateDelay(VaultState $state, VaultData $data, VaultHost $host) : int{
		$now = $host->getGameTime();
		$next = max($now + 1, $data->stateUpdatingResumesAt);
		if($state === VaultState::ACTIVE){
			$cycle = $now - ($now % self::DISPLAY_CYCLE_TICK_RATE) + self::DISPLAY_CYCLE_TICK_RATE;
			$next = min($next, $cycle);
		}
		return max(1, $next - $now);
	}

	private static function tickAndGetNext(VaultState $state, VaultConfig $config, VaultData $data, VaultHost $host) : VaultState{
		return match($state){
			VaultState::INACTIVE => self::updateStateForConnectedPlayers($config, $data, $host, $config->getActivationRange()),
			VaultState::ACTIVE => self::updateStateForConnectedPlayers($config, $data, $host, $config->getDeactivationRange()),
			VaultState::UNLOCKING => self::startEjecting($data, $host),
			VaultState::EJECTING => self::ejectNextItem($config, $data, $host)
		};
	}

	private static function startEjecting(VaultData $data, VaultHost $host) : VaultState{
		$data->stateUpdatingResumesAt = $host->getGameTime() + self::DELAY_BEFORE_FIRST_EJECTION_TICKS;
		return VaultState::EJECTING;
	}

	private static function ejectNextItem(VaultConfig $config, VaultData $data, VaultHost $host) : VaultState{
		$item = $data->popNextItemToEject();
		if($item === null){
			$data->markEjectionFinished();
			return self::updateStateForConnectedPlayers($config, $data, $host, $config->getDeactivationRange());
		}
		$host->ejectItem($item);
		$data->setDisplayItem($data->getNextItemToEject());
		$data->stateUpdatingResumesAt = $host->getGameTime() + self::DELAY_BETWEEN_EJECTIONS_TICKS;
		return VaultState::EJECTING;
	}

	private static function updateStateForConnectedPlayers(VaultConfig $config, VaultData $data, VaultHost $host, float $range) : VaultState{
		$data->setConnectedPlayers($host->detectPlayers($range));
		$data->stateUpdatingResumesAt = $host->getGameTime() + self::UPDATE_CONNECTED_PLAYERS_TICK_RATE;
		return $data->hasConnectedPlayers() ? VaultState::ACTIVE : VaultState::INACTIVE;
	}

	private static function setVaultState(VaultState $from, VaultState $to, VaultConfig $config, VaultData $data, VaultHost $host) : void{
		$host->setVaultState($to);
		//vanilla VaultState.onExit() and onEnter()
		if($from === VaultState::EJECTING){
			$host->onShutter(false);
		}
		switch($to){
			case VaultState::INACTIVE:
				$data->setDisplayItem(null);
				$host->onActivated(false, $host->isOminous());
				break;
			case VaultState::ACTIVE:
				if($data->getDisplayItem() === null){
					self::cycleDisplayItem($to, $config, $data, $host);
				}
				$host->onActivated(true, $host->isOminous());
				break;
			case VaultState::UNLOCKING:
				$host->onKeyInserted();
				break;
			case VaultState::EJECTING:
				$host->onShutter(true);
				break;
		}
	}

	/**
	 * Shows an item of the loot table (vanilla cycleDisplayItemFromLootTable); nothing if the vault can't be opened.
	 */
	private static function cycleDisplayItem(VaultState $state, VaultConfig $config, VaultData $data, VaultHost $host) : void{
		if(!self::canEjectReward($config, $state)){
			$data->setDisplayItem(null);
			return;
		}
		$data->setDisplayItem($host->rollDisplayItem($config->getLootTableToDisplay()));
	}

	private static function canEjectReward(VaultConfig $config, VaultState $state) : bool{
		return $config->hasKey() && $state !== VaultState::INACTIVE;
	}

	/**
	 * A player used an item on the vault (vanilla VaultBlockEntity.Server.tryInsertKey). Returns how many items to take
	 * from the player's stack: the count of the key if the vault opened, 0 otherwise.
	 */
	public static function tryInsertKey(VaultState $state, VaultConfig $config, VaultData $data, VaultHost $host, string $playerUuid, int $playerEntityId, Item $item) : int{
		if(!self::canEjectReward($config, $state)){
			return 0;
		}
		if(!$config->isKey($item)){
			self::playInsertFailSound($data, $host, false);
			return 0;
		}
		if($data->hasRewardedPlayer($playerUuid)){
			self::playInsertFailSound($data, $host, true);
			return 0;
		}
		$rewards = $host->rollRewards($config->getLootTable(), $playerEntityId, $item);
		if(count($rewards) === 0){
			return 0;
		}
		//vanilla unlock()
		$data->setItemsToEject($rewards);
		$data->setDisplayItem($data->getNextItemToEject());
		$data->stateUpdatingResumesAt = $host->getGameTime() + self::UNLOCKING_DELAY_TICKS;
		self::setVaultState($state, VaultState::UNLOCKING, $config, $data, $host);
		$data->addRewardedPlayer($playerUuid);
		$data->setConnectedPlayers($host->detectPlayers($config->getDeactivationRange()));
		return $config->getKeyItem()->getCount();
	}

	private static function playInsertFailSound(VaultData $data, VaultHost $host, bool $rewardedPlayer) : void{
		$now = $host->getGameTime();
		if($now >= $data->lastInsertFailTimestamp + self::INSERT_FAIL_SOUND_BUFFER_TICKS){
			$host->onInsertFailed($rewardedPlayer);
			$data->lastInsertFailTimestamp = $now;
		}
	}
}
