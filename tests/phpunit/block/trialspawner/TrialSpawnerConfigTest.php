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
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use function count;

final class TrialSpawnerConfigTest extends TestCase{

	public function testAllVanillaConfigsHaveAMob() : void{
		self::assertCount(28, TrialSpawnerConfigs::getNames());
		foreach(TrialSpawnerConfigs::getNames() as $name){
			$config = TrialSpawnerConfigs::get($name);
			self::assertNotNull($config, $name);
			self::assertGreaterThan(0, count($config->getSpawnPotentials()), $name);
			self::assertNotNull($config->pickSpawnData()?->getEntityId(), $name);
		}
	}

	public function testBreezeCounts() : void{
		$config = TrialSpawnerConfigs::get("trial_chamber/breeze/normal");
		self::assertNotNull($config);
		self::assertSame("minecraft:breeze", $config->pickSpawnData()?->getEntityId());
		self::assertSame(2, $config->calculateTargetTotalMobs(0));
		self::assertSame(1, $config->calculateTargetSimultaneousMobs(0));
		self::assertSame(4, $config->calculateTargetTotalMobs(2));
		self::assertSame(2, $config->calculateTargetSimultaneousMobs(2));
		self::assertSame(20, $config->getTicksBetweenSpawn());
		self::assertSame(TrialSpawnerConfig::DEFAULT_SPAWN_RANGE, $config->getSpawnRange());
	}

	public function testDefaults() : void{
		$config = TrialSpawnerConfigs::get("minecraft:trial_chamber/melee/zombie/normal");
		self::assertNotNull($config);
		self::assertSame(6, $config->calculateTargetTotalMobs(0));
		self::assertSame(3, $config->calculateTargetSimultaneousMobs(0));
		self::assertSame(3, $config->calculateTargetSimultaneousMobs(1)); //3.5
		self::assertSame([[TrialSpawnerConfig::LOOT_CONSUMABLES, 1], [TrialSpawnerConfig::LOOT_KEY, 1]], $config->getLootTablesToEject());

		$ominous = TrialSpawnerConfigs::get("minecraft:trial_chamber/melee/zombie/ominous");
		self::assertNotNull($ominous);
		self::assertSame("minecraft:equipment/trial_chamber_melee", $ominous->pickSpawnData()?->getEquipmentLootTable());
		self::assertSame(["minecraft:spawners/ominous/trial_chamber/key", 3], $ominous->getLootTablesToEject()[0]);
	}

	public function testSpawnDataFields() : void{
		$baby = TrialSpawnerConfigs::get("minecraft:trial_chamber/small_melee/baby_zombie/normal")?->pickSpawnData();
		self::assertNotNull($baby);
		self::assertTrue($baby->isBaby());
		self::assertFalse($baby->hasOnlyEntityId());

		$slime = TrialSpawnerConfigs::get("minecraft:trial_chamber/small_melee/slime/normal");
		self::assertNotNull($slime);
		$sizes = [];
		foreach($slime->getSpawnPotentials() as [$data, $weight]){
			$sizes[$data->getSlimeSize()] = $weight;
		}
		self::assertSame([2 => 3, 3 => 1], $sizes);

		self::assertTrue(TrialSpawnerSpawnData::ofEntity("minecraft:zombie")->hasOnlyEntityId());
	}

	public function testNbtRoundTrip() : void{
		$config = TrialSpawnerConfigs::get("minecraft:trial_chamber/slow_ranged/skeleton/ominous");
		self::assertNotNull($config);
		$copy = TrialSpawnerConfig::fromNbt($config->toNbt());
		self::assertEquals($config, $copy);
	}

	/**
	 * The first Bedrock trial chamber templates (1.21.0) store the configs inline.
	 */
	public function testInlineBedrockConfig() : void{
		$nbt = CompoundTag::create()
			->setInt("ticks_between_spawn", 20)
			->setFloat("simultaneous_mobs", 1)
			->setFloat("simultaneous_mobs_added_per_player", 0.5)
			->setFloat("total_mobs", 2)
			->setFloat("total_mobs_added_per_player", 1)
			->setTag("spawn_potentials", new ListTag([
				CompoundTag::create()
					->setTag("data", CompoundTag::create()->setTag("entity", CompoundTag::create()->setString("id", "minecraft:breeze")))
					->setInt("weight", 1)
			], NBT::TAG_Compound));
		$config = TrialSpawnerConfig::fromNbt($nbt);
		self::assertSame("minecraft:breeze", $config->pickSpawnData()?->getEntityId());
		self::assertSame(3, $config->calculateTargetTotalMobs(1));
		self::assertSame(1, $config->calculateTargetSimultaneousMobs(1));
	}

	public function testWithSpawning() : void{
		$config = TrialSpawnerConfigs::getDefault();
		self::assertNull($config->pickSpawnData());
		$pig = $config->withSpawning("minecraft:pig");
		self::assertSame("minecraft:pig", $pig->pickSpawnData()?->getEntityId());
		self::assertNull($config->pickSpawnData(), "the original is unchanged");
		self::assertSame(6, $pig->calculateTargetTotalMobs(0));
	}
}
