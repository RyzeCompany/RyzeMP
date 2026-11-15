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

namespace pocketmine\entity\golem;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\player\Player;
use pocketmine\world\World;

/**
 * Vanilla golem behaviour: village patrol and dwelling, hoglin targets, target_when_pushed, taking the offered flower
 * and the snow and copper golem damage rules.
 */
final class GolemVanillaTest extends MobTestCase{

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

	public function testVillageGolemReturnsToItsVillage() : void{
		$this->world->setTime(1000);
		$this->floor(25, -24, 46, 24);
		$this->placeBed(-10, 0);
		$this->set(-6, 1, 0, VanillaBlocks::BELL());
		$golem = new IronGolem($this->loc(-8.5, 1, 4.5));
		$golem->setVillageCreated(true);
		$golem->spawnToAll();
		$village = $golem->getVillage();
		self::assertNotNull($village);
		self::assertTrue($golem->getHomePos()?->equals($village->getCenter()->floor()));
		self::assertGreaterThan(30, $golem->getHomeRadius());

		$golem->teleport(new Vector3(42.5, 1, 0.5));
		self::assertFalse($golem->isWithinHome(42.5, 1, 0.5));
		for($i = 0; $i < 1200 && !$golem->isWithinHome($golem->getPosition()->x, $golem->getPosition()->y, $golem->getPosition()->z); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($golem->isWithinHome($golem->getPosition()->x, $golem->getPosition()->y, $golem->getPosition()->z), "walked back into its village");

		//golems built by players or spawned otherwise roam freely
		$free = new IronGolem($this->loc(-8.5, 1, 4.5));
		$free->getVillage();
		self::assertSame(0, $free->getHomeRadius());
		$golem->close();
		$free->close();
	}

	public function testVillageCreatedIsSaved() : void{
		$golem = new IronGolem($this->loc(0.5, 1, 0.5));
		$golem->setVillageCreated(true);
		$nbt = $golem->saveNBT();
		$golem->close();
		$copy = new IronGolem($this->loc(0.5, 1, 0.5), $nbt);
		self::assertTrue($copy->isVillageCreated());
		$copy->close();

		//Bedrock worlds keep the component groups in "definitions"
		$bedrock = CompoundTag::create()->setTag("definitions", new ListTag([new StringTag("+minecraft:iron_golem"), new StringTag("+minecraft:player_created")]));
		$golem = new IronGolem($this->loc(0.5, 1, 0.5), $bedrock);
		self::assertTrue($golem->isPlayerCreated());
		self::assertFalse($golem->isVillageCreated());
		$golem->close();
	}

	public function testGolemPatrolsTheVillageAtNight() : void{
		$this->placeBed(-12, 0);
		$this->set(10, 1, 0, VanillaBlocks::BELL());
		$golem = new IronGolem($this->loc(-12.5, 1, 4.5));
		$golem->spawnToAll();

		$this->world->setTime(6000);
		$goal = new IronGolemMoveThroughVillageGoal($golem);
		self::assertFalse($goal->canStart(), "only at night");

		$this->world->setTime(18000);
		$goal = new IronGolemMoveThroughVillageGoal($golem);
		self::assertTrue($goal->canStart());
		$goal->stop();

		$visited = false;
		for($i = 0; $i < 1200 && !$visited; ++$i){
			$this->runTicks(1);
			$visited = $golem->getPosition()->distance(new Vector3(10.5, 1, 0.5)) < 3.5;
		}
		self::assertTrue($visited, "walked from the bed to the bell");
		$golem->close();
	}

	public function testGolemTargetsHoglinsUnlessPeaceful() : void{
		foreach([World::DIFFICULTY_NORMAL, World::DIFFICULTY_PEACEFUL] as $difficulty){
			$this->difficulty = $difficulty;
			$golem = new IronGolem($this->loc(0.5, 1, 0.5));
			$golem->spawnToAll();
			$hoglin = new Hoglin($this->loc(6.5, 1, 0.5));
			$hoglin->spawnToAll();
			for($i = 0; $i < 60 && $golem->getAttackTarget() === null; ++$i){
				$this->runTicks(1);
			}
			if($difficulty === World::DIFFICULTY_PEACEFUL){
				self::assertNull($golem->getAttackTarget());
			}else{
				self::assertSame($hoglin, $golem->getAttackTarget());
			}
			$golem->close();
			$hoglin->close();
		}
	}

	public function testTargetWhenPushed() : void{
		$golem = new IronGolem($this->loc(0.5, 1, 0.5));
		$zombie = new Zombie($this->loc(1.0, 1, 0.5));
		$creeper = new Creeper($this->loc(0.0, 1, 0.5));
		$hits = 0;
		for($i = 0; $i < 2000; ++$i){
			$golem->notifyEntityContact($zombie);
			if($golem->getAttackTarget() === $zombie){
				++$hits;
				$golem->setAttackTarget(null);
			}
			$golem->notifyEntityContact($creeper);
			self::assertNull($golem->getAttackTarget(), "never a creeper");
		}
		self::assertGreaterThan(50, $hits);
		self::assertLessThan(170, $hits, "about 5%");
		foreach([$golem, $zombie, $creeper] as $entity){
			$entity->close();
		}
	}

	/**
	 * Runs the goal by hand until the golem stops offering its flower or the time runs out.
	 */
	private static function runTakeFlower(TakeFlowerGoal $goal, IronGolem $golem) : void{
		self::assertTrue($goal->canStart());
		$goal->start();
		for($i = 0; $i < TakeFlowerGoal::MAX_WAIT_TICKS + 20 && $goal->canContinue(); ++$i){
			$goal->tick();
		}
		$goal->stop();
	}

	public function testBabyVillagerTakesTheOfferedFlower() : void{
		$this->world->setTime(1000);
		$golem = new IronGolem($this->loc(0.5, 1, 0.5));
		$baby = new VillagerV2($this->loc(2.0, 1, 0.5));
		$baby->setAge(-24000);
		$adult = new VillagerV2($this->loc(-1.0, 1, 0.5));
		$adult->setAge(0);
		$babyGoal = new TakeFlowerGoal($baby, $baby->isBaby(...));
		self::assertFalse($babyGoal->canStart(), "no golem offers a flower");

		$golem->setOfferingFlower(true);
		self::assertFalse((new TakeFlowerGoal($adult, $adult->isBaby(...)))->canStart(), "adults don't take flowers");
		$this->world->setTime(18000);
		self::assertFalse($babyGoal->canStart(), "daytime only");
		$this->world->setTime(1000);
		self::runTakeFlower($babyGoal, $golem);
		self::assertFalse($golem->isOfferingFlower());
		self::assertCount(0, TakeFlowerGoal::getOfferingGolems());
		foreach([$golem, $baby, $adult] as $entity){
			$entity->close();
		}
	}

	public function testCopperGolemTakesFlowerOnlyFromIronGolem() : void{
		$this->world->setTime(1000);
		$copper = new CopperGolem($this->loc(2.0, 1, 0.5));
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$poppy = VanillaBlocks::POPPY()->asItem();
		self::assertFalse((fn() => $this->onInteractWithItem($player, $poppy))->call($copper), "players can't hand it a poppy");
		self::assertFalse($copper->hasFlower());

		$golem = new IronGolem($this->loc(0.5, 1, 0.5));
		$golem->setOfferingFlower(true);
		self::runTakeFlower(new TakeFlowerGoal($copper, fn() => !$copper->hasFlower(), fn() => $copper->setHasFlower(true)), $golem);
		self::assertTrue($copper->hasFlower());
		self::assertFalse($golem->isOfferingFlower());
		$copper->close();
		$golem->close();
	}

	public function testCopperGolemDamageRules() : void{
		$copper = new CopperGolem($this->loc(0.5, 1, 0.5));
		$copper->setOxidationLevel(2);
		$copper->attack(new EntityDamageEvent($copper, EntityDamageEvent::CAUSE_FALL, 5));
		self::assertSame(12.0, $copper->getHealth(), "no fall damage");
		$bolt = new LightningBolt($this->loc(0.5, 1, 0.5), true);
		$copper->onStruckByLightning($bolt);
		self::assertSame(12.0, $copper->getHealth(), "lightning deals no damage");
		self::assertFalse($copper->isOnFire());
		self::assertSame(1, $copper->getOxidationLevel(), "lightning removes one oxidation layer");
		$bolt->close();
		$copper->close();
	}

	public function testSnowGolemDamageRules() : void{
		$snow = new SnowGolem($this->loc(0.5, 1, 0.5));
		$snow->attack(new EntityDamageEvent($snow, EntityDamageEvent::CAUSE_FALL, 3));
		self::assertSame(4.0, $snow->getHealth(), "no fall damage");
		$snow->close();

		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				for($y = 0; $y <= 3; ++$y){
					$this->world->setBiomeId($x, $y, $z, BiomeIds::DESERT);
				}
			}
		}
		$snow = new SnowGolem($this->loc(0.5, 1, 0.5));
		$snow->spawnToAll();
		$this->runTicks(20);
		//1 damage per tick limited by the 10-tick hurt cooldown: 2 or 3 hits in a second
		self::assertLessThanOrEqual(2.0, $snow->getHealth());
		self::assertGreaterThanOrEqual(1.0, $snow->getHealth());
		$snow->close();
	}
}
