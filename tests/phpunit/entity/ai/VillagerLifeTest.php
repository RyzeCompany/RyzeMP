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

namespace pocketmine\entity\ai;

use pocketmine\block\Bed;
use pocketmine\block\Crops;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\villager\ExploreOutskirtsGoal;
use pocketmine\entity\ai\villager\HarvestCropsGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\npc\Gossips;
use pocketmine\entity\npc\GossipType;
use pocketmine\entity\npc\TradePrices;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerSchedule;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\raid\VillageScanner;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function intdiv;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Village life of villagers: claims, schedules, sleeping, work, food, breeding, gossip and golems.
 */
final class VillagerLifeTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Places a bed with its foot at x,z and its head one block south; returns the head position.
	 */
	private function placeBed(int $x, int $z) : Vector3{
		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set($x, 1, $z, (clone $bed)->setHead(false));
		$this->set($x, 1, $z + 1, (clone $bed)->setHead(true));
		return new Vector3($x, 1, $z + 1);
	}

	/**
	 * An adult villager with the given profession that doesn't wander on its own.
	 */
	private function villager(float $x, float $z, int $profession = VillagerV2::PROFESSION_UNEMPLOYED) : VillagerV2{
		$villager = new VillagerV2($this->loc($x, 1, $z));
		$villager->setAge(0);
		$villager->setProfession($profession);
		$villager->spawnToAll();
		return $villager;
	}

	private function player(string $uuid) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getUniqueId")->willReturn(Uuid::fromString($uuid));
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	public function testScheduleByTimeOfDay() : void{
		$farmer = VillagerV2::PROFESSION_FARMER;
		self::assertSame(VillagerActivity::WORK, VillagerSchedule::getActivity(1000, $farmer, false));
		self::assertSame(VillagerActivity::GATHER, VillagerSchedule::getActivity(9000, $farmer, false));
		self::assertSame(VillagerActivity::WORK, VillagerSchedule::getActivity(10500, $farmer, false));
		self::assertSame(VillagerActivity::HOME, VillagerSchedule::getActivity(11500, $farmer, false));
		self::assertSame(VillagerActivity::SLEEP, VillagerSchedule::getActivity(18000, $farmer, false));
		self::assertSame(VillagerActivity::SLEEP, VillagerSchedule::getActivity(24000 * 3 + 13000, $farmer, false), "any day");

		self::assertSame(VillagerActivity::WANDER, VillagerSchedule::getActivity(1000, VillagerV2::PROFESSION_UNEMPLOYED, false));
		self::assertSame(VillagerActivity::GATHER, VillagerSchedule::getActivity(9000, VillagerV2::PROFESSION_UNEMPLOYED, false));

		self::assertSame(VillagerActivity::SLEEP, VillagerSchedule::getActivity(1000, VillagerV2::PROFESSION_NITWIT, false), "nitwits sleep in");
		self::assertSame(VillagerActivity::WANDER, VillagerSchedule::getActivity(12500, VillagerV2::PROFESSION_NITWIT, false));
		self::assertSame(VillagerActivity::HOME, VillagerSchedule::getActivity(13500, VillagerV2::PROFESSION_NITWIT, false));

		self::assertSame(VillagerActivity::PLAY, VillagerSchedule::getActivity(1000, $farmer, true));
		self::assertSame(VillagerActivity::HOME, VillagerSchedule::getActivity(11500, $farmer, true));
		self::assertSame(VillagerActivity::SLEEP, VillagerSchedule::getActivity(13000, $farmer, true));
	}

	public function testClaimsBedAndJobSiteAndTakesTheProfession() : void{
		$head = $this->placeBed(4, 4);
		$this->set(6, 1, 4, VanillaBlocks::COMPOSTER());
		$villager = $this->villager(2.5, 2.5);
		$villager->updateDweller();
		self::assertTrue($villager->getBedPosition()?->equals($head));
		self::assertTrue($villager->getJobSitePosition()?->equals(new Vector3(6, 1, 4)));
		self::assertSame(VillagerV2::PROFESSION_FARMER, $villager->getProfession());
		self::assertNotEmpty($villager->getTradeOffers());
		$poi = $this->world->getPoiManager();
		self::assertSame($villager->getId(), $poi->getClaimant($head));
		self::assertSame($villager->getId(), $poi->getClaimant(new Vector3(6, 1, 4)));

		//nothing left for a second villager
		$other = $this->villager(3.5, 2.5);
		$other->updateDweller();
		self::assertNull($other->getBedPosition());
		self::assertNull($other->getJobSitePosition());
		self::assertSame(VillagerV2::PROFESSION_UNEMPLOYED, $other->getProfession());

		//the village knows its claimed bed
		$village = VillageScanner::findVillageAt($this->world, new Vector3(4, 1, 4));
		self::assertNotNull($village);
		self::assertSame(1, $village->getClaimedBedCount());
		self::assertCount(0, $village->getFreeBeds());
	}

	public function testJobSiteNeedsABedAndMatchingProfession() : void{
		$this->set(6, 1, 4, VanillaBlocks::LECTERN());
		$villager = $this->villager(2.5, 2.5);
		$villager->updateDweller();
		self::assertNull($villager->getJobSitePosition(), "no bed, no job");

		$this->placeBed(4, 4);
		$smith = $this->villager(3.5, 2.5, VillagerV2::PROFESSION_TOOLSMITH);
		$smith->updateDweller();
		self::assertNotNull($smith->getBedPosition());
		self::assertNull($smith->getJobSitePosition(), "a toolsmith doesn't take a lectern");
		self::assertSame(VillagerV2::PROFESSION_TOOLSMITH, $smith->getProfession());
	}

	public function testBrokenJobSiteBeforeTradingLosesTheProfession() : void{
		$this->placeBed(4, 4);
		$this->placeBed(8, 4);
		$this->set(6, 1, 4, VanillaBlocks::SMOKER());
		$this->set(6, 1, 8, VanillaBlocks::BLAST_FURNACE());
		$butcher = $this->villager(2.5, 2.5);
		$butcher->updateDweller();
		self::assertSame(VillagerV2::PROFESSION_BUTCHER, $butcher->getProfession());
		$armorer = $this->villager(3.5, 2.5);
		$armorer->updateDweller();
		self::assertSame(VillagerV2::PROFESSION_ARMORER, $armorer->getProfession());
		$armorer->addTradeExperience(5); //traded once

		$this->set(6, 1, 4, VanillaBlocks::AIR());
		$this->set(6, 1, 8, VanillaBlocks::AIR());
		$butcher->updateDweller();
		$armorer->updateDweller();
		self::assertSame(VillagerV2::PROFESSION_UNEMPLOYED, $butcher->getProfession());
		self::assertNull($butcher->getJobSitePosition());
		self::assertSame(VillagerV2::PROFESSION_ARMORER, $armorer->getProfession(), "kept after trading");
		self::assertNull($armorer->getJobSitePosition());

		//a new blast furnace is claimed again by the armorer
		$this->set(10, 1, 8, VanillaBlocks::BLAST_FURNACE());
		$armorer->updateDweller();
		self::assertTrue($armorer->getJobSitePosition()?->equals(new Vector3(10, 1, 8)) ?? false);
	}

	public function testClaimsAreSavedAndReleased() : void{
		$head = $this->placeBed(4, 4);
		$this->set(6, 1, 4, VanillaBlocks::LOOM());
		$villager = $this->villager(2.5, 2.5);
		$villager->updateDweller();
		$villager->getInventory()->addItem(VanillaItems::BREAD()->setCount(5));
		$nbt = $villager->saveNBT();
		$villager->close();
		$poi = $this->world->getPoiManager();
		self::assertNull($poi->getClaimant($head), "unloading frees the claim for the session");

		$copy = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(VillagerV2::class, $copy);
		self::assertTrue($copy->getBedPosition()?->equals($head));
		self::assertTrue($copy->getJobSitePosition()?->equals(new Vector3(6, 1, 4)));
		self::assertSame(VillagerV2::PROFESSION_SHEPHERD, $copy->getProfession());
		self::assertTrue($copy->getInventory()->contains(VanillaItems::BREAD()->setCount(5)));
		$copy->spawnToAll();
		$copy->updateDweller();
		self::assertSame($copy->getId(), $poi->getClaimant($head));
		$this->runTicks(1);

		$copy->attack(new EntityDamageEvent($copy, EntityDamageEvent::CAUSE_MAGIC, 1000));
		self::assertFalse($copy->isAlive());
		self::assertNull($poi->getClaimant($head), "death releases the bed");
		self::assertNull($poi->getClaimant(new Vector3(6, 1, 4)), "and the job site");
	}

	public function testSleepsInItsBedAtNightAndWakesUp() : void{
		$head = $this->placeBed(4, 4);
		$villager = $this->villager(4.5, 2.5);
		$villager->updateDweller();
		self::assertNotNull($villager->getBedPosition());
		$this->world->setTime(13000);
		for($i = 0; $i < 600 && !$villager->isSleeping(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->isSleeping(), "asleep");
		$bed = $this->world->getBlock($head);
		self::assertInstanceOf(Bed::class, $bed);
		self::assertTrue($bed->isOccupied());
		self::assertEqualsWithDelta($head->x + 0.5, $villager->getPosition()->x, 0.01);
		self::assertEqualsWithDelta($head->z + 0.5, $villager->getPosition()->z, 0.01);
		self::assertSame($villager, VillagerV2::findSleeperAt($this->world, $head));

		//hurt: wakes up
		$villager->attack(new EntityDamageEvent($villager, EntityDamageEvent::CAUSE_MAGIC, 1));
		self::assertFalse($villager->isSleeping());
		$bed = $this->world->getBlock($head);
		self::assertInstanceOf(Bed::class, $bed);
		self::assertFalse($bed->isOccupied());

		//goes back to bed, then gets up in the morning
		for($i = 0; $i < 800 && !$villager->isSleeping(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->isSleeping(), "back in bed");
		$this->world->setTime(24000 * 2 + 100);
		$this->runTicks(10);
		self::assertFalse($villager->isSleeping(), "morning");
		self::assertEquals($villager->getMaxHealth(), $villager->getHealth(), "healed by a night's sleep");
	}

	public function testRestocksAtTheJobSiteTwiceADay() : void{
		$this->placeBed(4, 4);
		$this->set(6, 1, 4, VanillaBlocks::CARTOGRAPHY_TABLE());
		$villager = $this->villager(2.5, 2.5);
		$villager->updateDweller();
		self::assertSame(VillagerV2::PROFESSION_CARTOGRAPHER, $villager->getProfession());
		$offer = $villager->getTradeOffers()[0];
		for($i = 1; $i <= 3; ++$i){
			$offer->setUses(5);
			$villager->onArriveAtWork();
			self::assertSame($i <= VillagerV2::MAX_RESTOCKS_PER_DAY ? 0 : 5, $offer->getUses(), "arrival $i");
		}
		self::assertTrue($villager->hasWorkedRecently());

		//next day
		$this->world->setTime($this->world->getTime() + 24000);
		$villager->onArriveAtWork();
		self::assertSame(0, $offer->getUses());

		//without a bed: no restock (Bedrock)
		$villager->releaseBed();
		$this->world->setTime($this->world->getTime() + 24000);
		$offer->setUses(5);
		$villager->onArriveAtWork();
		self::assertSame(5, $offer->getUses());
	}

	public function testWalksToItsJobSiteAndRestocks() : void{
		$this->placeBed(-6, -6);
		$this->set(8, 1, 0, VanillaBlocks::FLETCHING_TABLE());
		$villager = $this->villager(-4.5, -4.5);
		$villager->updateDweller();
		self::assertSame(VillagerV2::PROFESSION_FLETCHER, $villager->getProfession());
		$offer = $villager->getTradeOffers()[0];
		$offer->setUses(3);
		$this->world->setTime(1000);
		for($i = 0; $i < 600 && $offer->getUses() !== 0; ++$i){
			$this->runTicks(1);
		}
		self::assertSame(0, $offer->getUses(), "restocked at the job site");
		self::assertLessThan(3.0, $villager->getPosition()->distance(new Vector3(8.5, 1, 0.5)));
	}

	public function testReputationPrices() : void{
		$gossips = new Gossips();
		$uuid = Uuid::uuid4()->toString();
		self::assertSame(0, $gossips->getReputation($uuid));
		$gossips->add($uuid, GossipType::MAJOR_POSITIVE, VillagerV2::GOSSIP_CURE_MAJOR);
		$gossips->add($uuid, GossipType::MINOR_POSITIVE, VillagerV2::GOSSIP_CURE_MINOR);
		self::assertSame(125, $gossips->getReputation($uuid));
		$gossips->add($uuid, GossipType::MAJOR_POSITIVE, 100);
		self::assertSame(125, $gossips->getReputation($uuid), "capped");

		//10 emeralds, multiplier 0.05: -6 for a cure
		self::assertSame(4, TradePrices::getPrice(10, 0.05, 125, 0));
		self::assertSame(1, TradePrices::getPrice(10, 0.05, 125, 1), "cure + Hero of the Village");
		self::assertSame(10, TradePrices::getPrice(10, 0.05, -500, 0), "Bedrock: bad reputation doesn't raise prices");
		self::assertSame(7, TradePrices::getPrice(10, 0.05, -500, 1));
		self::assertSame(1, TradePrices::getPrice(1, 0.2, 1000, 1));
		self::assertSame(15, TradePrices::getPrice(20, 0.2, 25, 0), "25 trading gossip at 0.2");

		//hitting cancels positive reputation
		$gossips->add($uuid, GossipType::MINOR_NEGATIVE, 25);
		self::assertSame(100, $gossips->getReputation($uuid));

		//decay: the major positive cure gossip is permanent
		for($i = 0; $i < 30; ++$i){
			$gossips->decay();
		}
		self::assertSame(20, $gossips->get($uuid, GossipType::MAJOR_POSITIVE));
		self::assertSame(0, $gossips->get($uuid, GossipType::MINOR_NEGATIVE));
		self::assertSame(0, $gossips->get($uuid, GossipType::MINOR_POSITIVE));

		//sharing: minus the share cost, cure gossip never shared
		$other = new Gossips();
		$gossips->add($uuid, GossipType::TRADING, 25);
		$gossips->add($uuid, GossipType::MINOR_NEGATIVE, 100);
		$gossips->shareWith($other);
		self::assertSame(5, $other->get($uuid, GossipType::TRADING));
		self::assertSame(80, $other->get($uuid, GossipType::MINOR_NEGATIVE));
		self::assertSame(0, $other->get($uuid, GossipType::MAJOR_POSITIVE));

		$copy = Gossips::fromNbt($gossips->toNbt());
		self::assertSame($gossips->getReputation($uuid), $copy->getReputation($uuid));
	}

	public function testVillagerGossipFromTradingHittingAndCuring() : void{
		$uuid = Uuid::uuid4()->toString();
		$player = $this->player($uuid);
		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_LIBRARIAN);
		$offer = $villager->getTradeOffers()[0];
		$villager->onTrade($player, $offer, 3);
		self::assertSame(6, $villager->getPlayerReputation($player));
		self::assertTrue($villager->hasTraded());

		$villager->onCuredBy($uuid);
		self::assertSame(131, $villager->getPlayerReputation($player));

		$copy = EntityFactory::getInstance()->createFromData($this->world, $villager->saveNBT());
		self::assertInstanceOf(VillagerV2::class, $copy);
		self::assertSame(131, $copy->getPlayerReputation($player));
		$copy->flagForDespawn();
	}

	public function testCuringAZombieVillagerGivesTheDiscount() : void{
		$uuid = Uuid::uuid4()->toString();
		$zombie = new ZombieVillager($this->loc(0.5, 1, 0.5));
		$zombie->setProfession(VillagerV2::PROFESSION_CLERIC);
		$zombie->startConversion(10, $uuid);
		$copy = EntityFactory::getInstance()->createFromData($this->world, $zombie->saveNBT());
		self::assertInstanceOf(ZombieVillager::class, $copy);
		$zombie->close();
		$copy->spawnToAll();
		$this->runTicks(15);
		$villagers = $this->entitiesOf(VillagerV2::class);
		self::assertCount(1, $villagers);
		self::assertSame(125, $villagers[0]->getPlayerReputation($this->player($uuid)));
	}

	public function testIronGolemsTurnOnDislikedPlayers() : void{
		$uuid = Uuid::uuid4()->toString();
		$player = $this->player($uuid);
		$villager = $this->villager(0.5, 0.5);
		$golem = new IronGolem($this->loc(3.5, 1, 0.5));
		self::assertFalse($golem->isDislikedByVillagers($player));
		for($i = 0; $i < 4; ++$i){
			$villager->getGossips()->add($uuid, GossipType::MINOR_NEGATIVE, VillagerV2::GOSSIP_HIT);
		}
		self::assertSame(-100, $villager->getPlayerReputation($player));
		self::assertTrue($golem->isDislikedByVillagers($player));
		$golem->close();
	}

	public function testKillingAVillagerIsRememberedByTheOthers() : void{
		$witness = $this->villager(4.5, 0.5);
		$victim = $this->villager(0.5, 0.5);
		$this->runTicks(1);
		$uuid = Uuid::uuid4()->toString();
		$player = $this->player($uuid);
		$tick = new \ReflectionProperty(Mob::class, "lastHurtByPlayerTick");
		$tick->setValue($victim, $this->tick);
		(new \ReflectionProperty(Mob::class, "lastHurtByPlayer"))->setValue($victim, $player);
		$victim->attack(new EntityDamageEvent($victim, EntityDamageEvent::CAUSE_MAGIC, 1000));
		self::assertSame(-125, $witness->getPlayerReputation($player));
	}

	public function testBreedingConditions() : void{
		$this->placeBed(4, 4);
		$this->placeBed(8, 4);
		$this->world->setTime(9000); //gathering
		$a = $this->villager(4.5, 2.5);
		$a->updateDweller();
		self::assertNotNull($a->getBedPosition());
		self::assertFalse($a->isWillingToBreed(), "no food");
		$a->getInventory()->addItem(VanillaItems::BREAD()->setCount(3));
		self::assertSame(12, $a->getFoodPointsInInventory());
		self::assertTrue($a->isWillingToBreed());

		//no free bed: not willing
		$b = $this->villager(5.5, 2.5);
		$b->updateDweller();
		self::assertNotNull($b->getBedPosition());
		self::assertSame(0, $a->countFreeBeds());
		self::assertFalse($a->isWillingToBreed());

		//Bedrock: employed villagers breed during work hours too (make_and_receive_love comes with the work schedule),
		//nobody breeds during sleeping hours
		$this->placeBed(12, 4);
		self::assertTrue($a->isWillingToBreed());
		$a->setProfession(VillagerV2::PROFESSION_MASON);
		$this->world->setTime(1000);
		self::assertSame(VillagerActivity::WORK, $a->getActivity());
		self::assertTrue($a->isWillingToBreed(), "breeds while working");
		$this->world->setTime(18000);
		self::assertFalse($a->isWillingToBreed(), "not at night");
		$a->setProfession(VillagerV2::PROFESSION_UNEMPLOYED);
		$this->world->setTime(9000);

		//becoming willing spends the food
		$b->getInventory()->addItem(VanillaItems::CARROT()->setCount(12), VanillaItems::POTATO()->setCount(3));
		$a->updateDweller();
		$b->updateDweller();
		self::assertTrue($a->isInLove());
		self::assertTrue($b->isInLove());
		self::assertSame(0, $a->getFoodPointsInInventory());
		self::assertSame(3, $b->getFoodPointsInInventory());

		for($i = 0; $i < 300 && count($this->entitiesOf(VillagerV2::class)) < 3; ++$i){
			$this->runTicks(1);
		}
		$babies = array_values(array_filter($this->entitiesOf(VillagerV2::class), fn(VillagerV2 $v) => $v->isBaby()));
		self::assertCount(1, $babies);
		self::assertSame(VillagerV2::PROFESSION_UNEMPLOYED, $babies[0]->getProfession());
		self::assertGreaterThan(0, $a->getAge(), "breeding cooldown");
		self::assertFalse($a->isInLove());
	}

	public function testFarmerHarvestsReplantsBakesAndShares() : void{
		$this->set(2, 0, 2, VanillaBlocks::FARMLAND());
		$this->set(2, 1, 2, VanillaBlocks::WHEAT()->setAge(Crops::MAX_AGE));
		$this->set(3, 0, 2, VanillaBlocks::FARMLAND());
		$this->set(3, 1, 2, VanillaBlocks::WHEAT()->setAge(2));
		$farmer = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$farmer->getInventory()->addItem(VanillaItems::WHEAT_SEEDS()); //wheat may drop no seeds
		$this->world->setTime(1000);
		$goal = new HarvestCropsGoal($farmer);
		self::assertTrue($goal->findTarget()?->equals(new Vector3(2, 1, 2)), "the grown wheat, not the young one");
		$goal->work(new Vector3(2, 1, 2));
		$replanted = $this->world->getBlockAt(2, 1, 2);
		self::assertInstanceOf(Crops::class, $replanted);
		self::assertSame(0, $replanted->getAge(), "replanted");
		self::assertTrue($farmer->getInventory()->contains(VanillaItems::WHEAT()));
		self::assertNull($goal->findTarget());

		//3 wheat make a bread
		$farmer->getInventory()->addItem(VanillaItems::WHEAT()->setCount(2));
		$farmer->updateDweller();
		self::assertTrue($farmer->getInventory()->contains(VanillaItems::BREAD()));
		self::assertFalse($farmer->getInventory()->contains(VanillaItems::WHEAT()));

		//a surplus is shared with a hungry villager
		$farmer->getInventory()->addItem(VanillaItems::BREAD()->setCount(7));
		self::assertSame(4, $farmer->getShareableFood()?->getCount());
		$hungry = $this->villager(3.5, 0.5);
		$hungry->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true); //stays there
		$farmer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		self::assertTrue($hungry->wantsFood());
		$farmer->throwFoodTo($hungry);
		for($i = 0; $i < 200 && !$hungry->getInventory()->contains(VanillaItems::BREAD()); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($hungry->getInventory()->contains(VanillaItems::BREAD()->setCount(3)), "picked up the shared bread (want_amount 3)");
	}

	public function testPicksUpFoodButNotOtherItems() : void{
		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_LIBRARIAN);
		$villager->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$this->world->dropItem(new Vector3(1, 1, 0.5), VanillaItems::POTATO()->setCount(5), Vector3::zero(), 0);
		$this->world->dropItem(new Vector3(0.5, 1, 1), VanillaItems::DIAMOND(), Vector3::zero(), 0);
		$this->world->dropItem(new Vector3(0, 1, 0.5), VanillaItems::WHEAT_SEEDS(), Vector3::zero(), 0);
		$this->runTicks(40);
		self::assertTrue($villager->getInventory()->contains(VanillaItems::POTATO()->setCount(5)));
		self::assertFalse($villager->getInventory()->contains(VanillaItems::DIAMOND()));
		self::assertFalse($villager->getInventory()->contains(VanillaItems::WHEAT_SEEDS()), "non-peasants don't take seeds");
	}

	public function testWantAmountsByShareablesGroup() : void{
		$unemployed = $this->villager(0.5, 0.5);
		$librarian = $this->villager(2.5, 0.5, VillagerV2::PROFESSION_LIBRARIAN);
		$farmer = $this->villager(4.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$nitwit = $this->villager(6.5, 0.5, VillagerV2::PROFESSION_NITWIT);
		foreach([$unemployed, $librarian, $farmer, $nitwit] as $v){
			self::assertSame(3, $v->getWantAmount(VanillaItems::BREAD()));
		}
		self::assertSame(60, $unemployed->getWantAmount(VanillaItems::CARROT()));
		self::assertSame(12, $librarian->getWantAmount(VanillaItems::CARROT()));
		self::assertSame(64, $nitwit->getWantAmount(VanillaItems::WHEAT_SEEDS()));
		self::assertSame(45, $unemployed->getWantAmount(VanillaItems::WHEAT()));
		self::assertSame(0, $librarian->getWantAmount(VanillaItems::WHEAT()));
		self::assertSame(0, $unemployed->getWantAmount(VanillaItems::BONE_MEAL()));
		self::assertSame(64, $farmer->getWantAmount(VanillaItems::BONE_MEAL()));

		$librarian->getInventory()->addItem(VanillaItems::CARROT()->setCount(10));
		self::assertSame(2, $librarian->getWantedCount(VanillaItems::CARROT()));
		$librarian->getInventory()->addItem(VanillaItems::CARROT()->setCount(2));
		self::assertFalse($librarian->wantsItem(VanillaItems::CARROT()));

		//peasants bake wheat into bread
		$unemployed->getInventory()->addItem(VanillaItems::WHEAT()->setCount(6));
		$unemployed->updateDweller();
		self::assertTrue($unemployed->getInventory()->contains(VanillaItems::BREAD()->setCount(2)));
	}

	public function testUnemployedWalksToFoodAndPicksUpBread() : void{
		$villager = $this->villager(0.5, 0.5);
		$this->world->setTime(1000);
		$entity = $this->world->dropItem(new Vector3(3.5, 1, 0.5), VanillaItems::BREAD()->setCount(5), Vector3::zero(), 0);
		self::assertNotNull($entity);
		for($i = 0; $i < 200 && !$villager->getInventory()->contains(VanillaItems::BREAD()); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($villager->getInventory()->contains(VanillaItems::BREAD()->setCount(3)), "walked to the bread 3 blocks away");
		self::assertFalse($villager->getInventory()->contains(VanillaItems::BREAD()->setCount(4)), "up to its want amount");
		self::assertFalse($entity->isFlaggedForDespawn(), "the rest stays on the ground");
		self::assertSame(2, $entity->getItem()->getCount());
	}

	public function testVillagesSummonIronGolems() : void{
		for($i = 0; $i < 20; ++$i){
			$this->placeBed(-20 + ($i % 10) * 2, -20 + intdiv($i, 10) * 3);
		}
		$villagers = [];
		for($i = 0; $i < 10; ++$i){
			$villagers[] = $v = $this->villager(-10.5 + $i, 5.5);
			$v->onArriveAtWork();
		}
		$golem = $villagers[0]->trySummonGolem();
		self::assertNotNull($golem);
		self::assertNull($villagers[1]->trySummonGolem(), "checked once in a while");
		$this->tick += VillagerV2::GOLEM_CHECK_INTERVAL;
		self::assertNull($villagers[1]->trySummonGolem(), "one golem per 10 villagers");
		$golem->close();
	}

	/**
	 * A village of 30 villagers through a day: POI chunks are scanned once each, not per villager. Prints the cost
	 * per villager tick with MOB_BENCH=1.
	 */
	public function testBusyVillageCost() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(1234);
		$jobSites = [VanillaBlocks::COMPOSTER(), VanillaBlocks::LECTERN(), VanillaBlocks::SMOKER(), VanillaBlocks::BARREL(), VanillaBlocks::LOOM(), VanillaBlocks::STONECUTTER()];
		for($i = 0; $i < 30; ++$i){
			$this->placeBed(-20 + ($i % 10) * 4, -20 + intdiv($i, 10) * 4);
			$this->set(-20 + ($i % 10) * 4 + 2, 1, -20 + intdiv($i, 10) * 4, $jobSites[$i % count($jobSites)]);
		}
		$this->set(0, 1, 10, VanillaBlocks::BELL());
		$villagers = [];
		for($i = 0; $i < 30; ++$i){
			$villagers[] = $this->villager(mt_rand(-20, 20) + 0.5, mt_rand(-5, 15) + 0.5);
		}
		$poi = $this->world->getPoiManager();
		$full = getenv("MOB_BENCH") === "1";
		$ticks = $full ? 2400 : 300;
		$start = hrtime(true);
		foreach([1000, 9000, 11500, 13000] as $time){
			$this->world->setTime($time);
			$this->runTicks(intdiv($ticks, 4));
		}
		$perVillager = (hrtime(true) - $start) / $ticks / count($villagers) / 1e3;
		$claimedBeds = 0;
		$employed = 0;
		foreach($villagers as $villager){
			$claimedBeds += $villager->getBedPosition() !== null ? 1 : 0;
			$employed += $villager->getJobSitePosition() !== null ? 1 : 0;
		}
		self::assertSame(30, $claimedBeds, "every villager found a bed");
		self::assertSame(30, $employed, "and a job");
		self::assertLessThanOrEqual(30, $poi->getScanCount(), "POI chunks scanned once, not per villager");
		if($full){
			fwrite(STDERR, sprintf("[villager bench] 30 villagers: %.1f us per villager tick, %d chunk scans\n", $perVillager, $poi->getScanCount()));
		}
	}

	/**
	 * Calls $step once per tick for $ticks ticks and returns the path searches the villagers ran meanwhile and the cost
	 * per villager tick in microseconds.
	 *
	 * @param VillagerV2[] $villagers
	 * @phpstan-param list<VillagerV2> $villagers
	 * @phpstan-param \Closure(int) : void $step
	 * @phpstan-return array{int, float}
	 */
	private function measureVillagers(array $villagers, int $ticks, \Closure $step) : array{
		$lastRepath = new \ReflectionProperty(PathNavigator::class, "lastRepathTick");
		$seen = [];
		foreach($villagers as $i => $villager){
			$seen[$i] = $lastRepath->getValue($villager->getNavigation());
		}
		$searches = 0;
		$start = hrtime(true);
		for($t = 0; $t < $ticks; ++$t){
			$step($t);
			$this->runTicks(1);
			foreach($villagers as $i => $villager){
				$tick = $lastRepath->getValue($villager->getNavigation());
				if($tick !== $seen[$i]){
					$seen[$i] = $tick;
					++$searches;
				}
			}
		}
		return [$searches, (hrtime(true) - $start) / $ticks / count($villagers) / 1e3];
	}

	/**
	 * Removes the goals of the given class from the villager.
	 *
	 * @phpstan-param class-string<Goal> $class
	 */
	private function removeGoals(VillagerV2 $villager, string $class) : void{
		$selector = $villager->getGoalSelector();
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($selector);
		foreach($goals as $entry){
			if($entry->goal instanceof $class){
				$selector->removeGoal($entry->goal);
			}
		}
	}

	/**
	 * 20 unemployed villagers with beds in a closed 11x11 pen centred on x,z (iron farm, breeder) during wander time,
	 * with or without explore_outskirts. Returns the path searches and the cost per villager tick (microseconds).
	 *
	 * @phpstan-return array{int, float}
	 */
	private function runPen(int $cx, int $cz, int $ticks, bool $explore) : array{
		$wall = VanillaBlocks::STONE();
		for($i = -6; $i <= 6; ++$i){
			for($y = 1; $y <= 3; ++$y){
				$this->set($cx + $i, $y, $cz - 6, $wall);
				$this->set($cx + $i, $y, $cz + 6, $wall);
				$this->set($cx - 6, $y, $cz + $i, $wall);
				$this->set($cx + 6, $y, $cz + $i, $wall);
			}
		}
		$villagers = [];
		for($i = 0; $i < 20; ++$i){
			$x = $cx - 5 + $i % 10;
			$bed = $this->placeBed($x, $i < 10 ? $cz - 5 : $cz + 4);
			$villager = $this->villager($x + 0.5, ($i < 10 ? $cz - 2 : $cz + 2) + 0.5);
			self::assertTrue($villager->claimBed($bed));
			if(!$explore){
				$this->removeGoals($villager, ExploreOutskirtsGoal::class);
			}
			$villagers[] = $villager;
		}
		$this->world->setTime(3000);
		$result = $this->measureVillagers($villagers, $ticks, static function(int $t) : void{});
		foreach($villagers as $villager){
			self::assertSame(VillagerActivity::WANDER, $villager->getActivity());
			$villager->close();
		}
		return $result;
	}

	/**
	 * Villagers wandering in a closed pen can't reach the outskirts of their village: exploring gives up after a failed
	 * leg instead of searching for a path every couple of seconds. Prints the cost with MOB_BENCH=1.
	 */
	public function testPenWanderCost() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(4321);
		$this->floor(-32, -32, 47, 47); //the outskirts are standable: the paths to them fail on the walls
		$full = getenv("MOB_BENCH") === "1";
		$ticks = $full ? 2400 : 1200;
		[$searches, $cost] = $this->runPen(-12, 0, $ticks, true);
		[$baseSearches, $baseCost] = $this->runPen(12, 0, $ticks, false);
		if($full){
			fwrite(STDERR, sprintf("[villager bench] pen, 20 villagers: %.1f us per villager tick, %d path searches; without explore_outskirts %.1f us, %d searches\n", $cost, $searches, $baseCost, $baseSearches));
		}
		self::assertLessThanOrEqual((int) ($baseSearches * 1.25) + 20, $searches, "explore_outskirts gives up on unreachable outskirts");
	}

	/**
	 * 20 unemployed villagers in 1x1 cells in two rows at z and z+12, their beds 4 blocks away out of reach, during
	 * wander time; with $bell a bell rings at 0,1,0 every 10 seconds. Returns the path searches and the cost per
	 * villager tick (microseconds).
	 *
	 * @phpstan-return array{int, float}
	 */
	private function runCells(int $z0, int $ticks, bool $bell) : array{
		$wall = VanillaBlocks::STONE();
		$villagers = [];
		for($i = 0; $i < 20; ++$i){
			$x = -20 + ($i % 10) * 4;
			$z = $z0 + ($i < 10 ? 0 : 12);
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					if($dx !== 0 || $dz !== 0){
						for($y = 1; $y <= 3; ++$y){
							$this->set($x + $dx, $y, $z + $dz, $wall);
						}
					}
				}
			}
			$villager = $this->villager($x + 0.5, $z + 0.5);
			self::assertTrue($villager->claimBed($this->placeBed($x, $z + 4)));
			$villagers[] = $villager;
		}
		$this->world->setTime(3000);
		$hiding = 0;
		$result = $this->measureVillagers($villagers, $ticks, function(int $t) use ($villagers, $bell, &$hiding) : void{
			if($bell && $t % 200 === 0){
				VillagerV2::onBellRung($this->world, new Vector3(0, 1, 0));
			}
			if($t % 200 === 100){
				foreach($villagers as $villager){
					$hiding += $villager->isHidingFromBell() ? 1 : 0;
				}
			}
		});
		self::assertSame($bell, $hiding > 0, "the villagers heard the bell");
		foreach($villagers as $villager){
			$villager->close();
		}
		return $result;
	}

	/**
	 * Villagers in 1x1 cells with their beds out of reach hear a bell every 10 seconds: hiding gives up after a failed
	 * path (Bedrock behavior.hide timeout_cooldown) instead of trying again every tick. Prints the cost with MOB_BENCH=1.
	 */
	public function testBellHidingWithBedsOutOfReachCost() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(4321);
		$full = getenv("MOB_BENCH") === "1";
		$ticks = $full ? 2400 : 600;
		[$searches, $cost] = $this->runCells(-21, $ticks, true);
		[$baseSearches, $baseCost] = $this->runCells(5, $ticks, false);
		if($full){
			fwrite(STDERR, sprintf("[villager bench] cells, beds out of reach, 20 villagers: bell %.1f us per villager tick, %d path searches; no bell %.1f us, %d searches\n", $cost, $searches, $baseCost, $baseSearches));
		}
		//one search per villager and attempt to hide, an attempt every 8 seconds at most (behavior.hide timeout_cooldown),
		//not one every 2 seconds (the path finder's retry delay for a failed target)
		self::assertLessThanOrEqual($baseSearches + (int) (20 * ($ticks / 160 + 1) * 1.25), $searches, "hiding gives up on beds out of reach");
	}

	public function testPoiScansAreCached() : void{
		$this->placeBed(4, 4);
		$poi = $this->world->getPoiManager();
		$poi->getPois(0, 0, 0, 15, 5, 15);
		$scans = $poi->getScanCount();
		$poi->getPois(0, 0, 0, 15, 5, 15);
		self::assertSame($scans, $poi->getScanCount(), "cached");
		$this->set(10, 1, 10, VanillaBlocks::STONE());
		$poi->getPois(0, 0, 0, 15, 5, 15);
		self::assertSame($scans, $poi->getScanCount(), "other blocks don't invalidate the cache");
		$this->set(10, 1, 10, VanillaBlocks::BELL());
		self::assertCount(2, $poi->getPois(0, 0, 0, 15, 5, 15));
		self::assertSame($scans + 1, $poi->getScanCount());
	}
}
