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

use function count;

/**
 * A trial spawner without a world: players are a list of IDs, mobs always spawn and live until killed.
 */
final class FakeTrialSpawnerHost implements TrialSpawnerHost{
	public int $time = 0;
	public bool $canSpawn = true;
	/** @phpstan-var list<string> */
	public array $players = [];
	/** @phpstan-var array<int, true> */
	public array $alive = [];
	public int $spawned = 0;
	public int $detections = 0;
	/** @var string[] */
	public array $ejected = [];
	/** @phpstan-var array<int, string> */
	public array $ejectTimes = [];
	public int $shutterOpened = 0;
	public int $shutterClosed = 0;
	private int $nextMobId = 1;
	public bool $ominous = false;
	public int $becameOminous = 0;
	/**
	 * Players with an omen: "bad" or "trial".
	 * @phpstan-var array<string, string>
	 */
	public array $omens = [];
	/** @phpstan-var list<int> */
	public array $itemSpawnerTimes = [];

	public function __construct(
		private TrialSpawnerConfig $config,
		private ?TrialSpawnerConfig $ominousConfig = null
	){}

	public function getOmen(string $player) : ?string{
		return $this->omens[$player] ?? null;
	}

	public function kill(int $mobId) : void{
		unset($this->alive[$mobId]);
	}

	public function getGameTime() : int{ return $this->time; }

	public function getScanOffset() : int{ return 0; }

	public function getActiveConfig() : TrialSpawnerConfig{ return $this->ominous && $this->ominousConfig !== null ? $this->ominousConfig : $this->config; }

	public function getRequiredPlayerRange() : int{ return TrialSpawnerLogic::DEFAULT_PLAYER_SCAN_RANGE; }

	public function getTargetCooldownLength() : int{ return TrialSpawnerLogic::DEFAULT_TARGET_COOLDOWN_LENGTH; }

	public function canSpawnInLevel() : bool{ return $this->canSpawn; }

	public function detectPlayers(int $range, bool $requireLineOfSight) : array{
		return $this->players;
	}

	public function spawnMob(TrialSpawnerSpawnData $data, TrialSpawnerConfig $config) : int{
		$id = $this->nextMobId++;
		$this->alive[$id] = true;
		++$this->spawned;
		return $id;
	}

	public function shouldMobBeUntracked(int $mobId) : bool{
		return !isset($this->alive[$mobId]);
	}

	public function ejectReward(string $lootTable) : void{
		$this->ejected[] = $lootTable;
		$this->ejectTimes[$this->time] = $lootTable;
	}

	public function onPlayersDetected(int $players) : void{
		++$this->detections;
	}

	public function onShutterOpened() : void{
		++$this->shutterOpened;
	}

	public function onShutterClosed() : void{
		++$this->shutterClosed;
	}

	public function onSpawnDataChanged() : void{

	}

	public function isOminous() : bool{
		return $this->ominous;
	}

	public function hasPlayerWithOmen() : bool{
		return count($this->omens) > 0;
	}

	public function tryConsumeOmen(array $players) : bool{
		$badOmen = null;
		foreach($players as $player){
			if(($this->omens[$player] ?? null) === "trial"){
				return true;
			}
			if(isset($this->omens[$player])){
				$badOmen = $player;
			}
		}
		if($badOmen === null){
			return false;
		}
		$this->omens[$badOmen] = "trial";
		return true;
	}

	public function applyOminous(array $mobs) : void{
		$this->ominous = true;
		++$this->becameOminous;
		foreach($mobs as $mobId){
			unset($this->alive[$mobId]);
		}
	}

	public function removeOminous() : void{
		$this->ominous = false;
	}

	public function spawnOminousItemSpawner(array $players, array $mobs) : bool{
		if(count($players) === 0){
			return false;
		}
		$this->itemSpawnerTimes[] = $this->time;
		return true;
	}
}
