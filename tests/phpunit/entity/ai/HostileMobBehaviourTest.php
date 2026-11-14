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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Bogged;
use pocketmine\entity\monster\Breeze;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Phantom;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Shulker;
use pocketmine\entity\monster\Stray;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\object\EvokerFangs;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\BreezeWindCharge;
use pocketmine\entity\projectile\MobTrident;
use pocketmine\entity\projectile\ShulkerBullet;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\world\WorldManager;
use function array_filter;
use function array_values;
use function count;
use function max;
use function mt_srand;

final class HostileMobBehaviourTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261013);
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Returns a cow that doesn't move and can take a lot of damage, to be used as a target.
	 */
	private function dummy(float $x, float $z) : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setMaxHealth(10000);
		$cow->setHealth(10000);
		return $cow;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Runs ticks until the condition is true (at most $maxTicks). Returns whether it became true.
	 *
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function tickUntil(\Closure $condition, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return true;
			}
			$this->runTicks(1);
		}
		return $condition();
	}

	private function attacks(Mob $mob, float $targetDistance = 6.0, int $maxTicks = 200) : Cow{
		$cow = $this->dummy($mob->getPosition()->x + $targetDistance, $mob->getPosition()->z);
		$mob->setAttackTarget($cow);
		$health = $cow->getHealth();
		self::assertTrue($this->tickUntil(fn() => $cow->getHealth() < $health, $maxTicks), $mob->getName() . " should hurt its target");
		return $cow;
	}

	public function testTippedArrowSkeletons() : void{
		$stray = new Stray($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(8.5, 0.5);
		$stray->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(Arrow::class)) > 0, 120), "the stray shoots");
		self::assertSame(PotionType::LONG_SLOWNESS, $this->entitiesOf(Arrow::class)[0]->getPotionType());

		$bogged = new Bogged($this->loc(0.5, 1, 10.5));
		self::assertSame(16, $bogged->getMaxHealth());
		$stray->flagForDespawn();
		$cow = $this->dummy(8.5, 10.5);
		$bogged->setAttackTarget($cow);
		$boggedArrows = fn() => array_values(array_filter($this->entitiesOf(Arrow::class), fn(Arrow $a) => $a->getOwningEntityId() === $bogged->getId()));
		self::assertTrue($this->tickUntil(fn() => count($boggedArrows()) > 0, 200), "the bogged shoots");
		self::assertSame(PotionType::POISON, $boggedArrows()[0]->getPotionType());
	}

	public function testMeleeEffects() : void{
		$husk = new Husk($this->loc(0.5, 1, 0.5));
		$cow = $this->attacks($husk, 2.0);
		self::assertTrue($cow->getEffects()->has(VanillaEffects::HUNGER()), "husks give hunger");
		$husk->flagForDespawn();

		$wither = new WitherSkeleton($this->loc(0.5, 1, 10.5));
		self::assertEqualsWithDelta(2.01, $wither->getSize()->getHeight(), 0.01);
		$cow = $this->attacks($wither, 2.0);
		self::assertTrue($cow->getEffects()->has(VanillaEffects::WITHER()), "wither skeletons wither");
	}

	public function testRangedAttackers() : void{
		$pillager = new Pillager($this->loc(0.5, 1, 0.5));
		self::assertSame(VanillaItems::CROSSBOW()->getTypeId(), $pillager->getMainHandItem()->getTypeId());
		$this->attacks($pillager, 6.0, 300);
		$pillager->flagForDespawn();

		$witch = new Witch($this->loc(0.5, 1, 10.5));
		$cow = $this->dummy(6.5, 10.5);
		$witch->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(SplashPotion::class)) > 0, 120), "the witch throws potions");
		$witch->flagForDespawn();

		$blaze = new Blaze($this->loc(0.5, 1, -10.5));
		$cow = $this->dummy(8.5, -10.5);
		$blaze->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(SmallFireball::class)) > 0, 120), "the blaze shoots fireballs");
		self::assertTrue($blaze->isCharged());
	}

	public function testDrownedThrowsTrident() : void{
		$drowned = new Drowned($this->loc(0.5, 1, 0.5));
		$drowned->setMainHandItem(VanillaItems::TRIDENT());
		self::assertTrue($drowned->hasTrident());
		$cow = $this->dummy(7.5, 0.5);
		$drowned->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(MobTrident::class)) > 0, 200), "drowned with tridents throw them");
	}

	public function testBreezeShootsWindCharges() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(8.5, 0.5);
		$breeze->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(BreezeWindCharge::class)) > 0, 200), "breezes shoot wind charges");
		$health = $breeze->getHealth();
		$breeze->attack(new EntityDamageEvent($breeze, EntityDamageEvent::CAUSE_PROJECTILE, 4));
		self::assertSame($health, $breeze->getHealth(), "arrows don't hurt breezes");
	}

	public function testEvokerSpells() : void{
		$evoker = new Evoker($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(10.5, 0.5);
		$evoker->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(EvokerFangs::class)) > 0 || count($this->entitiesOf(Vex::class)) > 0, 200), "the evoker casts a spell");
		self::assertTrue($this->tickUntil(fn() => $cow->getHealth() < 10000 || count($this->entitiesOf(Vex::class)) > 0, 100));
		$evoker->summonVexes();
		$vexes = $this->entitiesOf(Vex::class);
		self::assertGreaterThanOrEqual(3, count($vexes));
		self::assertSame($evoker, $vexes[0]->getOwningEntity());
	}

	public function testVexFliesThroughBlocks() : void{
		for($y = 1; $y <= 4; ++$y){
			for($z = -3; $z <= 3; ++$z){
				$this->set(3, $y, $z, VanillaBlocks::STONE());
			}
		}
		$vex = new Vex($this->loc(0.5, 2, 0.5));
		$vex->getFlightControl()->setWantedPosition(6.5, 2, 0.5, Vex::SPEED);
		$vex->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		self::assertTrue($this->tickUntil(fn() => $vex->getPosition()->x > 4.5, 100), "the vex went through the wall, is at " . $vex->getPosition());
		$health = $vex->getHealth();
		$this->runTicks(5);
		self::assertSame($health, $vex->getHealth(), "vexes don't suffocate in blocks");
	}

	public function testPhantomSwoops() : void{
		$phantom = new Phantom($this->loc(0.5, 12, 0.5));
		$this->attacks($phantom, 3.0, 600);
	}

	public function testShulkerBullets() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(6.5, 0.5);
		$shulker->setAttackTarget($cow);
		self::assertTrue($this->tickUntil(fn() => count($this->entitiesOf(ShulkerBullet::class)) > 0, 100), "the shulker shoots");
		self::assertSame(Shulker::PEEK_OPEN, $shulker->getPeek());
		self::assertTrue($this->tickUntil(fn() => $cow->getEffects()->has(VanillaEffects::LEVITATION()), 200), "bullets levitate their target");
		$position = $shulker->getPosition();
		$shulker->knockBack(1, 0, 1);
		$this->runTicks(5);
		self::assertTrue($position->equals($shulker->getPosition()), "shulkers don't move");
	}

	public function testShulkersIgnoreShulkerAttackers() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		$other = new Shulker($this->loc(3.5, 1, 0.5));
		$cow = $this->dummy(6.5, 0.5);

		//its own bullet and another shulker's bullet: no retaliation (vanilla HurtByTargetGoal(this, Shulker.class))
		foreach([$shulker, $other] as $damager){
			$shulker->attack(new EntityDamageByEntityEvent($damager, $shulker, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			$this->runTicks(5);
			self::assertNull($shulker->getAttackTarget());
		}

		$shulker->attack(new EntityDamageByEntityEvent($cow, $shulker, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertTrue($this->tickUntil(fn() => $shulker->getAttackTarget() === $cow, 20), "other attackers are still targeted");
	}

	public function testMobsNeverTargetThemselves() : void{
		$shulker = new Shulker($this->loc(0.5, 1, 0.5));
		self::assertFalse($shulker->getSensing()->isValidTarget($shulker, 16.0));
	}

	public function testZombifiedPiglinGroupAnger() : void{
		$a = new ZombifiedPiglin($this->loc(0.5, 1, 0.5));
		$b = new ZombifiedPiglin($this->loc(5.5, 1, 0.5));
		$far = new ZombifiedPiglin($this->loc(0.5, 1, 22.5));
		$attacker = new Vindicator($this->loc(-3.5, 1, 0.5));
		$attacker->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$this->runTicks(1);
		self::assertFalse($a->isAngry());
		$a->attack(new EntityDamageByEntityEvent($attacker, $a, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertTrue($a->isAngry());
		self::assertTrue($b->isAngry(), "nearby zombified piglins join in");
		self::assertFalse($far->isAngry(), "beyond 20 blocks they don't");
		self::assertSame($attacker, $b->getAngerTarget());
		self::assertTrue($this->tickUntil(fn() => $b->getAttackTarget() === $attacker, 30));
	}

	public function testStriderStaysOnLava() : void{
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->set($x, 0, $z, VanillaBlocks::LAVA());
			}
		}
		$strider = new Strider($this->loc(0.5, 1.2, 0.5));
		$strider->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$this->runTicks(60);
		self::assertTrue($strider->isAlive());
		self::assertTrue($strider->isOnLava());
		self::assertFalse($strider->isCold());
		self::assertGreaterThan(0.7, $strider->getPosition()->y, "it doesn't sink in the lava");

		$cold = new Strider($this->loc(10.5, 1, 10.5));
		$this->runTicks(2);
		self::assertTrue($cold->isCold(), "out of lava it shivers");
	}

	public function testSulfurCubeHops() : void{
		$cube = new SulfurCube($this->loc(0.5, 1, 0.5));
		$maxY = 1.0;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			$maxY = max($maxY, $cube->getPosition()->y);
		}
		self::assertGreaterThan(1.3, $maxY);
	}
}
