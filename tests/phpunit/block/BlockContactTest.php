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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\LightArray;
use pocketmine\world\GameRules;
use function mt_srand;

/**
 * Blocks acting on the entities touching them: magma, slime, hay bales, beds, sweet berry bushes, powder snow and
 * turtle eggs, and Slow Falling.
 */
final class BlockContactTest extends MobTestCase{

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	/**
	 * Surrounds the 1x1 column at x, z with stone from y=1 to y=3, so that a mob in it can't walk away.
	 */
	private function pit(int $x, int $z) : void{
		for($y = 1; $y <= 3; ++$y){
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					if($dx !== 0 || $dz !== 0){
						$this->set($x + $dx, $y, $z + $dz, VanillaBlocks::STONE());
					}
				}
			}
		}
	}

	private function cowAt(float $x, float $y, float $z) : Cow{
		return new Cow(new Location($x, $y, $z, $this->world, 0, 0));
	}

	public function testMagmaHurtsWhoStandsOnIt() : void{
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$this->pit(0, 0);
		$cow = $this->cowAt(0.5, 1, 0.5);
		$this->runTicks(40);
		self::assertLessThan($cow->getMaxHealth(), $cow->getHealth());
		self::assertSame(EntityDamageEvent::CAUSE_MAGMA, $cow->getLastDamageCause()?->getCause());
		$cow->close();
	}

	public function testMagmaPlacedUnderAStandingMob() : void{
		$this->pit(0, 0);
		$cow = $this->cowAt(0.5, 1, 0.5);
		$this->runTicks(20);
		self::assertSame((float) $cow->getMaxHealth(), $cow->getHealth());
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$this->runTicks(20);
		self::assertLessThan($cow->getMaxHealth(), $cow->getHealth(), "the block change is noticed");
		$cow->close();
	}

	public function testMagmaSparesSneakingAndFireResistant() : void{
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$this->pit(0, 0);
		$this->set(4, 0, 0, VanillaBlocks::MAGMA());
		$this->pit(4, 0);
		$sneaking = $this->cowAt(0.5, 1, 0.5);
		$sneaking->setSneaking(true);
		$resistant = $this->cowAt(4.5, 1, 0.5);
		$resistant->getEffects()->add(new EffectInstance(VanillaEffects::FIRE_RESISTANCE(), 20 * 60));
		$this->runTicks(40);
		self::assertSame((float) $sneaking->getMaxHealth(), $sneaking->getHealth());
		self::assertSame((float) $resistant->getMaxHealth(), $resistant->getHealth());
		$sneaking->close();
		$resistant->close();
	}

	public function testSlowFallingPreventsFallDamage() : void{
		$slow = $this->cowAt(0.5, 41, 0.5);
		$slow->getEffects()->add(new EffectInstance(VanillaEffects::SLOW_FALLING(), 20 * 60));
		$control = $this->cowAt(8.5, 41, 0.5);
		$this->runTicks(600);
		self::assertTrue($slow->isOnGround(), "landed at " . $slow->getPosition());
		self::assertSame((float) $slow->getMaxHealth(), $slow->getHealth());
		self::assertTrue(!$control->isAlive() || $control->getHealth() < $control->getMaxHealth(), "control cow is hurt");
		$slow->close();
		$control->close();
	}

	public function testHayBaleCutsTheDamageNotTheSafeDistance() : void{
		$this->set(0, 0, 0, VanillaBlocks::HAY_BALE());
		$cow = $this->cowAt(0.5, 21, 0.5);
		$this->runTicks(60);
		//ceil((20 - 3) * 0.2) = 4
		self::assertSame($cow->getMaxHealth() - 4.0, $cow->getHealth());
		$cow->close();
	}

	public function testBedHalvesTheDamageEvenWhenSneaking() : void{
		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set(0, 0, 0, (clone $bed)->setHead(false));
		$this->set(0, 0, 1, (clone $bed)->setHead(true));
		$cow = $this->cowAt(0.5, 0.5625 + 16, 0.5);
		$cow->setSneaking(true);
		$this->runTicks(60);
		//ceil((16 - 3) * 0.5) = 7
		self::assertSame($cow->getMaxHealth() - 7.0, $cow->getHealth());
		$cow->close();
	}

	public function testItemsBounceOnSlime() : void{
		$this->set(0, 0, 0, VanillaBlocks::SLIME());
		$item = new ItemEntity(new Location(0.5, 6, 0.5, $this->world, 0, 0), VanillaItems::STICK());
		$item->spawnToAll();
		$lowest = 6.0;
		$bounced = false;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			$y = $item->getPosition()->y;
			if($y < $lowest){
				$lowest = $y;
			}elseif($y > $lowest + 0.2){
				$bounced = true;
				break;
			}
		}
		self::assertTrue($bounced, "the item bounced up from the slime block");
		$item->close();
	}

	public function testSlimeSlowsWalkers() : void{
		$slime = VanillaBlocks::SLIME();
		$this->set(0, 0, 0, $slime);
		$slime = $this->get(0, 0, 0);
		self::assertInstanceOf(Slime::class, $slime);
		$cow = $this->cowAt(0.5, 1, 0.5);
		$cow->setMotion(new Vector3(0.5, 0, 0));
		$slime->onEntityStepOn($cow);
		self::assertEqualsWithDelta(0.2, $cow->getMotion()->x, 0.0001);

		$cow->setSneaking(true);
		$cow->setMotion(new Vector3(0.5, 0, 0));
		$slime->onEntityStepOn($cow);
		self::assertEqualsWithDelta(0.5, $cow->getMotion()->x, 0.0001);
		$cow->close();
	}

	public function testBerryBushHurtsOnlyWhileMoving() : void{
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
		$this->pit(0, 0);
		$this->set(0, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
		$still = $this->cowAt(0.5, 1, 0.5);
		$this->runTicks(60);
		self::assertSame((float) $still->getMaxHealth(), $still->getHealth(), "a mob standing still in a bush is not hurt");
		$still->close();

		//a corridor of bushes, the cow pushed along it
		for($x = 4; $x <= 12; ++$x){
			$this->set($x, 0, 0, VanillaBlocks::GRASS());
			$this->set($x, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
		}
		$moving = $this->cowAt(4.5, 1, 0.5);
		for($i = 0; $i < 30; ++$i){
			$moving->addMotion(0.05, 0, 0);
			$this->runTicks(1);
		}
		self::assertLessThan($moving->getMaxHealth(), $moving->getHealth());
		self::assertSame(EntityDamageEvent::CAUSE_SWEET_BERRY_BUSH, $moving->getLastDamageCause()?->getCause());
		$moving->close();
	}

	public function testBerryGrowthNeedsLight() : void{
		mt_srand(1234);
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH());
		//the test world has no light
		for($i = 0; $i < 100; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertSame(SweetBerryBush::STAGE_SAPLING, $this->getBush(0, 1, 0)->getAge());

		$this->fullSkyLight();
		for($i = 0; $i < 100; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertSame(SweetBerryBush::STAGE_MATURE, $this->getBush(0, 1, 0)->getAge());
	}

	public function testBerryGrowthChance() : void{
		mt_srand(42);
		$this->fullSkyLight();
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$grown = 0;
		for($i = 0; $i < 5000; ++$i){
			$this->set(0, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH());
			$this->get(0, 1, 0)->onRandomTick();
			if($this->getBush(0, 1, 0)->getAge() > 0){
				++$grown;
			}
		}
		//1 in 5 (1 in 3 would be ~1667)
		self::assertGreaterThan(900, $grown);
		self::assertLessThan(1100, $grown);
	}

	private function fullSkyLight() : void{
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$chunk->getSubChunk(0)->setBlockSkyLightArray(LightArray::fill(15));
		$chunk->setLightPopulated(true);
	}

	private function getBush(int $x, int $y, int $z) : SweetBerryBush{
		$bush = $this->get($x, $y, $z);
		self::assertInstanceOf(SweetBerryBush::class, $bush);
		return $bush;
	}

	private function powderSnowPit(int $x) : void{
		for($y = 1; $y <= 3; ++$y){
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					$this->set($x + $dx, $y, $dz, $dx === 0 && $dz === 0 ? VanillaBlocks::POWDER_SNOW() : VanillaBlocks::STONE());
				}
			}
		}
	}

	public function testBurningMobMeltsPowderSnowWithMobGriefing() : void{
		$this->powderSnowPit(0);
		$this->powderSnowPit(4);
		$cow = $this->cowAt(0.5, 4, 0.5);
		$cow->setOnFire(10);
		$this->runTicks(40);
		self::assertFalse($cow->isOnFire());
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 3, 0)->getTypeId(), "the burning cow melted the powder snow");
		$cow->close();

		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$cow = $this->cowAt(4.5, 4, 0.5);
		$cow->setOnFire(10);
		$this->runTicks(40);
		self::assertFalse($cow->isOnFire());
		self::assertSame(BlockTypeIds::POWDER_SNOW, $this->get(4, 3, 0)->getTypeId(), "no mobgriefing: only put out");
		$cow->close();
	}

	private function eggs(int $x, int $z, int $count = 4) : TurtleEgg{
		$this->set($x, 1, $z, VanillaBlocks::TURTLE_EGG()->setCount($count));
		$egg = $this->get($x, 1, $z);
		self::assertInstanceOf(TurtleEgg::class, $egg);
		return $egg;
	}

	public function testStandingOnEggsTramplesThem() : void{
		mt_srand(7);
		$this->pit(0, 0);
		$this->eggs(0, 0);
		$cow = $this->cowAt(0.5, 1 + 7 / 16, 0.5);
		$this->runTicks(1000);
		$egg = $this->get(0, 1, 0);
		self::assertTrue(!$egg instanceof TurtleEgg || $egg->getCount() < 4, "the cow broke at least one egg");
		$cow->close();
	}

	public function testEggTrampleChances() : void{
		mt_srand(99);
		$cow = $this->cowAt(0.5, 2, 0.5);
		$turtle = new Turtle(new Location(0.5, 2, 0.5, $this->world, 0, 0));
		$stepped = $landed = $byTurtle = $sneaking = 0;
		for($i = 0; $i < 3000; ++$i){
			$this->eggs(0, 0)->onEntityStepOn($cow);
			$stepped += 4 - $this->eggCount();
			$this->eggs(0, 0)->onEntityLand($cow);
			$landed += 4 - $this->eggCount();
			$this->eggs(0, 0)->onEntityLand($turtle);
			$byTurtle += 4 - $this->eggCount();
		}
		$cow->setSneaking(true);
		for($i = 0; $i < 1000; ++$i){
			$this->eggs(0, 0)->onEntityStepOn($cow);
			$sneaking += 4 - $this->eggCount();
		}
		//1 in 100 standing, 1 in 3 landing
		self::assertGreaterThan(10, $stepped);
		self::assertLessThan(60, $stepped);
		self::assertGreaterThan(850, $landed);
		self::assertLessThan(1150, $landed);
		self::assertSame(0, $byTurtle);
		self::assertSame(0, $sneaking);

		//the last egg takes the block away; no mobgriefing: mobs don't break them
		$this->eggs(0, 0, 1)->breakOneEgg();
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$cow->setSneaking(false);
		$this->eggs(0, 0);
		for($i = 0; $i < 100; ++$i){
			$this->get(0, 1, 0)->onEntityLand($cow);
		}
		self::assertSame(4, $this->eggCount());
		$cow->close();
		$turtle->close();
	}

	private function eggCount() : int{
		$egg = $this->get(0, 1, 0);
		return $egg instanceof TurtleEgg ? $egg->getCount() : 0;
	}

	public function testEggsHatchAroundDawnOnAnySand() : void{
		mt_srand(5);
		$this->set(0, 0, 0, VanillaBlocks::RED_SAND());
		$this->set(2, 0, 0, VanillaBlocks::SUSPICIOUS_SAND());
		$this->set(4, 0, 0, VanillaBlocks::DIRT());
		$this->world->setTime(21500);
		foreach([0, 2, 4] as $x){
			$this->set($x, 1, 0, VanillaBlocks::TURTLE_EGG());
			$this->get($x, 1, 0)->onRandomTick();
		}
		self::assertSame(1, $this->eggAt(0)->getCracks(), "red sand, at 21500");
		self::assertSame(1, $this->eggAt(2)->getCracks(), "suspicious sand");
		self::assertSame(0, $this->eggAt(4)->getCracks(), "not on sand");

		//the old window (15600-16560) is not dawn: 1 in 500 there
		$this->world->setTime(16000);
		$cracked = 0;
		for($i = 0; $i < 100; ++$i){
			$this->set(0, 1, 0, VanillaBlocks::TURTLE_EGG());
			$this->get(0, 1, 0)->onRandomTick();
			$cracked += $this->eggAt(0)->getCracks();
		}
		self::assertLessThan(3, $cracked);
	}

	private function eggAt(int $x) : TurtleEgg{
		$egg = $this->get($x, 1, 0);
		self::assertInstanceOf(TurtleEgg::class, $egg);
		return $egg;
	}
}
