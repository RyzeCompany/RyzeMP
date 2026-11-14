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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Filesystem;
use pocketmine\world\dimension\DimensionWorldTestCase;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\mobs\spawn\SpawnBlockInfo;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\WorldCreationOptions;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Spawner parts that need a world: block classification, natural mobs and their counting, despawning.
 */
final class SpawnWorldTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 63;

	/**
	 * @var mixed[][]
	 * @phpstan-var list<array{string, int, bool, bool}>
	 */
	private array $seenEvents = [];
	private bool $cancelEvents = true;

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		SpawnBlockInfo::clearCache();
		MobDespawner::clearCache();
		$this->fill(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y, 31, VanillaBlocks::GRASS());
	}

	private function flagsAt(int $x, int $y, int $z) : int{
		return SpawnBlockInfo::flags($this->world, $this->world->getChunk($x >> 4, $z >> 4)?->getBlockStateId($x & 15, $y, $z & 15) ?? 0, $x, $y, $z);
	}

	public function testBlockClassification() : void{
		$this->set(0, self::FLOOR_Y + 1, 0, VanillaBlocks::TALL_GRASS());
		$this->set(1, self::FLOOR_Y + 1, 0, VanillaBlocks::OAK_LEAVES());
		$this->set(2, self::FLOOR_Y + 1, 0, VanillaBlocks::GLASS());
		$this->set(3, self::FLOOR_Y + 1, 0, VanillaBlocks::CARPET());
		$this->set(4, self::FLOOR_Y + 1, 0, VanillaBlocks::WATER());
		$this->set(5, self::FLOOR_Y + 1, 0, VanillaBlocks::LAVA());
		$this->set(6, self::FLOOR_Y + 1, 0, VanillaBlocks::OAK_SLAB());

		$grass = $this->flagsAt(0, self::FLOOR_Y, 0);
		self::assertNotSame(0, $grass & SpawnBlockInfo::SOLID_TOP);
		self::assertSame("minecraft:grass_block", SpawnBlockInfo::name($this->world->getChunk(0, 0)?->getBlockStateId(0, self::FLOOR_Y, 0) ?? 0));
		self::assertNotSame(0, $this->flagsAt(0, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::PASSABLE, "tall grass has no collision");
		self::assertNotSame(0, $this->flagsAt(0, self::FLOOR_Y + 2, 0) & SpawnBlockInfo::PASSABLE, "air");
		self::assertSame(0, $this->flagsAt(1, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::SOLID_TOP, "leaves");
		self::assertNotSame(0, $this->flagsAt(1, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::SOLID, "leaves end the surface");
		self::assertSame(0, $this->flagsAt(2, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::SOLID_TOP, "glass");
		self::assertSame(0, $this->flagsAt(3, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::SOLID_TOP, "carpet");
		self::assertNotSame(0, $this->flagsAt(4, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::WATER);
		self::assertNotSame(0, $this->flagsAt(4, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::PASSABLE);
		self::assertNotSame(0, $this->flagsAt(5, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::LAVA);
		self::assertSame(0, $this->flagsAt(6, self::FLOOR_Y + 1, 0) & SpawnBlockInfo::SOLID_TOP, "bottom slab");
	}

	public function testNaturalMobsAreCountedAndNotPersistent() : void{
		$spawner = new NaturalMobSpawner($this->world);
		$rules = SpawnRules::builtin();
		$spawner->setRules($rules);
		$zombieRule = null;
		$cowRule = null;
		foreach($rules->getConditions() as $condition){
			if($condition->identifier === "minecraft:zombie"){
				$zombieRule = $condition;
			}elseif($condition->identifier === "minecraft:cow"){
				$cowRule = $condition;
			}
		}
		self::assertInstanceOf(SpawnCondition::class, $zombieRule);
		self::assertInstanceOf(SpawnCondition::class, $cowRule);

		self::assertSame(0, $spawner->countPopulation(0, 0, "monster", true));
		$spawned = $spawner->spawnRuleAt($cowRule, 5, self::FLOOR_Y + 1, 5);
		self::assertCount(1, $spawned);
		$cow = $spawned[0];
		self::assertInstanceOf(Cow::class, $cow);
		self::assertFalse($cow->isPersistent(), "naturally spawned animals despawn like in Bedrock");

		$this->tick++;
		self::assertSame(1, $spawner->countPopulation(0, 0, "animal", true));
		self::assertSame(1, $spawner->countPopulation(4, 4, "animal", true), "9x9 chunks around the attempt");
		self::assertSame(0, $spawner->countPopulation(5, 0, "animal", true));
		self::assertSame(0, $spawner->countPopulation(0, 0, "animal", false), "surface and underground are counted apart");
		self::assertSame(1, $spawner->countType(0, 0, "minecraft:cow", true));

		//mobs from eggs or breeding are persistent and don't count
		$egg = new Zombie(new Location(8.5, self::FLOOR_Y + 1, 8.5, $this->world, 0, 0));
		$egg->spawnToAll();
		$this->tick++;
		self::assertSame(0, $spawner->countPopulation(0, 0, "monster", true));

		$zombies = $spawner->spawnRuleAt($zombieRule, 10, self::FLOOR_Y + 1, 10, false);
		self::assertCount(1, $zombies);
		$this->tick++;
		self::assertSame(1, $spawner->countPopulation(0, 0, "monster", false));
	}

	public function testDespawnRules() : void{
		$cow = new Cow(new Location(5.5, self::FLOOR_Y + 1, 5.5, $this->world, 0, 0));
		$cow->spawnToAll();
		$far = 0;
		//no player in the world: beyond every instant distance
		self::assertTrue(MobDespawner::shouldDespawn($cow, $far));
		//chunk tick radius of the test world (4 by default): simulation distance 4 => 44 blocks
		self::assertSame(MobDespawner::SMALL_SIMULATION_INSTANT_DISTANCE, MobDespawner::getInstantDistance($this->world));
		self::assertTrue($cow->isPersistent(), "mobs are persistent unless the world spawned them");
	}

	private function cowRule(SpawnRules $rules) : SpawnCondition{
		foreach($rules->getConditions() as $condition){
			if($condition->identifier === "minecraft:cow"){
				return $condition;
			}
		}
		self::fail("no cow rule");
	}

	public function testNaturalSpawnEventCanBeCancelled() : void{
		$spawner = new NaturalMobSpawner($this->world);
		$rules = SpawnRules::builtin();
		$spawner->setRules($rules);
		$this->seenEvents = [];
		$this->cancelEvents = true;
		$listener = new RegisteredListener(function(EntityNaturalSpawnEvent $ev) : void{
			$this->seenEvents[] = [$ev->getEntity()::getNetworkTypeId(), $ev->getCause(), $ev->isSurface(), $ev->getEntity()->isClosed()];
			if($this->cancelEvents){
				$ev->cancel();
			}
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityNaturalSpawnEvent::class)->register($listener);
		try{
			self::assertSame([], $spawner->spawnRuleAt($this->cowRule($rules), 5, self::FLOOR_Y + 1, 5));
			self::assertSame([["minecraft:cow", EntityNaturalSpawnEvent::CAUSE_NATURAL, true, false]], $this->seenEvents);
			self::assertSame(0, $spawner->getSpawnCount());
			$this->tick++;
			self::assertSame(0, $spawner->countPopulation(0, 0, "animal", true), "a cancelled mob is not counted");
			foreach($this->world->getEntities() as $entity){
				self::assertNotInstanceOf(Cow::class, $entity, "a cancelled mob is closed");
			}

			$this->cancelEvents = false;
			self::assertCount(1, $spawner->spawnRuleAt($this->cowRule($rules), 6, self::FLOOR_Y + 1, 6, false));
			self::assertSame(1, $spawner->getSpawnCount());
			self::assertCount(2, $this->seenEvents);
			self::assertFalse($this->seenEvents[1][2], "underground spawn");
		}finally{
			HandlerListManager::global()->getListFor(EntityNaturalSpawnEvent::class)->unregister($listener);
		}
	}

	public function testMobSpawningGameRule() : void{
		self::assertTrue($this->world->isMobSpawningEnabled(), "worlds without the gamerule spawn mobs");
		$this->world->setMobSpawningEnabled(false);
		self::assertFalse($this->world->isMobSpawningEnabled());
		$this->world->setMobSpawningEnabled(true);
		self::assertTrue($this->world->isMobSpawningEnabled());

		$path = sys_get_temp_dir() . "/pm-spawning-test-" . uniqid();
		mkdir($path);
		try{
			BedrockWorldData::generate($path, "spawning", WorldCreationOptions::create());
			$data = new BedrockWorldData($path . "/level.dat");
			self::assertTrue($data->isMobSpawningEnabled(), "domobspawning defaults to on");
			$data->setMobSpawningEnabled(false);
			$data->save();
			self::assertFalse((new BedrockWorldData($path . "/level.dat"))->isMobSpawningEnabled());
		}finally{
			Filesystem::recursiveUnlink($path);
		}
	}
}
