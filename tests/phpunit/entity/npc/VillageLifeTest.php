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

namespace pocketmine\entity\npc;

use pocketmine\block\Bell;
use pocketmine\block\Composter;
use pocketmine\block\Crops;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\villager\ExploreOutskirtsGoal;
use pocketmine\entity\ai\villager\FertilizeCropsGoal;
use pocketmine\entity\ai\villager\MoveIndoorsGoal;
use pocketmine\entity\ai\villager\VillagerGoal;
use pocketmine\entity\ai\villager\WorkAtJobSiteGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\World;
use function abs;
use function array_filter;
use function array_values;
use function count;
use function intdiv;
use function mt_srand;
use function sqrt;

/**
 * Bedrock village life of villagers: professions of new villagers, the avoid list, bells, golem summoning, farmer
 * bone meal and composter, celebrations and the dwelling restriction.
 */
final class VillageLifeTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function placeBed(int $x, int $z) : Vector3{
		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set($x, 1, $z, (clone $bed)->setHead(false));
		$this->set($x, 1, $z + 1, (clone $bed)->setHead(true));
		return new Vector3($x, 1, $z + 1);
	}

	private function villager(float $x, float $z, int $profession = VillagerV2::PROFESSION_UNEMPLOYED) : VillagerV2{
		$villager = new VillagerV2($this->loc($x, 1, $z));
		$villager->setAge(0);
		$villager->setProfession($profession);
		$villager->spawnToAll();
		return $villager;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	public function testVillageAndSpawnEggProfessionRolls() : void{
		mt_srand(42);
		$nitwits = 0;
		for($i = 0; $i < 10000; ++$i){
			$p = VillagerV2::rollVillageProfession();
			self::assertContains($p, [VillagerV2::PROFESSION_UNEMPLOYED, VillagerV2::PROFESSION_NITWIT]);
			$nitwits += $p === VillagerV2::PROFESSION_NITWIT ? 1 : 0;
		}
		self::assertEqualsWithDelta(1000, $nitwits, 150, "spawn_from_village: 10% nitwits");

		$seen = [];
		for($i = 0; $i < 2000; ++$i){
			$seen[VillagerV2::rollSpawnEggProfession()] = true;
		}
		self::assertCount(14, $seen, "entity_spawned: 13 professions and nitwit");
		self::assertArrayNotHasKey(VillagerV2::PROFESSION_UNEMPLOYED, $seen);
	}

	public function testStructureVillagersAreUnemployedOrNitwitsAndOthersPickAProfession() : void{
		mt_srand(7);
		$babies = 0;
		for($i = 0; $i < 200; ++$i){
			$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
			$villager->setSpawnReason(SpawnReason::STRUCTURE);
			if($villager->isBaby()){
				++$babies;
				self::assertSame(VillagerV2::PROFESSION_UNEMPLOYED, $villager->getProfession());
			}else{
				self::assertContains($villager->getProfession(), [VillagerV2::PROFESSION_UNEMPLOYED, VillagerV2::PROFESSION_NITWIT], "a village villager (structure)");
				self::assertSame([], $villager->getTradeOffers());
			}
			$villager->close();
		}
		self::assertLessThan(30, $babies, "about 5% babies");

		//entity_spawned: spawn eggs, the summon command, plugins
		foreach([null, SpawnReason::SPAWN_EGG] as $reason){
			$professions = [];
			for($i = 0; $i < 100; ++$i){
				$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
				if($reason !== null){
					$villager->setSpawnReason($reason);
				}
				if(!$villager->isBaby()){
					$professions[$villager->getProfession()] = true;
					if($villager->getProfession() !== VillagerV2::PROFESSION_NITWIT){
						self::assertNotEmpty($villager->getTradeOffers(), "an egg villager can trade");
					}
				}
				$villager->close();
			}
			self::assertGreaterThan(8, count($professions));
			self::assertArrayNotHasKey(VillagerV2::PROFESSION_UNEMPLOYED, $professions);
		}

		//a profession chosen by the code creating the villager is kept
		for($i = 0; $i < 20; ++$i){
			$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
			$villager->setAge(0);
			$villager->setProfession(VillagerV2::PROFESSION_LIBRARIAN);
			$villager->setSpawnReason(SpawnReason::STRUCTURE);
			self::assertSame(VillagerV2::PROFESSION_LIBRARIAN, $villager->getProfession(), "set before the spawn reason");
			$villager->close();
		}

		//loaded villagers keep their profession
		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_MASON);
		$copy = EntityFactory::getInstance()->createFromData($this->world, $villager->saveNBT());
		self::assertInstanceOf(VillagerV2::class, $copy);
		$copy->setSpawnReason(SpawnReason::STRUCTURE);
		self::assertSame(VillagerV2::PROFESSION_MASON, $copy->getProfession());
		$copy->close();
	}

	public function testGeneratedVillagersGetTheStructureReason() : void{
		mt_srand(11);
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		for($i = 0; $i < 20; ++$i){
			$chunk->addPendingEntityNBT(CompoundTag::create()
				->setString(Chunk::PENDING_ENTITY_IDENTIFIER, EntityIds::VILLAGER_V2)
				->setTag(Chunk::PENDING_ENTITY_POS, new ListTag([new DoubleTag(4.5), new DoubleTag(1), new DoubleTag(4.5)]))
				->setTag(Chunk::PENDING_ENTITY_ROTATION, new ListTag([new FloatTag(0), new FloatTag(0)]))
			);
		}
		(new \ReflectionMethod(World::class, "createPendingEntities"))->invoke($this->world, 0, 0, $chunk);
		$villagers = $this->entitiesOf(VillagerV2::class);
		self::assertCount(20, $villagers);
		foreach($villagers as $villager){
			self::assertSame(SpawnReason::STRUCTURE, $villager->getSpawnReason());
			self::assertContains($villager->getProfession(), [VillagerV2::PROFESSION_UNEMPLOYED, VillagerV2::PROFESSION_NITWIT]);
		}
	}

	public function testBabiesGrowUpAsNitwitsTenPercentOfTheTime() : void{
		mt_srand(99);
		$nitwits = 0;
		$total = 400;
		for($i = 0; $i < $total; ++$i){
			$baby = new VillagerV2($this->loc(0.5, 1, 0.5));
			$baby->makeSpawnBaby();
			$baby->setAge(0);
			$nitwits += $baby->getProfession() === VillagerV2::PROFESSION_NITWIT ? 1 : 0;
			self::assertContains($baby->getProfession(), [VillagerV2::PROFESSION_UNEMPLOYED, VillagerV2::PROFESSION_NITWIT]);
			$baby->close();
		}
		self::assertEqualsWithDelta(40, $nitwits, 20, "ageable_grow_up: 10% nitwits");
	}

	public function testCuredBabyRollDoesNotMakeAdultsBabies() : void{
		mt_srand(3);
		for($i = 0; $i < 40; ++$i){
			$zombie = new ZombieVillager($this->loc(0.5, 1, 0.5));
			if($zombie->isBaby()){
				$zombie->close();
				continue;
			}
			$zombie->setProfession(VillagerV2::PROFESSION_CLERIC);
			$villager = $zombie->finishConversion();
			self::assertInstanceOf(VillagerV2::class, $villager);
			self::assertFalse($villager->isBaby());
			self::assertSame(VillagerV2::PROFESSION_CLERIC, $villager->getProfession());
			$villager->close();
			$zombie->close();
		}
	}

	public function testAvoidList() : void{
		$at = $this->loc(10.5, 1, 10.5);
		$avoided = [new Zombie($at), new Husk($at), new ZombieVillager($at), new Pillager($at), new Vex($at), new Zoglin($at)];
		$ignored = [new ZombifiedPiglin($at), new Witch($at), new IronGolem($at)];
		foreach($avoided as $entity){
			self::assertTrue(VillagerV2::isAvoided($entity), $entity::class);
			$entity->close();
		}
		foreach($ignored as $entity){
			self::assertFalse(VillagerV2::isAvoided($entity), $entity::class);
			$entity->close();
		}
	}

	public function testFleesFromAPillager() : void{
		$villager = $this->villager(0.5, 0.5);
		$pillager = new Pillager($this->loc(4.5, 1, 0.5));
		$pillager->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$pillager->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$pillager->spawnToAll();
		$this->runTicks(100);
		self::assertGreaterThan(6.0, $villager->getPosition()->distance($pillager->getPosition()), "ran away");
		$pillager->close();
	}

	public function testBellSendsVillagersToTheirBedsFor30Seconds() : void{
		$head = $this->placeBed(-10, 0);
		$villager = $this->villager(8.5, 0.5);
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($head));
		$this->world->setTime(1000);
		$this->set(10, 1, 5, VanillaBlocks::BELL());
		$bell = $this->world->getBlockAt(10, 1, 5);
		self::assertInstanceOf(Bell::class, $bell);
		$bell->ring(Facing::NORTH);
		self::assertTrue($villager->isHidingFromBell());

		$reached = false;
		for($i = 0; $i < 400 && !$reached; ++$i){
			$this->runTicks(1);
			$pos = $villager->getPosition();
			$reached = abs($pos->x - $head->x - 0.5) <= 1.6 && abs($pos->z - $head->z - 0.5) <= 1.6;
		}
		self::assertTrue($reached, "ran to its bed");
		$this->runTicks(VillagerV2::HIDE_TICKS);
		self::assertFalse($villager->isHidingFromBell(), "for 30 seconds");

		//villagers out of earshot don't hide
		$far = $this->villager(-20.5, 22.5);
		$far->teleport(new Vector3(60.5, 1, 60.5));
		$bell->ring(Facing::NORTH);
		self::assertFalse($far->isHidingFromBell());
		$far->close();
	}

	public function testVillagesSummonGolemsAtAnyTimeInTheVillageBox() : void{
		for($i = 0; $i < 20; ++$i){
			$this->placeBed(-20 + ($i % 10) * 2, -20 + intdiv($i, 10) * 3);
		}
		$villagers = [];
		for($i = 0; $i < 10; ++$i){
			$villagers[] = $v = $this->villager(-10.5 + $i, 5.5);
			$v->onArriveAtWork();
			$v->updateDweller(); //claims a bed
		}
		$this->world->setTime(18000); //night
		foreach($this->entitiesOf(IronGolem::class) as $golem){
			$golem->close();
		}
		$this->tick += VillagerV2::GOLEM_CHECK_INTERVAL;
		$golem = null;
		foreach($villagers as $v){
			$golem ??= $v->trySummonGolem();
		}
		self::assertNotNull($golem, "summoned at night too");
		$village = VillageScanner::findVillageAt($this->world, $villagers[0]->getPosition());
		self::assertNotNull($village);
		$center = $village->getCenter();
		$pos = $golem->getPosition();
		self::assertLessThanOrEqual(VillagerV2::GOLEM_SPAWN_HORIZONTAL + 1, abs($pos->x - $center->x));
		self::assertLessThanOrEqual(VillagerV2::GOLEM_SPAWN_HORIZONTAL + 1, abs($pos->z - $center->z));
		self::assertLessThanOrEqual(VillagerV2::GOLEM_SPAWN_VERTICAL + 1, abs($pos->y - $center->y));

		//checked once every 700 ticks
		$golem->close();
		$this->tick += 10;
		self::assertNull($villagers[9]->trySummonGolem());

		//the conditions
		self::assertTrue(VillagerV2::canVillageSummonGolem(10, 8, 0, 20));
		self::assertFalse(VillagerV2::canVillageSummonGolem(10, 7, 0, 20), "75% must have worked");
		self::assertFalse(VillagerV2::canVillageSummonGolem(10, 10, 1, 20), "10 villagers per golem");
		self::assertTrue(VillagerV2::canVillageSummonGolem(20, 20, 1, 20));
		self::assertFalse(VillagerV2::canVillageSummonGolem(10, 10, 0, 19), "20 beds");
	}

	public function testDwellerUpdatesSummonGolemsAndWorkIsSaved() : void{
		for($i = 0; $i < 20; ++$i){
			$this->placeBed(-20 + ($i % 10) * 2, -20 + intdiv($i, 10) * 3);
		}
		$villagers = [];
		for($i = 0; $i < 10; ++$i){
			$villagers[] = $v = $this->villager(-10.5 + $i, 5.5);
			$v->onArriveAtWork();
		}
		$copy = EntityFactory::getInstance()->createFromData($this->world, $villagers[0]->saveNBT());
		self::assertInstanceOf(VillagerV2::class, $copy);
		$this->tick += 100;
		self::assertTrue($copy->hasWorkedRecently(), "lastWorkedTick is saved");
		$copy->close();

		foreach($villagers as $v){
			$v->updateDweller(); //claims beds; the first update may summon a golem
		}
		$this->tick += VillagerV2::GOLEM_CHECK_INTERVAL;
		foreach($villagers as $v){
			$v->updateDweller();
		}
		self::assertCount(1, $this->entitiesOf(IronGolem::class), "one golem for 10 villagers, without gossip");
	}

	public function testFarmerUsesBoneMeal() : void{
		$this->set(1, 0, 0, VanillaBlocks::FARMLAND());
		$this->set(1, 1, 0, VanillaBlocks::WHEAT()->setAge(0));
		$farmer = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$farmer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$farmer->getInventory()->addItem(VanillaItems::BONE_MEAL()->setCount(2));
		$this->world->setTime(1000);
		$goal = new FertilizeCropsGoal($farmer);
		self::assertTrue($goal->findCrop()?->equals(new Vector3(1, 1, 0)));
		self::assertTrue($goal->fertilize(new Vector3(1, 1, 0)));
		$wheat = $this->world->getBlockAt(1, 1, 0);
		self::assertInstanceOf(Crops::class, $wheat);
		self::assertGreaterThan(0, $wheat->getAge());
		self::assertTrue($farmer->getInventory()->contains(VanillaItems::BONE_MEAL()->setCount(1)));
		self::assertFalse($farmer->getInventory()->contains(VanillaItems::BONE_MEAL()->setCount(2)), "one used");

		//non-farmers don't
		$librarian = $this->villager(3.5, 3.5, VillagerV2::PROFESSION_LIBRARIAN);
		$librarian->getInventory()->addItem(VanillaItems::BONE_MEAL());
		self::assertFalse((new FertilizeCropsGoal($librarian))->canStart());
	}

	public function testFarmerCompostsSurplusSeeds() : void{
		mt_srand(5);
		$this->set(2, 1, 0, VanillaBlocks::COMPOSTER());
		$farmer = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$farmer->getInventory()->addItem(VanillaItems::WHEAT_SEEDS()->setCount(25), VanillaItems::BEETROOT_SEEDS()->setCount(8));
		$composter = $this->world->getBlockAt(2, 1, 0);
		self::assertInstanceOf(Composter::class, $composter);
		$used = WorkAtJobSiteGoal::compost($farmer, $composter);
		self::assertGreaterThan(0, $used);
		self::assertLessThanOrEqual(15, $used, "keeps 10 wheat seeds, beetroot seeds below 10 kept");
		self::assertTrue($farmer->getInventory()->contains(VanillaItems::WHEAT_SEEDS()->setCount(10)));
		self::assertTrue($farmer->getInventory()->contains(VanillaItems::BEETROOT_SEEDS()->setCount(8)));
		$after = $this->world->getBlockAt(2, 1, 0);
		self::assertInstanceOf(Composter::class, $after);
		self::assertGreaterThan(0, $after->getLayers());

		//a full composter is emptied first: the bone meal drops on top for the farmer
		$this->set(2, 1, 0, VanillaBlocks::COMPOSTER()->setLayers(Composter::MAX_COMPOST_LAYERS));
		$full = $this->world->getBlockAt(2, 1, 0);
		self::assertInstanceOf(Composter::class, $full);
		WorkAtJobSiteGoal::compost($farmer, $full);
		$emptied = $this->world->getBlockAt(2, 1, 0);
		self::assertInstanceOf(Composter::class, $emptied);
		self::assertLessThan(Composter::MAX_COMPOST_LAYERS, $emptied->getLayers());
		self::assertTrue($farmer->wantsItem(VanillaItems::BONE_MEAL()));
	}

	public function testCelebrationFireworksHurtNobody() : void{
		$villager = $this->villager(0.5, 0.5);
		$villager->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$other = $this->villager(1.5, 0.5);
		$other->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		self::assertTrue($villager->canSeeSky());
		$villager->setCelebrating(true);
		$fireworks = 0;
		for($i = 0; $i < 200; ++$i){
			$this->runTicks(1);
			$fireworks = count($this->entitiesOf(CelebrationFirework::class)) > 0 ? $fireworks + 1 : $fireworks;
		}
		self::assertGreaterThan(0, $fireworks, "launched fireworks");
		$this->runTicks(80); //let them explode
		self::assertEquals($other->getMaxHealth(), $other->getHealth());
		self::assertEquals($villager->getMaxHealth(), $villager->getHealth());
		$firework = $villager->launchFirework();
		self::assertFalse($firework->canSaveWithChunk(), "not saved without a save ID");
		$firework->close();

		//under a roof: none
		$villager->setCelebrating(false);
		$this->set(0, 4, 0, VanillaBlocks::STONE());
		self::assertFalse($villager->canSeeSky());
	}

	/**
	 * Blocks without collision (grass, flowers, torches) and the snow layer or carpet the feet are in are no roof:
	 * villagers standing in them see the sky and launch their celebration fireworks.
	 */
	public function testSkyAboveGrassSnowLayersAndCarpets() : void{
		$world = $this->world;
		$feet = new Vector3(0.5, 1, 0.5);
		self::assertTrue(VillagerGoal::isUnderSky($world, $feet));
		$world->setBlockAt(0, 0, 0, VanillaBlocks::GRASS(), false);
		foreach([
			"tall grass" => VanillaBlocks::TALL_GRASS(),
			"poppy" => VanillaBlocks::POPPY(),
			"snow layer" => VanillaBlocks::SNOW_LAYER(),
			"carpet" => VanillaBlocks::CARPET(),
			"pressure plate" => VanillaBlocks::OAK_PRESSURE_PLATE()
		] as $name => $block){
			$world->setBlockAt(0, 1, 0, $block, false);
			self::assertTrue(VillagerGoal::isUnderSky($world, $feet), $name);
		}
		$world->setBlockAt(0, 1, 0, VanillaBlocks::DOUBLE_TALLGRASS()->setTop(false), false);
		$world->setBlockAt(0, 2, 0, VanillaBlocks::DOUBLE_TALLGRASS()->setTop(true), false);
		self::assertTrue(VillagerGoal::isUnderSky($world, $feet), "double tall grass");
		$world->setBlockAt(0, 2, 0, VanillaBlocks::AIR(), false);

		//roofs: anything with collision or a liquid above the feet, even with blocks without collision on top of it
		foreach([
			"stone" => VanillaBlocks::STONE(),
			"leaves" => VanillaBlocks::OAK_LEAVES(),
			"glass" => VanillaBlocks::GLASS(),
			"slab" => VanillaBlocks::OAK_SLAB(),
			"water" => VanillaBlocks::WATER()
		] as $name => $block){
			$world->setBlockAt(0, 4, 0, $block, false);
			self::assertFalse(VillagerGoal::isUnderSky($world, $feet), $name);
			$world->setBlockAt(0, 5, 0, VanillaBlocks::TORCH(), false);
			self::assertFalse(VillagerGoal::isUnderSky($world, $feet), "torch on $name");
			$world->setBlockAt(0, 5, 0, VanillaBlocks::AIR(), false);
		}
		$world->setBlockAt(0, 4, 0, VanillaBlocks::AIR(), false);

		foreach(["tall grass" => VanillaBlocks::TALL_GRASS(), "snow layer" => VanillaBlocks::SNOW_LAYER()] as $name => $block){
			$world->setBlockAt(0, 1, 0, $block, false);
			$villager = $this->villager(0.5, 0.5);
			$villager->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
			self::assertTrue($villager->canSeeSky(), $name);
			$villager->setCelebrating(true);
			$fireworks = 0;
			for($i = 0; $i < 200 && $fireworks === 0; ++$i){
				$this->runTicks(1);
				$fireworks = count($this->entitiesOf(CelebrationFirework::class));
			}
			self::assertGreaterThan(0, $fireworks, "fireworks launched from the $name");
			foreach($this->entitiesOf(CelebrationFirework::class) as $firework){
				$firework->close();
			}
			$villager->close();
		}
	}

	/**
	 * Villagers without a bed don't take a spot in tall grass for shelter (move_indoors).
	 */
	public function testShelterIsUnderARoofNotInGrass() : void{
		mt_srand(99);
		for($x = -12; $x <= 12; ++$x){
			for($z = -12; $z <= 12; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::GRASS(), false);
				$this->world->setBlockAt($x, 1, $z, VanillaBlocks::TALL_GRASS(), false);
			}
		}
		$this->world->setTime(11500); //home time
		$villager = $this->villager(0.5, 0.5);
		self::assertSame(VillagerActivity::HOME, $villager->getActivity());
		self::assertTrue($villager->canSeeSky(), "in tall grass");
		$goal = new MoveIndoorsGoal($villager);
		$tick = new \ReflectionProperty(Mob::class, "aiTick");
		$aiTick = $villager->getAiTick();
		$found = false;
		for($i = 0; $i < 20 && !$found; ++$i){
			$found = $goal->canStart();
			$tick->setValue($villager, $aiTick += 200); //past the cooldown
		}
		self::assertFalse($found, "no shelter in the open grass");

		//a roof over a corner of the field: the villager takes a spot under it
		for($x = 2; $x <= 10; ++$x){
			for($z = 2; $z <= 10; ++$z){
				$this->world->setBlockAt($x, 3, $z, VanillaBlocks::OAK_PLANKS(), false);
			}
		}
		for($i = 0; $i < 50 && !$found; ++$i){
			$found = $goal->canStart();
			$tick->setValue($villager, $aiTick += 200);
		}
		self::assertTrue($found, "found a spot under the roof");
		$target = (new \ReflectionProperty(MoveIndoorsGoal::class, "target"))->getValue($goal);
		self::assertInstanceOf(Vector3::class, $target);
		self::assertFalse(VillagerGoal::isUnderSky($this->world, $target));
	}

	public function testCelebrationEndsAfter30Seconds() : void{
		$villager = $this->villager(0.5, 0.5);
		$villager->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$villager->setCelebrating(true);
		$this->runTicks(VillagerV2::CELEBRATE_TICKS - 20);
		self::assertTrue($villager->isCelebrating());
		$this->runTicks(40);
		self::assertFalse($villager->isCelebrating(), "celebrate_survive lasts 30 seconds");
		foreach($this->entitiesOf(CelebrationFirework::class) as $firework){
			$firework->close();
		}
		$this->runTicks(VillagerV2::FIREWORK_INTERVAL_MAX + 20);
		self::assertCount(0, $this->entitiesOf(CelebrationFirework::class), "no more fireworks");
	}

	public function testCelebratingVillagersGoOutdoors() : void{
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->set($x, 4, $z, VanillaBlocks::STONE());
			}
		}
		$villager = $this->villager(0.5, 0.5);
		self::assertFalse($villager->canSeeSky());
		$villager->setCelebrating(true);
		for($i = 0; $i < 400 && !$villager->canSeeSky(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->canSeeSky(), "walked out from under the roof");
	}

	public function testDwellingRestriction() : void{
		$head = $this->placeBed(-20, -20);
		$villager = $this->villager(-18.5, -18.5);
		self::assertSame(0, $villager->getHomeRadius(), "no bed: free");
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($head));
		self::assertSame(VillagerV2::DWELLING_RADIUS, $villager->getHomeRadius());
		self::assertTrue($villager->getHomePos()?->equals($head));

		$villager->teleport(new Vector3(20.5, 1, 20.5));
		$start = $villager->getPosition()->distance($head);
		self::assertFalse($villager->isWithinHome(20.5, 1, 20.5));
		$this->world->setTime(1000);
		$this->runTicks(300);
		self::assertLessThan($start - 8, $villager->getPosition()->distance($head), "walks back towards its village");

		//wandering: explores the outskirts, within 6 blocks inside the edge
		$goal = new ExploreOutskirtsGoal($villager);
		self::assertTrue($goal->canStart());
		$target = (new \ReflectionProperty(ExploreOutskirtsGoal::class, "target"))->getValue($goal);
		self::assertInstanceOf(Vector3::class, $target);
		$d = sqrt(($target->x - $head->x - 0.5) ** 2 + ($target->z - $head->z - 0.5) ** 2);
		self::assertGreaterThanOrEqual(VillagerV2::DWELLING_RADIUS - ExploreOutskirtsGoal::EXPLORE_DISTANCE - 0.01, $d);
		self::assertLessThan(VillagerV2::DWELLING_RADIUS, $d);

		$villager->releaseBed();
		self::assertSame(0, $villager->getHomeRadius());
		self::assertNull($villager->getHomePos());
	}
}
