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

use PHPUnit\Framework\TestCase;
use pocketmine\block\TrialSpawner;
use pocketmine\nbt\tag\CompoundTag;
use function array_keys;
use function array_unique;
use function count;
use function max;
use function min;

final class TrialSpawnerLogicTest extends TestCase{

	private function host(string $config = "minecraft:trial_chamber/melee/zombie/normal") : FakeTrialSpawnerHost{
		$normal = TrialSpawnerConfigs::get($config);
		self::assertNotNull($normal);
		return new FakeTrialSpawnerHost($normal);
	}

	/**
	 * Ticks the spawner like the block does (only at the ticks it asks for) until $until, returning the state.
	 */
	private function runUntil(FakeTrialSpawnerHost $host, TrialSpawnerData $data, int $state, int $until) : int{
		while($host->time < $until){
			$delay = TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host) ?? 1;
			$host->time += min($delay, $until - $host->time);
			$state = TrialSpawnerLogic::tick($state, $data, $host);
		}
		return $state;
	}

	public function testInactiveWithoutMob() : void{
		$host = new FakeTrialSpawnerHost(TrialSpawnerConfigs::getDefault());
		$data = new TrialSpawnerData();
		self::assertSame(TrialSpawner::STATE_INACTIVE, TrialSpawnerLogic::tick(TrialSpawner::STATE_INACTIVE, $data, $host));
		self::assertNull(TrialSpawnerLogic::getNextUpdateDelay(TrialSpawner::STATE_INACTIVE, $data, $host));
	}

	public function testWaitsForPlayersOnScanTicks() : void{
		$host = $this->host();
		$data = new TrialSpawnerData();
		$state = TrialSpawnerLogic::tick(TrialSpawner::STATE_INACTIVE, $data, $host);
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $state);
		self::assertSame("minecraft:zombie", $data->nextSpawnData?->getEntityId());

		$host->players = ["a"];
		$host->time = 3; //not a scan tick (offset 0)
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, TrialSpawnerLogic::tick($state, $data, $host));
		self::assertSame(17, TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host));
		$host->time = 20;
		self::assertSame(TrialSpawner::STATE_ACTIVE, TrialSpawnerLogic::tick($state, $data, $host));
		self::assertSame(1, $host->detections);
		self::assertSame(60, $data->nextMobSpawnsAt, "first spawn 40 ticks after detection");
	}

	public function testPeacefulResetsAndDoesNotDetect() : void{
		$host = $this->host();
		$host->canSpawn = false;
		$host->players = ["a"];
		$data = new TrialSpawnerData();
		$host->time = 20;
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, TrialSpawnerLogic::tick(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $data, $host));
		self::assertCount(0, $data->detectedPlayers);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int, int}
	 */
	private function fight(int $players) : array{
		$host = $this->host();
		$data = new TrialSpawnerData();
		$host->players = [];
		for($i = 0; $i < $players; ++$i){
			$host->players[] = "player$i";
		}
		$state = TrialSpawner::STATE_WAITING_FOR_PLAYERS;
		$maxAlive = 0;
		//kill one mob every 25 ticks, as players would
		for($t = 0; $t < 4000 && $state !== TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION; $t += 25){
			$state = $this->runUntil($host, $data, $state, $t);
			$maxAlive = max($maxAlive, count($host->alive));
			if(count($host->alive) > 0){
				$host->kill(array_keys($host->alive)[0]);
			}
		}
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION, $state);
		$fightEnd = $host->time;
		$state = $this->runUntil($host, $data, $state, $fightEnd + 1000);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state);
		$cooldownStart = $data->cooldownEndsAt - 36000;
		self::assertTrue($cooldownStart <= $fightEnd && $cooldownStart > $fightEnd - 25, "30 minutes from the death of the last mob");
		return [$host->spawned, $maxAlive, count($host->ejected)];
	}

	public function testFightScalesWithPlayers() : void{
		//zombie: 6 + 2 per extra player in total, 3 + 0.5 per extra player at once, one reward per player
		[$spawned, $maxAlive, $rewards] = $this->fight(1);
		self::assertSame(6, $spawned);
		self::assertLessThanOrEqual(3, $maxAlive);
		self::assertSame(1, $rewards);

		[$spawned, $maxAlive, $rewards] = $this->fight(3);
		self::assertSame(10, $spawned);
		self::assertLessThanOrEqual(4, $maxAlive);
		self::assertSame(3, $rewards);
	}

	public function testRewardsFromOneTableThirtyTicksApart() : void{
		$host = $this->host();
		$data = new TrialSpawnerData();
		$data->detectedPlayers = ["a" => true, "b" => true];
		$host->time = 1000;
		$data->cooldownEndsAt = 1000 + 36000;
		$state = TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION;
		$state = $this->runUntil($host, $data, $state, 1039);
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_REWARD_EJECTION, $state);
		$state = $this->runUntil($host, $data, $state, 1040);
		self::assertSame(TrialSpawner::STATE_EJECTING_REWARD, $state);
		self::assertSame(1, $host->shutterOpened);
		$state = $this->runUntil($host, $data, $state, 1100);
		self::assertSame([1060, 1090], array_keys($host->ejectTimes));
		self::assertCount(1, array_unique($host->ejected), "same table for every player");
		$state = $this->runUntil($host, $data, $state, 1120);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state);
		self::assertSame(1, $host->shutterClosed);

		//back to waiting once the cooldown is over
		$state = $this->runUntil($host, $data, $state, 1000 + 35999);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state);
		$state = $this->runUntil($host, $data, $state, 1000 + 36000);
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $state);
	}

	public function testMobsTooFarStopCountingAndDelayTheNextSpawn() : void{
		$host = $this->host();
		$data = new TrialSpawnerData();
		$data->currentMobs = [5 => true];
		$host->alive = [5 => true];
		$host->time = 100;
		$data->nextMobSpawnsAt = 1000; //no spawn now
		TrialSpawnerLogic::tick(TrialSpawner::STATE_ACTIVE, $data, $host);
		self::assertCount(1, $data->currentMobs);
		unset($host->alive[5]); //died, unloaded or went 47 blocks away
		$host->time = 101;
		$data->detectedPlayers = ["a" => true];
		TrialSpawnerLogic::tick(TrialSpawner::STATE_ACTIVE, $data, $host);
		self::assertCount(0, $data->currentMobs);
		self::assertSame(121, $data->nextMobSpawnsAt);
	}

	public function testSaveDataRoundTrip() : void{
		$data = new TrialSpawnerData();
		$data->detectedPlayers = ["5e0b2f8a-0000-4000-8000-000000000001" => true];
		$data->currentMobs = [123456789 => true];
		$data->totalMobsSpawned = 4;
		$data->cooldownEndsAt = 1500;
		$data->nextMobSpawnsAt = 1020;
		$data->nextSpawnData = TrialSpawnerSpawnData::ofEntity("minecraft:breeze");
		$data->ejectingLootTable = TrialSpawnerConfig::LOOT_KEY;
		$nbt = new CompoundTag();
		$data->writeSaveData($nbt, 1000);

		$loaded = new TrialSpawnerData();
		$loaded->readSaveData($nbt, 50); //the server ticks start again after a restart
		self::assertSame($data->detectedPlayers, $loaded->detectedPlayers);
		self::assertSame($data->currentMobs, $loaded->currentMobs);
		self::assertSame(4, $loaded->totalMobsSpawned);
		self::assertSame(550, $loaded->cooldownEndsAt);
		self::assertSame(70, $loaded->nextMobSpawnsAt);
		self::assertSame("minecraft:breeze", $loaded->nextSpawnData?->getEntityId());
		self::assertSame(TrialSpawnerConfig::LOOT_KEY, $loaded->ejectingLootTable);
	}
}
