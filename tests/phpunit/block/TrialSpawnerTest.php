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

use pocketmine\block\tile\TileFactory;
use pocketmine\block\tile\TrialSpawner as TileTrialSpawner;
use pocketmine\block\trialspawner\TrialSpawnerConfigs;
use pocketmine\block\trialspawner\TrialSpawnerLogic;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Breeze;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function array_filter;
use function array_values;

final class TrialSpawnerTest extends MobTestCase{

	protected function getPocketmineYml() : array{
		//no player ticks the chunks of the test world: run the block ticks anyway
		return ["chunk-ticking" => ["tick-radius" => 0]] + parent::getPocketmineYml();
	}

	private function spawner(?string $config) : TileTrialSpawner{
		$this->set(0, 1, 0, VanillaBlocks::TRIAL_SPAWNER());
		$tile = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertInstanceOf(TileTrialSpawner::class, $tile);
		$tile->setConfigNames($config, null);
		return $tile;
	}

	/**
	 * @return Entity[]
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed()));
	}

	private function spawnOne(TileTrialSpawner $tile, string $config) : ?int{
		$normal = TrialSpawnerConfigs::get($config);
		self::assertNotNull($normal);
		$host = $tile->getHost();
		//random spots may be inside the floor or the spawner
		for($i = 0; $i < 50; ++$i){
			$data = $normal->pickSpawnData();
			self::assertNotNull($data);
			$id = $host->spawnMob($data, $normal);
			if($id !== null){
				return $id;
			}
		}
		return null;
	}

	public function testSpawnedMobIsPersistentAndTracked() : void{
		$config = "minecraft:trial_chamber/breeze/normal";
		$tile = $this->spawner($config);
		$id = $this->spawnOne($tile, $config);
		self::assertNotNull($id);
		$breezes = $this->entitiesOf(Breeze::class);
		self::assertCount(1, $breezes);
		$breeze = $breezes[0];
		self::assertInstanceOf(Mob::class, $breeze);
		self::assertTrue($breeze->isPersistent());
		self::assertSame(SpawnReason::TRIAL_SPAWNER, $breeze->getSpawnReason());
		self::assertSame($id, $breeze->getPersistentUniqueId());
		$pos = $breeze->getPosition();
		self::assertLessThanOrEqual(4.5, $pos->x < 0 ? -$pos->x : $pos->x);
		self::assertLessThanOrEqual(4.5, $pos->z < 0 ? -$pos->z : $pos->z);

		$host = $tile->getHost();
		self::assertFalse($host->shouldMobBeUntracked($id));
		$breeze->teleport(new Vector3(48.5, 1, 0.5));
		self::assertTrue($host->shouldMobBeUntracked($id), "more than 47 blocks away");
	}

	public function testDeadMobIsUntracked() : void{
		$config = "minecraft:trial_chamber/melee/zombie/normal";
		$tile = $this->spawner($config);
		$id = $this->spawnOne($tile, $config);
		self::assertNotNull($id);
		$zombie = $this->entitiesOf(Zombie::class)[0] ?? null;
		self::assertInstanceOf(Living::class, $zombie);
		$zombie->kill();
		self::assertTrue($tile->getHost()->shouldMobBeUntracked($id));
	}

	public function testSpawnDataVariants() : void{
		$config = "minecraft:trial_chamber/small_melee/baby_zombie/normal";
		$tile = $this->spawner($config);
		self::assertNotNull($this->spawnOne($tile, $config));
		$zombie = $this->entitiesOf(Zombie::class)[0] ?? null;
		self::assertInstanceOf(Zombie::class, $zombie);
		self::assertTrue($zombie->isBaby());

		$config = "minecraft:trial_chamber/small_melee/slime/normal";
		$tile = $this->spawner($config);
		self::assertNotNull($this->spawnOne($tile, $config));
		$slime = $this->entitiesOf(Slime::class)[0] ?? null;
		self::assertInstanceOf(Slime::class, $slime);
		self::assertContains($slime->getSlimeSize(), [2, 3]);
	}

	public function testNothingSpawnsOnPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$tile = $this->spawner("minecraft:trial_chamber/melee/zombie/normal");
		self::assertFalse($tile->getHost()->canSpawnInLevel());
	}

	public function testTileSaveRoundTrip() : void{
		$tile = $this->spawner("minecraft:trial_chamber/breeze/normal");
		$tile->setTargetCooldownLength(1200);
		$nbt = $tile->saveNBT();
		self::assertSame("TrialSpawner", $nbt->getString("id"));
		self::assertSame("minecraft:trial_chamber/breeze/normal", $nbt->getString(TileTrialSpawner::TAG_NORMAL_CONFIG));

		$tile->close();
		$loaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileTrialSpawner::class, $loaded);
		self::assertSame("minecraft:trial_chamber/breeze/normal", $loaded->getNormalConfigName());
		self::assertSame(1200, $loaded->getTargetCooldownLength());
		self::assertSame(TrialSpawnerLogic::DEFAULT_PLAYER_SCAN_RANGE, $loaded->getRequiredPlayerRange());
	}

	public function testSpawnEggSetsTheMob() : void{
		$tile = $this->spawner(null);
		$tile->setEntityToSpawn("minecraft:pig");
		self::assertNull($tile->getNormalConfigName());
		self::assertSame("minecraft:pig", $tile->getNormalConfig()->pickSpawnData()?->getEntityId());
		self::assertSame("minecraft:pig", $tile->getOminousConfig()->pickSpawnData()?->getEntityId());
		$nbt = $tile->saveNBT();
		$tile->close();
		$loaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileTrialSpawner::class, $loaded);
		self::assertSame("minecraft:pig", $loaded->getNormalConfig()->pickSpawnData()?->getEntityId(), "inline config saved");
	}

	public function testWaitsForPlayersWithoutSpawning() : void{
		$this->spawner("minecraft:trial_chamber/melee/zombie/normal");
		$this->runTicks(60);
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(TrialSpawner::class, $block);
		self::assertSame(TrialSpawner::STATE_WAITING_FOR_PLAYERS, $block->getSpawnerState());
		self::assertCount(0, $this->entitiesOf(Zombie::class));
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 1, 0), "keeps checking for players");
	}
}
