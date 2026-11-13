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

/**
 * What {@link TrialSpawnerLogic} needs from the world around a trial spawner: its configs and settings, the time,
 * the players nearby, spawning mobs and ejecting rewards, and the effects players see.
 * @internal
 */
interface TrialSpawnerHost{

	/** Current game time in ticks. */
	public function getGameTime() : int;

	/** Offset of this spawner's player scans, so that spawners don't all scan on the same tick (0-19). */
	public function getScanOffset() : int;

	/** The config in use (normal or ominous). */
	public function getActiveConfig() : TrialSpawnerConfig;

	public function getRequiredPlayerRange() : int;

	public function getTargetCooldownLength() : int;

	/** Whether mobs may spawn: not on Peaceful, mob spawning enabled. */
	public function canSpawnInLevel() : bool;

	/**
	 * Returns the IDs (UUIDs) of the players in range that take part in a fight: not in creative or spectator mode,
	 * and seeing the spawner if $requireLineOfSight.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function detectPlayers(int $range, bool $requireLineOfSight) : array;

	/**
	 * Tries to spawn a mob of the data around the spawner. Returns its persistent ID, or null if it couldn't spawn.
	 */
	public function spawnMob(TrialSpawnerSpawnData $data, TrialSpawnerConfig $config) : ?int;

	/** Whether a mob of the spawner no longer counts: dead, unloaded, or too far away. */
	public function shouldMobBeUntracked(int $mobId) : bool;

	/** Ejects the items of one roll of the loot table. */
	public function ejectReward(string $lootTable) : void;

	/** New players joined the fight ($players: how many take part now). */
	public function onPlayersDetected(int $players) : void;

	public function onShutterOpened() : void;

	public function onShutterClosed() : void;

	/** The mob shown in the spawner (next spawn data) changed. */
	public function onSpawnDataChanged() : void;

	/** Whether the spawner is ominous (its ominous config is the active one). */
	public function isOminous() : bool;

	/**
	 * Whether any player of the world has Bad Omen or Trial Omen: a cheap check before looking for them in range.
	 */
	public function hasPlayerWithOmen() : bool;

	/**
	 * Looks among the players (IDs from {@link TrialSpawnerHost::detectPlayers()}) for one with Trial Omen, or else
	 * one with Bad Omen, whose Bad Omen then turns into Trial Omen. Returns whether one was found.
	 *
	 * @param string[] $players
	 * @phpstan-param list<string> $players
	 */
	public function tryConsumeOmen(array $players) : bool;

	/**
	 * Makes the spawner ominous (block state, effects); the mobs of the fight so far (persistent IDs) vanish.
	 *
	 * @param int[] $mobs
	 * @phpstan-param list<int> $mobs
	 */
	public function applyOminous(array $mobs) : void;

	/** Makes the spawner normal again. */
	public function removeOminous() : void;

	/**
	 * Tries to create an ominous item spawner above one of the players taking part (UUIDs) or one of the mobs
	 * (persistent IDs). Returns whether it did.
	 *
	 * @param string[] $players
	 * @param int[]    $mobs
	 * @phpstan-param list<string> $players
	 * @phpstan-param list<int>    $mobs
	 */
	public function spawnOminousItemSpawner(array $players, array $mobs) : bool;
}
