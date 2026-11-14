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

use pocketmine\block\tile\MonsterSpawner as TileMonsterSpawner;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\Entity;
use pocketmine\entity\SpawnReason;
use pocketmine\math\Vector3;
use function array_filter;
use function count;

final class MonsterSpawnerTest extends MobTestCase{

	private function spawner(string $entityId) : TileMonsterSpawner{
		$this->set(0, 1, 0, VanillaBlocks::MONSTER_SPAWNER());
		$tile = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertInstanceOf(TileMonsterSpawner::class, $tile);
		$tile->setEntityTypeId($entityId);
		return $tile;
	}

	private function block() : MonsterSpawner{
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(MonsterSpawner::class, $block);
		return $block;
	}

	private function countPigs() : int{
		return count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Pig && !$e->isClosed()));
	}

	public function testSpawnsUpToTheNearbyLimit() : void{
		$tile = $this->spawner("minecraft:pig");
		self::assertSame(TileMonsterSpawner::DEFAULT_SPAWN_PER_ATTEMPT, $tile->getSpawnPerAttempt());
		//the four spots are random and may all be blocked (inside the floor or the spawner)
		$spawned = 0;
		for($i = 0; $i < 10 && $spawned === 0; ++$i){
			$tile->setSpawnDelay(0);
			$spawned = $this->block()->tickSpawner($tile);
		}
		self::assertGreaterThan(0, $spawned);
		self::assertSame($spawned, $this->countPigs());
		self::assertGreaterThanOrEqual(TileMonsterSpawner::DEFAULT_MIN_SPAWN_DELAY, $tile->getSpawnDelay(), "delay drawn again");

		for($i = 0; $i < 20; ++$i){
			$tile->setSpawnDelay(0);
			$this->block()->tickSpawner($tile);
		}
		self::assertSame(TileMonsterSpawner::DEFAULT_MAX_NEARBY_ENTITIES, $this->countPigs(), "never more than 6 nearby");
	}

	public function testDelayCountsDown() : void{
		$tile = $this->spawner("minecraft:pig");
		$tile->setSpawnDelay(3);
		for($i = 0; $i < 3; ++$i){
			self::assertSame(0, $this->block()->tickSpawner($tile));
			self::assertSame(2 - $i, $tile->getSpawnDelay());
		}
		$spawned = 0;
		for($i = 0; $i < 10 && $spawned === 0; ++$i){
			$spawned = $this->block()->tickSpawner($tile);
		}
		self::assertGreaterThan(0, $spawned);
	}

	public function testSpawnedMobsDespawnLikeNaturalOnes() : void{
		$tile = $this->spawner("minecraft:pig");
		$spawned = 0;
		for($i = 0; $i < 10 && $spawned === 0; ++$i){
			$tile->setSpawnDelay(0);
			$spawned = $this->block()->tickSpawner($tile);
		}
		self::assertGreaterThan(0, $spawned);
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Pig){
				self::assertFalse($entity->isPersistent());
				self::assertSame(SpawnReason::SPAWNER, $entity->getSpawnReason());
			}
		}
	}

	public function testEmptySpawnerDoesNothing() : void{
		$tile = $this->spawner(":");
		$tile->setSpawnDelay(0);
		self::assertSame(0, $this->block()->tickSpawner($tile));
	}
}
