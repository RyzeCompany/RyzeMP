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

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Vector3;
use function array_filter;
use function array_values;
use function count;

final class MobBehaviourTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	public function testWalksAlongPathAroundWall() : void{
		for($z = -3; $z <= 3; ++$z){
			$this->set(3, 1, $z, VanillaBlocks::STONE());
			$this->set(3, 2, $z, VanillaBlocks::STONE());
		}
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		//keep other goals (wander, look around) from taking over during the test
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		self::assertTrue($cow->getNavigation()->moveTo(6.5, 1, 0.5, 1.0));
		for($i = 0; $i < 200 && !$cow->getNavigation()->isDone(); ++$i){
			$this->runTicks(1);
		}
		$pos = $cow->getPosition();
		self::assertEqualsWithDelta(6.5, $pos->x, 0.8, "cow should have reached the target, is at $pos");
		self::assertEqualsWithDelta(0.5, $pos->z, 0.8);
	}

	public function testJumpsUpOneBlock() : void{
		for($z = -24; $z <= 24; ++$z){
			$this->set(3, 1, $z, VanillaBlocks::STONE());
			$this->set(4, 1, $z, VanillaBlocks::STONE());
		}
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$zombie->getNavigation()->moveTo(4.5, 2, 0.5, 1.0);
		for($i = 0; $i < 200 && !$zombie->getNavigation()->isDone(); ++$i){
			$this->runTicks(1);
		}
		self::assertEqualsWithDelta(2.0, $zombie->getPosition()->y, 0.01, "zombie should stand on the step, is at " . $zombie->getPosition());
	}

	public function testZombieAttacksTarget() : void{
		$cow = new Cow($this->loc(4.5, 1, 0.5));
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setAttackTarget($cow);
		$health = $cow->getHealth();
		for($i = 0; $i < 100 && $cow->getHealth() >= $health; ++$i){
			$this->runTicks(1);
		}
		self::assertLessThan($health, $cow->getHealth(), "zombie should have hit the cow");
		self::assertInstanceOf(EntityDamageByEntityEvent::class, $cow->getLastDamageCause());
	}

	public function testDamageScalesWithDifficulty() : void{
		self::assertSame(0.0, Zombie::scaleDamageForDifficulty(3, 0));
		self::assertSame(2.5, Zombie::scaleDamageForDifficulty(3, 1));
		self::assertSame(3.0, Zombie::scaleDamageForDifficulty(3, 2));
		self::assertSame(4.5, Zombie::scaleDamageForDifficulty(3, 3));
	}

	public function testBreeding() : void{
		$a = new Cow($this->loc(0.5, 1, 0.5));
		$b = new Cow($this->loc(2.5, 1, 0.5));
		$a->setInLove(Animal::LOVE_TICKS);
		$b->setInLove(Animal::LOVE_TICKS);
		self::assertTrue($a->canMate($b));
		for($i = 0; $i < 300 && count($this->entitiesOf(Cow::class)) < 3; ++$i){
			$this->runTicks(1);
		}
		$cows = $this->entitiesOf(Cow::class);
		self::assertCount(3, $cows, "a baby should have been born");
		$babies = array_values(array_filter($cows, fn(Cow $c) => $c->isBaby()));
		self::assertCount(1, $babies);
		self::assertLessThanOrEqual(Animal::BABY_AGE + 300, $babies[0]->getAge());
		self::assertEqualsWithDelta(0.5, $babies[0]->getScale(), 0.001);
		foreach([$a, $b] as $parent){
			self::assertFalse($parent->isInLove());
			self::assertGreaterThan(Animal::BREED_COOLDOWN - 300, $parent->getAge());
			self::assertFalse($parent->canMate($a === $parent ? $b : $a));
		}
		$xp = array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof \pocketmine\entity\object\ExperienceOrb);
		self::assertNotEmpty($xp, "breeding drops experience");
	}

	public function testDifferentSpeciesDontMate() : void{
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$sheep = new Sheep($this->loc(1.5, 1, 0.5));
		$cow->setInLove(100);
		$sheep->setInLove(100);
		self::assertFalse($cow->canMate($sheep));
	}

	public function testBabyGrowsUpAndFeedingSpeedsItUp() : void{
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->setAge(Animal::BABY_AGE);
		self::assertTrue($cow->isBaby());
		$cow->ageUp((int) (-$cow->getAge() / 10));
		self::assertSame(-21600, $cow->getAge());

		$cow->setAge(-20);
		$this->runTicks(25);
		self::assertFalse($cow->isBaby());
		self::assertEqualsWithDelta(1.0, $cow->getScale(), 0.001);

		$cow->setAge(Animal::BREED_COOLDOWN);
		$this->runTicks(10);
		self::assertSame(Animal::BREED_COOLDOWN - 10, $cow->getAge());
	}

	public function testLoveExpires() : void{
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->setInLove(10);
		$this->runTicks(11);
		self::assertFalse($cow->isInLove());
	}

	public function testCreeperFuseAndExplosion() : void{
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(1.5, 1, 0.5));
		$creeper->ignite();
		$this->runTicks(Creeper::DEFAULT_FUSE - 2);
		self::assertFalse($creeper->hasExploded());
		self::assertGreaterThan(0, $creeper->getSwell());
		$this->runTicks(3);
		self::assertTrue($creeper->hasExploded());
		self::assertTrue($creeper->isClosed() || $creeper->isFlaggedForDespawn());
		self::assertLessThan($cow->getMaxHealth(), $cow->getHealth(), "the explosion should hurt the cow");
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 0, 0)->getTypeId(), "the explosion should break blocks");
	}

	public function testCreeperSwellsNearTargetAndDefuses() : void{
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(2.0, 1, 0.5));
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$creeper->setAttackTarget($cow);
		$this->runTicks(10);
		self::assertSame(1, $creeper->getSwellDirection());
		self::assertGreaterThan(0, $creeper->getSwell());

		//target runs away: the fuse goes back down
		$cow->teleport(new Vector3(15.5, 1, 0.5));
		$creeper->getNavigation()->stop();
		$this->runTicks(4);
		$swell = $creeper->getSwell();
		self::assertSame(-1, $creeper->getSwellDirection());
		$this->runTicks(2);
		self::assertLessThan($swell, $creeper->getSwell());
		self::assertFalse($creeper->hasExploded());
	}

	public function testChickenLaysEggs() : void{
		$chicken = new Chicken($this->loc(0.5, 1, 0.5));
		$chicken->setEggLayTime(2);
		$this->runTicks(3);
		$eggs = array_filter($this->entitiesOf(ItemEntity::class), fn(ItemEntity $e) => $e->getItem()->getTypeId() === ItemTypeIds::EGG);
		self::assertCount(1, $eggs);
		self::assertGreaterThanOrEqual(Chicken::EGG_MIN_TICKS - 5, $chicken->getEggLayTime());
	}

	public function testChickenFallsSlowlyWithoutDamage() : void{
		$chicken = new Chicken($this->loc(0.5, 12, 0.5));
		$this->runTicks(10);
		self::assertGreaterThan(-0.2, $chicken->getMotion()->y);
		$this->runTicks(200);
		self::assertSame((float) $chicken->getMaxHealth(), $chicken->getHealth());
	}

	public function testSheepShearAndRegrow() : void{
		$sheep = new Sheep($this->loc(0.5, 1, 0.5));
		$sheep->setColor(DyeColor::RED);
		self::assertTrue($sheep->shear());
		self::assertTrue($sheep->isSheared());
		self::assertFalse($sheep->shear(), "can't shear twice");
		$wool = array_values(array_filter($this->entitiesOf(ItemEntity::class), fn(ItemEntity $e) => $e->getItem()->getTypeId() === VanillaBlocks::WOOL()->asItem()->getTypeId()));
		self::assertCount(1, $wool);
		self::assertGreaterThanOrEqual(1, $wool[0]->getItem()->getCount());
		self::assertLessThanOrEqual(3, $wool[0]->getItem()->getCount());
		self::assertTrue($wool[0]->getItem()->equals(VanillaBlocks::WOOL()->setColor(DyeColor::RED)->asItem()));

		$sheep->onEatGrass();
		self::assertFalse($sheep->isSheared());
	}

	public function testSheepEatsGrass() : void{
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$sheep = new Sheep($this->loc(0.5, 1, 0.5));
		$sheep->setSheared(true);
		$sheep->setAge(Animal::BABY_AGE); //babies eat 50x more often
		for($i = 0; $i < 3000 && $sheep->isSheared(); ++$i){
			$this->runTicks(1);
			if($sheep->getPosition()->distanceSquared(new Vector3(0.5, 1, 0.5)) > 0.01){
				$sheep->teleport(new Vector3(0.5, 1, 0.5));
			}
		}
		self::assertFalse($sheep->isSheared(), "sheep should have eaten the grass");
		self::assertSame(VanillaBlocks::DIRT()->getTypeId(), $this->get(0, 0, 0)->getTypeId());
	}

	public function testSaveAndLoad() : void{
		$sheep = new Sheep($this->loc(0.5, 1, 0.5));
		$sheep->setColor(DyeColor::LIME);
		$sheep->setSheared(true);
		$sheep->setAge(-1234);
		$sheep->setPersistent(false);
		$nbt = $sheep->saveNBT();
		$copy = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(Sheep::class, $copy);
		self::assertSame(DyeColor::LIME, $copy->getColor());
		self::assertTrue($copy->isSheared());
		self::assertSame(-1234, $copy->getAge());
		self::assertTrue($copy->isBaby());
		self::assertFalse($copy->isPersistent());

		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setBaby(true);
		$copyZombie = EntityFactory::getInstance()->createFromData($this->world, $zombie->saveNBT());
		self::assertInstanceOf(Zombie::class, $copyZombie);
		self::assertTrue($copyZombie->isBaby());

		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$creeper->setPowered(true);
		$copyCreeper = EntityFactory::getInstance()->createFromData($this->world, $creeper->saveNBT());
		self::assertInstanceOf(Creeper::class, $copyCreeper);
		self::assertTrue($copyCreeper->isPowered());
	}

	public function testHostilesRemovedInPeaceful() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(3.5, 1, 0.5));
		$this->difficulty = 0;
		$this->runTicks(21);
		self::assertTrue($zombie->isClosed() || $zombie->isFlaggedForDespawn());
		self::assertFalse($cow->isClosed());
	}

	public function testNaturalColorDistribution() : void{
		$counts = [];
		for($i = 0; $i < 20000; ++$i){
			$color = Sheep::randomNaturalColor();
			$counts[$color->name] = ($counts[$color->name] ?? 0) + 1;
		}
		self::assertEqualsWithDelta(0.818, $counts[DyeColor::WHITE->name] / 20000, 0.02);
		self::assertEqualsWithDelta(0.05, $counts[DyeColor::BLACK->name] / 20000, 0.01);
		self::assertEqualsWithDelta(0.03, $counts[DyeColor::BROWN->name] / 20000, 0.01);
		self::assertArrayNotHasKey(DyeColor::RED->name, $counts);
	}
}
