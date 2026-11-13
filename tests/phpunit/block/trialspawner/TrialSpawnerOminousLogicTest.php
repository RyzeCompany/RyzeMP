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
use pocketmine\item\VanillaItems;
use function array_keys;
use function count;
use function min;
use function str_contains;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Ominous mode of the trial spawner state machine: omens, the ominous config, ominous item spawners and the return to
 * normal at the end of the cooldown.
 */
final class TrialSpawnerOminousLogicTest extends TestCase{

	private function host(string $mob = "melee/zombie") : FakeTrialSpawnerHost{
		$normal = TrialSpawnerConfigs::get("minecraft:trial_chamber/$mob/normal");
		$ominous = TrialSpawnerConfigs::get("minecraft:trial_chamber/$mob/ominous");
		self::assertNotNull($normal);
		self::assertNotNull($ominous);
		return new FakeTrialSpawnerHost($normal, $ominous);
	}

	private function runUntil(FakeTrialSpawnerHost $host, TrialSpawnerData $data, int $state, int $until) : int{
		while($host->time < $until){
			$delay = TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host) ?? 1;
			$host->time += min($delay, $until - $host->time);
			$state = TrialSpawnerLogic::tick($state, $data, $host);
		}
		return $state;
	}

	private static function nextSpawnData(TrialSpawnerData $data) : ?TrialSpawnerSpawnData{
		return $data->nextSpawnData;
	}

	public function testBadOmenPlayerTurnsSpawnerOminous() : void{
		$host = $this->host();
		$host->players = ["a", "b"];
		$host->omens = ["b" => "bad"];
		$data = new TrialSpawnerData();
		$host->time = 20;
		$state = TrialSpawnerLogic::tick(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $data, $host);
		self::assertSame(TrialSpawner::STATE_ACTIVE, $state);
		self::assertTrue($host->ominous);
		self::assertSame("trial", $host->getOmen("b"), "Bad Omen turned into Trial Omen");
		self::assertSame(["a", "b"], array_keys($data->detectedPlayers));
		self::assertSame(0, $host->detections, "no normal detection effects when becoming ominous");
		self::assertSame(20 + TrialSpawnerConfig::TICKS_BETWEEN_ITEM_SPAWNERS, $data->cooldownEndsAt, "first item spawner timer");
		self::assertSame(60, $data->nextMobSpawnsAt);
		self::assertNull($data->nextSpawnData, "picked again from the ominous config");
		$host->time = 21;
		TrialSpawnerLogic::tick($state, $data, $host);
		$next = self::nextSpawnData($data);
		self::assertNotNull($next);
		self::assertSame("minecraft:zombie", $next->getEntityId());
		self::assertSame("minecraft:equipment/trial_chamber_melee", $next->getEquipmentLootTable());
		self::assertSame(0.0, $next->getEquipmentDropChance());
	}

	public function testPlayersWithoutOmenKeepItNormal() : void{
		$host = $this->host();
		$host->players = ["a"];
		$data = new TrialSpawnerData();
		$host->time = 20;
		self::assertSame(TrialSpawner::STATE_ACTIVE, TrialSpawnerLogic::tick(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $data, $host));
		self::assertFalse($host->ominous);
		self::assertSame(1, $host->detections);
	}

	public function testOminousFightUsesOminousConfigAndCreatesItemSpawners() : void{
		$host = $this->host("breeze");
		$host->players = ["a"];
		$host->omens = ["a" => "trial"];
		$data = new TrialSpawnerData();
		$state = TrialSpawner::STATE_WAITING_FOR_PLAYERS;
		//kill one breeze every 100 ticks
		for($t = 0; $t < 5000 && $state !== TrialSpawner::STATE_COOLDOWN; $t += 100){
			$state = $this->runUntil($host, $data, $state, $t);
			if(count($host->alive) > 0){
				$host->kill(array_keys($host->alive)[0]);
			}
		}
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state);
		self::assertSame(4, $host->spawned, "ominous breeze config: 4 in total for one player (normal: 2)");
		self::assertGreaterThan(0, count($host->itemSpawnerTimes));
		self::assertGreaterThanOrEqual(TrialSpawnerConfig::TICKS_BETWEEN_ITEM_SPAWNERS, $host->itemSpawnerTimes[0]);
		for($i = 1; $i < count($host->itemSpawnerTimes); ++$i){
			self::assertSame(TrialSpawnerConfig::TICKS_BETWEEN_ITEM_SPAWNERS, $host->itemSpawnerTimes[$i] - $host->itemSpawnerTimes[$i - 1]);
		}

		$itemSpawners = count($host->itemSpawnerTimes);
		$state = $this->runUntil($host, $data, $state, $host->time + 1000);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state);
		self::assertCount($itemSpawners, $host->itemSpawnerTimes, "no item spawners after the fight");
		self::assertCount(1, $host->ejected);
		self::assertTrue(str_contains($host->ejected[0], "spawners/ominous/trial_chamber/"), "ominous rewards");
	}

	public function testOminousSpawnerRevertsAtTheEndOfItsCooldown() : void{
		$host = $this->host();
		$host->ominous = true;
		$host->players = ["a"];
		$host->omens = ["a" => "trial"];
		$data = new TrialSpawnerData();
		$host->time = 1000;
		$data->cooldownEndsAt = 5000;
		$state = TrialSpawner::STATE_COOLDOWN;
		self::assertSame(4000, TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host), "ominous: nothing to look for before the end");
		$state = $this->runUntil($host, $data, $state, 4999);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state, "Trial Omen players do not restart an ominous spawner");
		self::assertTrue($host->ominous);
		$state = $this->runUntil($host, $data, $state, 5000);
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $state);
		self::assertFalse($host->ominous);
	}

	public function testOmenRestartsNormalSpawnerDuringCooldown() : void{
		$host = $this->host();
		$host->players = ["a"];
		$data = new TrialSpawnerData();
		$host->time = 1000;
		$data->cooldownEndsAt = 30000;
		$state = TrialSpawner::STATE_COOLDOWN;
		self::assertSame(20, TrialSpawnerLogic::getNextUpdateDelay($state, $data, $host), "normal: scans for omens");
		$state = $this->runUntil($host, $data, $state, 2000);
		self::assertSame(TrialSpawner::STATE_COOLDOWN, $state, "players without omen wait for the end of the cooldown");
		self::assertSame(0, $host->detections);

		$host->omens = ["a" => "bad"];
		$state = $this->runUntil($host, $data, $state, 2020);
		self::assertSame(TrialSpawner::STATE_ACTIVE, $state);
		self::assertTrue($host->ominous);
		self::assertSame(0, $data->nextMobSpawnsAt);
		self::assertSame(2020 + TrialSpawnerConfig::TICKS_BETWEEN_ITEM_SPAWNERS, $data->cooldownEndsAt);
	}

	public function testBecomingOminousMidFightRemovesTheMobs() : void{
		$host = $this->host();
		$host->players = ["a"];
		$data = new TrialSpawnerData();
		$state = $this->runUntil($host, $data, TrialSpawner::STATE_WAITING_FOR_PLAYERS, 120);
		self::assertSame(TrialSpawner::STATE_ACTIVE, $state);
		self::assertGreaterThan(0, count($host->alive));
		self::assertGreaterThan(0, $data->totalMobsSpawned);

		$host->players = ["a", "b"];
		$host->omens = ["b" => "bad"];
		$this->runUntil($host, $data, $state, 140);
		self::assertTrue($host->ominous);
		self::assertSame(1, $host->becameOminous);
		self::assertCount(0, $host->alive, "the normal mobs vanish");
		self::assertCount(0, $data->currentMobs);
		self::assertSame(0, $data->totalMobsSpawned);
		self::assertSame(["a", "b"], array_keys($data->detectedPlayers));
	}

	public function testDispensedItemsAreWeightedByCount() : void{
		$arrow = VanillaItems::ARROW();
		$charge = VanillaItems::SNOWBALL();
		self::assertNull(WorldTrialSpawnerHost::pickDispensedItem([]));
		$counts = [0, 0];
		for($i = 0; $i < 4000; ++$i){
			$item = WorldTrialSpawnerHost::pickDispensedItem([[$arrow, 1], [$charge, 3]]);
			self::assertNotNull($item);
			++$counts[$item->equals($charge) ? 1 : 0];
		}
		self::assertGreaterThan(2700, $counts[1]);
		self::assertGreaterThan(700, $counts[0]);
	}

	public function testSeedHelpersMatchJava() : void{
		//BlockPos.asLong() of the low resolution positions (1, 2, 3) and (-1, -1, -1)
		self::assertSame((1 << 38) | (3 << 12) | 2, WorldTrialSpawnerHost::lowResolutionPosition(30, 40, 90));
		self::assertSame(-1, WorldTrialSpawnerHost::lowResolutionPosition(-1, -1, -1));
		self::assertSame(PHP_INT_MIN, WorldTrialSpawnerHost::addLong(PHP_INT_MAX, 1));
		self::assertSame(5, WorldTrialSpawnerHost::addLong(2, 3));
		self::assertSame(-2, WorldTrialSpawnerHost::addLong(-1, -1));
	}
}
