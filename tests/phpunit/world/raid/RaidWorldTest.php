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

namespace pocketmine\world\raid;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Raider;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\OminousBottle;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\dimension\DimensionWorldTestCase;

/**
 * Villages built from blocks, raids and raiders in a real (in-memory) world.
 */
final class RaidWorldTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 63;

	private RaidManager $raids;

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		//grass floor over chunks -2..5 x -2..2 (x -32..95, z -32..47)
		$this->fill(-32, self::FLOOR_Y, -32, 95, self::FLOOR_Y, 47, VanillaBlocks::GRASS());
		$this->raids = new RaidManager($this->world, new RaidStore(null), true);
	}

	private function placeBed(int $x, int $z) : void{
		//foot at x,z, head one block south
		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set($x, self::FLOOR_Y + 1, $z, (clone $bed)->setHead(false));
		$this->set($x, self::FLOOR_Y + 1, $z + 1, (clone $bed)->setHead(true));
	}

	private function buildVillage() : void{
		$this->placeBed(10, 10);
		$this->placeBed(14, 10);
		$this->set(12, self::FLOOR_Y + 1, 14, VanillaBlocks::BELL());
		$this->set(8, self::FLOOR_Y + 1, 14, VanillaBlocks::COMPOSTER());
	}

	public function testScannerFindsPoisOnce() : void{
		$this->buildVillage();
		$pois = VillageScanner::findPois($this->world, 0, self::FLOOR_Y - 5, 0, 30, self::FLOOR_Y + 5, 30);
		$byType = [];
		foreach($pois as [$type, $pos]){
			$byType[$type->value][] = $pos;
		}
		self::assertCount(2, $byType[PoiType::BED->value], "one POI per bed, at its head");
		self::assertTrue($byType[PoiType::BED->value][0]->equals(new Vector3(10, self::FLOOR_Y + 1, 11)));
		self::assertCount(1, $byType[PoiType::BELL->value]);
		self::assertCount(1, $byType[PoiType::JOB_SITE->value]);
	}

	public function testVillageFromBlocks() : void{
		self::assertNull(VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12)));
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		self::assertCount(2, $village->getBeds());
		self::assertNotNull(VillageScanner::findVillageForOmen($this->world, new Vector3(30, self::FLOOR_Y + 1, 30)));
		self::assertNull(VillageScanner::findVillageForOmen($this->world, new Vector3(60, self::FLOOR_Y + 1, 12)));

		$villager = new VillagerV2(new Location(12, self::FLOOR_Y + 1, 12, $this->world, 0, 0));
		$villager->spawnToAll();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		self::assertSame(1, $village->getVillagerCount());
	}

	public function testWaveSpawnsRaiders() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village, 2);
		//the test world's data is a mock (difficulty 0): only the number of waves depends on it
		self::assertSame(RaidWaves::getWaveCount($this->world->getDifficulty()), $raid->getTotalWaves());
		self::assertTrue($raid->checkVillage($this->world));

		$spot = $raid->findSpawnSpot($this->world);
		self::assertNotNull($spot);
		self::assertSame(self::FLOOR_Y + 1, (int) $spot->y);

		$raid->spawnNextWave($this->world, []);
		self::assertSame(1, $raid->getWave());
		self::assertSame(4, $raid->getRaidersAlive());
		$raiders = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Raider && $entity->getRaidId() === $raid->getId()){
				$raiders[] = $entity;
				self::assertInstanceOf(Pillager::class, $entity);
				self::assertTrue($entity->getRaidCenter()?->equals($raid->getCenter()));
				self::assertFalse($entity->canDespawn(), "raiders don't despawn during the raid");
			}
		}
		self::assertCount(4, $raiders);

		foreach($raiders as $raider){
			$raider->attack(new EntityDamageEvent($raider, EntityDamageEvent::CAUSE_MAGIC, 1000));
		}
		self::assertSame(0, $raid->countRaiders($this->world));
	}

	public function testEachWaveHasOneCaptain() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village);
		for($wave = 1; $wave <= 7; ++$wave){
			$raid->spawnNextWave($this->world, []);
		}
		$captains = [];
		$evokers = 0;
		foreach($this->world->getEntities() as $entity){
			if(!$entity instanceof Raider || $entity->getRaidId() !== $raid->getId()){
				continue;
			}
			self::assertInstanceOf(Mob::class, $entity);
			self::assertSame(SpawnReason::RAID, $entity->getSpawnReason());
			if($entity->isPatrolLeader()){
				self::assertTrue($entity instanceof Pillager || $entity instanceof Vindicator, "only pillagers and vindicators lead waves");
				self::assertTrue($entity->getArmorInventory()->getChestplate()->equals(VanillaItems::OMINOUS_BANNER()));
				$banners = 0;
				foreach($entity->getDrops() as $drop){
					if($drop->equals(VanillaItems::OMINOUS_BANNER())){
						++$banners;
					}
				}
				self::assertSame(1, $banners, "the captain always drops its banner");
				$captains[$entity->getRaidWave()] = ($captains[$entity->getRaidWave()] ?? 0) + 1;
			}
			if($entity instanceof Evoker){
				++$evokers;
				self::assertTrue($entity->isPersistent(), "raid evokers are persistent");
				$entity->leaveRaid();
				self::assertFalse($entity->canDespawn(), "evokers never despawn");
			}elseif($entity->isPersistent()){
				self::fail("raiders other than evokers despawn after the raid");
			}
		}
		self::assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1], $captains);
		self::assertSame(5, $evokers); //waves 5, 6, 7 (2) and a ravager rider in wave 7
	}

	public function testNearbyIllagersJoinTheWave() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$witch = new Witch(new Location(20, self::FLOOR_Y + 1, 20, $this->world, 0, 0));
		$witch->spawnToAll();
		$raid = $this->raids->startRaid($village);
		$raid->spawnNextWave($this->world, []);
		self::assertSame($raid->getId(), $witch->getRaidId());
		self::assertSame(5, $raid->getRaidersAlive());
	}

	public function testBarTitlesAreBdsKeys() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village);
		self::assertSame("raid.name", $raid->getBossBar()->getTitle(), "BDS sends the plain key without %");
		$raid->end($this->world, RaidStatus::LOSS);
		self::assertSame("%raid.name - %raid.defeat", $raid->getBossBar()->getTitle());
	}

	public function testVillageLostWhenBedsAreDestroyed() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village);
		foreach([[10, 10], [10, 11], [14, 10], [14, 11]] as [$x, $z]){
			$this->set($x, self::FLOOR_Y + 1, $z, VanillaBlocks::AIR());
		}
		self::assertFalse($raid->checkVillage($this->world));
	}

	public function testVillageLostWhenItsVillagersDie() : void{
		$this->buildVillage();
		$villager = new VillagerV2(new Location(12, self::FLOOR_Y + 1, 12, $this->world, 0, 0));
		$villager->spawnToAll();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village);
		self::assertTrue($raid->checkVillage($this->world));
		$villager->flagForDespawn();
		$villager->close();
		self::assertFalse($raid->checkVillage($this->world), "a village that had villagers is lost when they are all dead, even with a bell");
	}

	public function testOnlyOneRaidPerVillage() : void{
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $this->raids->startRaid($village);
		self::assertSame($raid, $this->raids->getRaidAt(new Vector3(20, self::FLOOR_Y, 20)));
		self::assertNull($this->raids->getRaidAt(new Vector3(500, self::FLOOR_Y, 500)));
		$this->raids->stopAll();
		self::assertCount(0, $this->raids->getRaids());
	}

	public function testRaidsAreReloaded() : void{
		$store = new RaidStore(null);
		$manager = new RaidManager($this->world, $store, true);
		$this->buildVillage();
		$village = VillageScanner::findVillageAt($this->world, new Vector3(12, self::FLOOR_Y + 1, 12));
		self::assertNotNull($village);
		$raid = $manager->startRaid($village, 3);

		$reloaded = new RaidManager($this->world, $store, true);
		self::assertCount(1, $reloaded->getRaids());
		$copy = $reloaded->getRaid($raid->getId());
		self::assertNotNull($copy);
		self::assertSame(3, $copy->getOmenLevel());
		self::assertTrue($copy->getCenter()->equals($raid->getCenter()));
		self::assertCount(2, $copy->getBeds());
		$next = $reloaded->startRaid($village);
		self::assertGreaterThan($raid->getId(), $next->getId(), "raid IDs are not reused");
	}

	public function testCaptainWearsBannerAndDropsBottle() : void{
		$captain = new Pillager(new Location(0, self::FLOOR_Y + 1, 0, $this->world, 0, 0));
		$captain->setPatrolLeader(true);
		$captain->setPatrolling(true);
		self::assertTrue($captain->getArmorInventory()->getChestplate()->equals(VanillaItems::OMINOUS_BANNER()));

		$drops = $captain->getDrops();
		$banners = 0;
		foreach($drops as $drop){
			if($drop->equals(VanillaItems::OMINOUS_BANNER())){
				++$banners;
			}
			self::assertNotInstanceOf(OminousBottle::class, $drop, "no bottle unless killed by a player");
		}
		self::assertSame(1, $banners);

		$nbt = $captain->saveNBT();
		$copy = new Pillager(new Location(0, self::FLOOR_Y + 1, 0, $this->world, 0, 0), $nbt);
		self::assertTrue($copy->isPatrolLeader());
		self::assertTrue($copy->isPatrolling());
		self::assertTrue($copy->getArmorInventory()->getChestplate()->equals(VanillaItems::OMINOUS_BANNER()));
		$captain->close();
		$copy->close();
	}

	public function testRaidMembershipIsSaved() : void{
		$pillager = new Pillager(new Location(0, self::FLOOR_Y + 1, 0, $this->world, 0, 0));
		$pillager->joinRaid(4, 2, new Vector3(1.5, 64.0, 2.5));
		$nbt = $pillager->saveNBT();
		self::assertInstanceOf(CompoundTag::class, $nbt->getCompoundTag("Raid"));
		$copy = new Pillager(new Location(0, self::FLOOR_Y + 1, 0, $this->world, 0, 0), $nbt);
		self::assertSame(4, $copy->getRaidId());
		self::assertSame(2, $copy->getRaidWave());
		self::assertTrue($copy->getRaidCenter()?->equals(new Vector3(1.5, 64.0, 2.5)));
		$copy->leaveRaid();
		self::assertNull($copy->getRaidId());
		self::assertNull($copy->saveNBT()->getCompoundTag("Raid"));
		$pillager->close();
		$copy->close();
	}

	public function testWitchOnlyTargetsGolems() : void{
		$witch = new Witch(new Location(0, self::FLOOR_Y + 1, 0, $this->world, 0, 0));
		$villager = new VillagerV2(new Location(2, self::FLOOR_Y + 1, 0, $this->world, 0, 0));
		$pillager = new Pillager(new Location(4, self::FLOOR_Y + 1, 0, $this->world, 0, 0));
		$villager->setAge(0); //spawned villagers are babies 5% of the time
		self::assertFalse($witch->isRaidVictim($villager));
		self::assertTrue($pillager->isRaidVictim($villager));
		$villager->setAge(VillagerV2::BABY_AGE);
		self::assertFalse($pillager->isRaidVictim($villager), "babies are left alone");
		foreach([$witch, $villager, $pillager] as $e){
			$e->close();
		}
	}
}
