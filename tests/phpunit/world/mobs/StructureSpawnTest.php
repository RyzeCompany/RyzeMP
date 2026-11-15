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

namespace pocketmine\world\mobs;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\Living;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Witch;
use pocketmine\world\dimension\DimensionWorldTestCase;
use pocketmine\world\format\LightArray;
use pocketmine\world\mobs\spawn\SpawnBlockInfo;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\mobs\spawn\StructureSpawnArea;
use pocketmine\world\World;
use function count;
use function floor;
use function mt_rand;
use function mt_srand;

/**
 * Structure spawns (swamp huts, outposts, fortresses, monuments): caps, despawning, herd spreading, light and
 * difficulty; village cats.
 */
final class StructureSpawnTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 63;

	private int $difficulty = World::DIFFICULTY_NORMAL;

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		SpawnBlockInfo::clearCache();
		MobDespawner::clearCache();
		$this->fill(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y, 31, VanillaBlocks::NETHER_BRICKS());
		$worldData = $this->world->getProvider()->getWorldData();
		self::assertInstanceOf(MockObject::class, $worldData);
		$worldData->method("getDifficulty")->willReturnCallback(fn() => $this->difficulty);
		for($cx = -1; $cx <= 1; ++$cx){
			for($cz = -1; $cz <= 1; ++$cz){
				$this->world->getChunk($cx, $cz)?->setLightPopulated();
			}
		}
		mt_srand(1234);
	}

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	private function spawner() : NaturalMobSpawner{
		$spawner = new NaturalMobSpawner($this->world);
		$spawner->setRules(SpawnRules::builtin());
		return $spawner;
	}

	/**
	 * Runs structure attempts in random columns of the area, one per tick, and returns every mob spawned.
	 *
	 * @return Living[]
	 * @phpstan-return list<Living>
	 */
	private function runAttempts(NaturalMobSpawner $spawner, StructureSpawnArea $area, int $attempts) : array{
		$all = [];
		for($i = 0; $i < $attempts; ++$i){
			++$this->tick;
			foreach($spawner->structureAttemptAt($area, mt_rand($area->minX, $area->maxX), mt_rand($area->minZ, $area->maxZ)) as $mob){
				$all[] = $mob;
			}
		}
		return $all;
	}

	private function countAlive(string $class) : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && $entity->isAlive() && !$entity->isClosed()){
				++$count;
			}
		}
		return $count;
	}

	public function testHutWitchesAreCountedAndCapped() : void{
		$spawner = $this->spawner();
		$hut = new StructureSpawnArea(0, self::FLOOR_Y - 2, 0, 6, self::FLOOR_Y + 8, 8, [["minecraft:witch", 1, 1, 1]], SpawnCondition::POPULATION_MONSTER, false, 7);
		$witches = $this->runAttempts($spawner, $hut, 200);
		self::assertGreaterThan(0, count($witches));
		foreach($witches as $witch){
			self::assertInstanceOf(Witch::class, $witch);
			self::assertFalse($witch->isPersistent(), "hut witches despawn (witch.json despawn_from_distance)");
		}
		//structure mobs: underground monster cap (16) + 1
		self::assertSame(17, $this->countAlive(Witch::class));
		++$this->tick;
		self::assertSame(17, $spawner->countPopulation(0, 0, SpawnCondition::POPULATION_MONSTER, false));
	}

	public function testOutpostPillagersUseThePillagerCap() : void{
		$spawner = $this->spawner();
		$outpost = new StructureSpawnArea(-16, self::FLOOR_Y - 5, -16, 31, self::FLOOR_Y + 25, 31, [["minecraft:pillager", 1, 1, 1]], SpawnCondition::POPULATION_PILLAGER);
		$this->runAttempts($spawner, $outpost, 200);
		//pillager cap (8) + 1, counted under the population pillagers are filed under
		self::assertSame(9, $this->countAlive(Pillager::class));
		++$this->tick;
		self::assertSame(9, $spawner->countPopulation(0, 0, SpawnCondition::POPULATION_PILLAGER, false));
	}

	public function testHerdMembersSpreadOnDistinctBlocks() : void{
		$spawner = $this->spawner();
		$fortress = new StructureSpawnArea(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y + 6, 31, [["minecraft:zombie_pigman", 1, 4, 4]]);
		$herd = $spawner->structureAttemptAt($fortress, 8, 8);
		self::assertGreaterThan(1, count($herd));
		$blocks = [];
		foreach($herd as $mob){
			$pos = $mob->getPosition();
			self::assertSame((float) self::FLOOR_Y + 1, $pos->y, "members stay on the level of the first one");
			self::assertTrue($fortress->containsColumn((int) floor($pos->x), (int) floor($pos->z)));
			$blocks[World::blockHash((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z))] = true;
		}
		self::assertCount(count($herd), $blocks, "every member on its own block");
	}

	public function testHerdMembersNeedSpawnableBlocks() : void{
		$spawner = $this->spawner();
		//a 1-block wide corridor between walls: members can only go along it
		$this->fill(-16, self::FLOOR_Y + 1, -16, 31, self::FLOOR_Y + 3, 31, VanillaBlocks::NETHER_BRICKS());
		$this->fill(0, self::FLOOR_Y + 1, 8, 31, self::FLOOR_Y + 3, 8, VanillaBlocks::AIR());
		$fortress = new StructureSpawnArea(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y + 6, 31, [["minecraft:zombie_pigman", 1, 4, 4]]);
		for($i = 0; $i < 20; ++$i){
			++$this->tick;
			foreach($spawner->structureAttemptAt($fortress, 8 + $i % 4, 8) as $mob){
				self::assertSame(8, (int) floor($mob->getPosition()->z));
				$mob->close();
			}
		}
	}

	public function testFortressSkeletonsNeedDarkness() : void{
		$spawner = $this->spawner();
		$fortress = new StructureSpawnArea(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y + 6, 31, [["minecraft:wither_skeleton", 1, 5, 5, 7]]);
		$blaze = new StructureSpawnArea(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y + 6, 31, [["minecraft:blaze", 1, 2, 3]]);
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$chunk->getSubChunk((self::FLOOR_Y + 1) >> 4)->setBlockLightArray(LightArray::fill(15));
		self::assertCount(0, $spawner->structureAttemptAt($fortress, 8, 8), "wither skeletons need brightness 7 or less");
		self::assertGreaterThan(0, count($spawner->structureAttemptAt($blaze, 8, 8)), "blazes spawn at any light");

		$chunk->getSubChunk((self::FLOOR_Y + 1) >> 4)->setBlockLightArray(LightArray::fill(0));
		++$this->tick;
		self::assertGreaterThan(0, count($spawner->structureAttemptAt($fortress, 8, 8)));
	}

	public function testNoStructureSpawnsInPeaceful() : void{
		$spawner = $this->spawner();
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$fortress = new StructureSpawnArea(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y + 6, 31, [["minecraft:blaze", 1, 2, 3]]);
		self::assertCount(0, $this->runAttempts($spawner, $fortress, 20));
	}

	public function testVillageCatsDespawn() : void{
		$catSpawner = new VillageCatSpawner($this->world);
		$y = self::FLOOR_Y + 1;
		$cat = (fn() => $this->spawnCat(4, $y, 4))->call($catSpawner);
		self::assertInstanceOf(Cat::class, $cat);
		self::assertFalse($cat->isPersistent(), "untamed village cats despawn (cat.json despawn_from_distance)");
	}
}
