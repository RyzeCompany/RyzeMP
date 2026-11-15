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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\item\PotionType;
use pocketmine\math\Vector3;
use function count;
use function mt_srand;

/**
 * Raid mobs: targets, pillager melee mode, ravager shield reaction, evoker persistence and witch support.
 */
final class RaidersTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	public function testRavagerIsStunnedHalfOfTheTime() : void{
		$stuns = 0;
		for($i = 0; $i < 10000; ++$i){
			if(Ravager::rollBlockedStun()){
				++$stuns;
			}
		}
		self::assertEqualsWithDelta(5000, $stuns, 250);
	}

	public function testTargets() : void{
		$villager = new VillagerV2($this->loc(2, 1, 0));
		$villager->setAge(0);
		$baby = new VillagerV2($this->loc(3, 1, 0));
		$baby->setAge(VillagerV2::BABY_AGE);
		$trader = new WanderingTrader($this->loc(4, 1, 0));
		$snowGolem = new SnowGolem($this->loc(5, 1, 0));
		$ironGolem = new IronGolem($this->loc(6, 1, 0));
		$pillager = new Pillager($this->loc(7, 1, 0));
		$vindicator = new Vindicator($this->loc(8, 1, 0));

		self::assertTrue(Vex::isVexTarget($villager));
		self::assertFalse(Vex::isVexTarget($baby));
		self::assertTrue(Vex::isVexTarget($trader));
		self::assertTrue(Vex::isVexTarget($ironGolem));
		self::assertFalse(Vex::isVexTarget($snowGolem));
		self::assertFalse(Vex::isVexTarget($pillager));

		self::assertFalse($pillager->isRaidVictim($snowGolem), "pillagers leave snow golems alone");
		self::assertTrue($pillager->isRaidVictim($ironGolem));
		self::assertTrue($vindicator->isRaidVictim($snowGolem));

		foreach([$villager, $baby, $trader, $snowGolem, $ironGolem, $pillager, $vindicator] as $e){
			$e->close();
		}
	}

	public function testVexTargetsVillagers() : void{
		$villager = new VillagerV2($this->loc(6.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$vex = new Vex($this->loc(0.5, 2, 0.5));
		$vex->spawnToAll();
		for($i = 0; $i < 40 && $vex->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($villager, $vex->getAttackTarget());
	}

	public function testVexTargetsTheNearestVillager() : void{
		$far = new VillagerV2($this->loc(22.5, 1, 0.5));
		$far->setAge(0);
		$far->spawnToAll();
		$near = new VillagerV2($this->loc(-18.5, 1, 0.5));
		$near->setAge(0);
		$near->spawnToAll();
		$vex = new Vex($this->loc(0.5, 2, 0.5));
		$vex->spawnToAll();
		for($i = 0; $i < 40 && $vex->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($near, $vex->getAttackTarget());
	}

	public function testSiblingVexesFindANewTarget() : void{
		//the vexes of one evoker share their searches that found nothing, which must not keep them from a later target
		$baby = new VillagerV2($this->loc(20.5, 1, 0.5));
		$baby->setAge(VillagerV2::BABY_AGE);
		$baby->spawnToAll();
		$evoker = new Evoker($this->loc(0.5, 1, -6.5));
		$evoker->spawnToAll();
		$vexes = [];
		for($i = 0; $i < 3; ++$i){
			$vex = new Vex($this->loc(0.5 + $i, 2, 0.5));
			$vex->setOwningEntity($evoker);
			$vex->spawnToAll();
			$vexes[] = $vex;
		}
		$this->runTicks(30);
		foreach($vexes as $vex){
			self::assertNull($vex->getAttackTarget());
		}
		$villager = new VillagerV2($this->loc(-20.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$targeted = [];
		for($i = 0; $i < 300 && count($targeted) < count($vexes); ++$i){
			$this->runTicks(1);
			foreach($vexes as $k => $vex){
				if($vex->getAttackTarget() === $villager){
					$targeted[$k] = true;
				}
			}
		}
		self::assertCount(count($vexes), $targeted, "every vex finds the villager (vexes below the floor cannot see it)");
	}

	public function testPillagerFightsInMeleeUnderwater() : void{
		//a walled pool, 3 deep
		for($y = 1; $y <= 4; ++$y){
			for($x = -3; $x <= 3; ++$x){
				for($z = -3; $z <= 3; ++$z){
					$wall = $x === -3 || $x === 3 || $z === -3 || $z === 3;
					$this->set($x, $y, $z, $wall ? VanillaBlocks::STONE() : ($y <= 3 ? VanillaBlocks::WATER() : VanillaBlocks::AIR()));
				}
			}
		}
		$pillager = new Pillager($this->loc(0.5, 1, 0.5));
		$pillager->spawnToAll();
		$golem = new IronGolem($this->loc(10.5, 1, 10.5));
		$golem->spawnToAll();
		$pillager->setAttackTarget($golem);
		self::assertFalse($pillager->isMeleeMode());
		$this->runTicks(10);
		self::assertTrue($pillager->isMeleeMode(), "underwater: melee");

		$pillager->teleport(new Vector3(-15.5, 1, -15.5));
		$this->runTicks(10);
		self::assertFalse($pillager->isMeleeMode(), "out of the water: crossbow again");
	}

	public function testEvokerAlwaysGivesExperienceAndNeverDespawns() : void{
		$evoker = new Evoker($this->loc(0.5, 1, 0.5));
		$evoker->setPersistent(false);
		self::assertSame(10, $evoker->getXpDropAmount());
		self::assertFalse($evoker->canDespawn());
		$evoker->close();
	}

	public function testWitchPotionsForRaiders() : void{
		$center = new Vector3(0, 1, 0);
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->joinRaid(1, 1, $center);
		$pillager = new Pillager($this->loc(3.5, 1, 0.5));
		$pillager->joinRaid(1, 1, $center);
		self::assertSame(PotionType::REGENERATION, $witch->choosePotion($pillager));
		$pillager->setHealth(4);
		self::assertSame(PotionType::HEALING, $witch->choosePotion($pillager));
		$witch->close();
		$pillager->close();
	}

	public function testWitchFightsRaidersOutsideRaids() : void{
		//e.g. a "Johnny" vindicator or a patrol illager that hurt the witch: offensive potions, and the target is kept
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$vindicator = new Vindicator($this->loc(3.5, 1, 0.5));
		self::assertNotContains($witch->choosePotion($vindicator), [PotionType::HEALING, PotionType::REGENERATION]);

		//a raider in a raid hitting a witch that is not in it is fought back too
		$vindicator->joinRaid(1, 1, new Vector3(0, 1, 0));
		self::assertNotContains($witch->choosePotion($vindicator), [PotionType::HEALING, PotionType::REGENERATION]);
		$witch->close();
		$vindicator->close();
	}

	public function testWitchKeepsANonRaidRaiderTarget() : void{
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->spawnToAll();
		$vindicator = new Vindicator($this->loc(4.5, 1, 0.5));
		$vindicator->spawnToAll();
		$witch->setAttackTarget($vindicator);
		$witch->throwPotion($vindicator, 4.0);
		self::assertSame($vindicator, $witch->getAttackTarget());
	}

	public function testWitchHealsAHurtRaider() : void{
		$center = new Vector3(0, 1, 0);
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->joinRaid(1, 1, $center);
		$witch->spawnToAll();
		$pillager = new Pillager($this->loc(5.5, 1, 0.5));
		$pillager->joinRaid(1, 1, $center);
		$pillager->setHealth(3);
		$pillager->spawnToAll();

		$thrown = null;
		for($i = 0; $i < 200 && $thrown === null; ++$i){
			$this->runTicks(1);
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof SplashPotion){
					$thrown = $entity->getPotionType();
				}
			}
		}
		self::assertSame(PotionType::HEALING, $thrown);
		self::assertNull($witch->getAttackTarget(), "the witch forgets the raider once it has thrown the potion");
	}

	public function testWitchTargetsGolems() : void{
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->spawnToAll();
		$golem = new SnowGolem($this->loc(6.5, 1, 0.5));
		$golem->spawnToAll();
		for($i = 0; $i < 40 && $witch->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($golem, $witch->getAttackTarget());
	}

	public function testWitchOutsideRaidsIgnoresRaiders() : void{
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->spawnToAll();
		$pillager = new Pillager($this->loc(5.5, 1, 0.5));
		$pillager->setHealth(3);
		$pillager->spawnToAll();
		$this->runTicks(60);
		self::assertNotSame($pillager, $witch->getAttackTarget());
	}

	public function testWitchDrinksFireResistanceOnMagma() : void{
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$witch = new Witch($this->loc(0.5, 1, 0.5));
		$witch->spawnToAll();
		$drank = false;
		for($i = 0; $i < 400 && !$drank; ++$i){
			$this->runTicks(1);
			$witch->teleport(new Vector3(0.5, 1, 0.5));
			$drank = $witch->getEffects()->has(VanillaEffects::FIRE_RESISTANCE());
		}
		self::assertTrue($drank);
	}
}
