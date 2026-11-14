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

namespace pocketmine\entity\monster;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\hostile\goal\BreezeAttackGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\BreezeWindCharge;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function abs;
use function atan2;
use function deg2rad;
use function mt_srand;
use function rad2deg;
use function sqrt;

/**
 * Breezes: projectile reflection, jump_around_target, targets, persistence, wind charge accuracy.
 */
final class BreezeTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
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
	 * Runs ticks until the condition is true (at most $maxTicks). Returns the ticks it took, or -1.
	 *
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function ticksUntil(\Closure $condition, int $maxTicks) : int{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return $i;
			}
			$this->runTicks(1);
		}
		return $condition() ? $maxTicks : -1;
	}

	private static function gone(Entity $entity) : bool{
		return $entity->isClosed() || $entity->isFlaggedForDespawn();
	}

	private function horizontalAngle(Vector3 $a, Vector3 $b) : float{
		$angle = abs(rad2deg(atan2($b->z, $b->x) - atan2($a->z, $a->x)));
		return $angle > 180 ? 360 - $angle : $angle;
	}

	public function testReflectedMotion() : void{
		$motion = new Vector3(2, -0.2, 1);
		$reflected = Breeze::reflectedMotion($motion, deg2rad(180));
		self::assertEqualsWithDelta(-1.0, $reflected->x, 1e-9);
		self::assertEqualsWithDelta(-0.1, $reflected->y, 1e-9);
		self::assertEqualsWithDelta(-0.5, $reflected->z, 1e-9);
		foreach([160.0, 200.0] as $degrees){
			$reflected = Breeze::reflectedMotion($motion, deg2rad($degrees));
			self::assertEqualsWithDelta(160.0, $this->horizontalAngle($motion, $reflected), 1e-6);
			self::assertEqualsWithDelta(0.5 * sqrt(5), sqrt($reflected->x ** 2 + $reflected->z ** 2), 1e-9, "half the speed");
		}
		self::assertTrue(Breeze::reflects(new Snowball($this->loc(5.5, 2, 5.5), null)));
		self::assertFalse(Breeze::reflects(new BreezeWindCharge($this->loc(5.5, 2, 5.5), null)), "wind charges hit breezes");
	}

	public function testArrowsAreReflected() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$breeze->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$health = $breeze->getHealth();
		$arrow = new Arrow($this->loc(-5.5, 2, 0.5), null, false);
		$arrow->setMotion(new Vector3(2, 0, 0));
		$arrow->spawnToAll();
		self::assertNotSame(-1, $this->ticksUntil(fn() => $arrow->getMotion()->x < 0, 10), "the arrow turns back");
		self::assertFalse(self::gone($arrow), "the arrow keeps flying");
		$motion = $arrow->getMotion();
		self::assertGreaterThanOrEqual(160.0 - 1, $this->horizontalAngle(new Vector3(1, 0, 0), $motion));
		$speed = sqrt($motion->x ** 2 + $motion->z ** 2);
		self::assertTrue($speed > 0.85 && $speed <= 1.0, "about half its speed, got $speed");
		$x = $arrow->getPosition()->x;
		$this->runTicks(3);
		self::assertFalse(self::gone($arrow), "it doesn't hit the breeze again");
		self::assertLessThan($x, $arrow->getPosition()->x, "it flies back");
		self::assertSame($health, $breeze->getHealth(), "no damage");
	}

	public function testTargetsAndIgnoredAttackers() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		self::assertTrue($breeze->isPersistent(), "breezes are persistent");
		self::assertFalse($breeze->canDespawn());
		$golem = new IronGolem($this->loc(8.5, 1, 0.5));
		$golem->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP | Goal::FLAG_TARGET, true);
		self::assertNotSame(-1, $this->ticksUntil(fn() => $breeze->getAttackTarget() === $golem, 60), "breezes attack iron golems");
		$golem->flagForDespawn();
		$this->runTicks(5);

		$other = new Breeze($this->loc(-10.5, 1, 10.5));
		$skeleton = new Skeleton($this->loc(-12.5, 1, 10.5));
		$skeleton->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP | Goal::FLAG_TARGET, true);
		$other->attack(new EntityDamageByEntityEvent($skeleton, $other, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(20);
		self::assertNull($other->getAttackTarget(), "breezes don't retaliate against skeletons");

		self::assertTrue(Breeze::ignoresAttacker(new Stray($this->loc(5.5, 1, -10.5))));
		self::assertTrue(Breeze::ignoresAttacker(new Husk($this->loc(6.5, 1, -10.5))));
		self::assertTrue(Breeze::ignoresAttacker(new Zombie($this->loc(7.5, 1, -10.5))));
		self::assertTrue(Breeze::ignoresAttacker(new CaveSpider($this->loc(8.5, 1, -10.5))));
		self::assertFalse(Breeze::ignoresAttacker(new ZombifiedPiglin($this->loc(9.5, 1, -10.5))));
		self::assertFalse(Breeze::ignoresAttacker(new MagmaCube($this->loc(10.5, 1, -10.5))));

		$zombified = new ZombifiedPiglin($this->loc(-8.5, 1, 10.5));
		$zombified->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP | Goal::FLAG_TARGET, true);
		$other->attack(new EntityDamageByEntityEvent($zombified, $other, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertNotSame(-1, $this->ticksUntil(fn() => $other->getAttackTarget() === $zombified, 20), "other mobs are retaliated against");
	}

	public function testWindChargeAccuracy() : void{
		self::assertEqualsWithDelta(0.0075, Breeze::windChargeInaccuracy(World::DIFFICULTY_EASY), 1e-12);
		self::assertSame(0.0, Breeze::windChargeInaccuracy(World::DIFFICULTY_NORMAL), "perfect aim on normal");
		self::assertSame(0.0, Breeze::windChargeInaccuracy(World::DIFFICULTY_HARD));
	}

	public function testJumpVelocityLandsOnTheSpot() : void{
		$from = new Vector3(0.5, 1, 0.5);
		foreach([[new Vector3(8.5, 1, 3.5), 55.0], [new Vector3(-6.5, 3, 0.5), 75.0], [new Vector3(2.5, -2, -9.5), 40.0]] as [$to, $angle]){
			$velocity = BreezeAttackGoal::solveJumpVelocity($from, $to, $angle, 0.08, 0.02, BreezeAttackGoal::MAX_JUMP_VELOCITY);
			self::assertNotNull($velocity);
			self::assertLessThanOrEqual(BreezeAttackGoal::MAX_JUMP_VELOCITY + 1e-9, $velocity->length());
			//fly it like a mob moves in the air
			$pos = clone $from;
			$v = clone $velocity;
			for($i = 0; $i < 200; ++$i){
				$v = new Vector3($v->x * 0.98, ($v->y - 0.08) * 0.98, $v->z * 0.98);
				$next = $pos->addVector($v);
				if($v->y < 0 && $next->y <= $to->y){
					break;
				}
				$pos = $next;
			}
			$landedDistance = sqrt(($pos->x - $to->x) ** 2 + ($pos->z - $to->z) ** 2);
			self::assertLessThan(1.5, $landedDistance, "lands near the spot (half a block short, like Java)");
		}
		self::assertNull(BreezeAttackGoal::solveJumpVelocity($from, new Vector3(30.5, 1, 0.5), 40.0, 0.08, 0.02, 1.4), "too far for 1.4 blocks per tick");
		self::assertNull(BreezeAttackGoal::solveJumpVelocity($from, new Vector3(3.5, 9, 0.5), 40.0, 0.08, 0.02, 1.4), "too high for a flat angle");
	}

	public function testJumpsOnlyAtValidDistances() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(18.5, 0.5);
		$breeze->setAttackTarget($cow);
		$prepared = false;
		$leapt = $landed = false;
		$targetAtTakeOff = null;
		for($i = 0; $i < 200 && !$landed; ++$i){
			$wasPreparing = $breeze->isPreparingJump();
			$wasLeaping = $breeze->isLeaping();
			$this->runTicks(1);
			if(!$wasPreparing && $breeze->isPreparingJump()){
				$distance = $breeze->getPosition()->distance($cow->getPosition());
				self::assertTrue($distance > 4 && $distance <= 20, "leaps only 4-20 blocks from the target, got $distance");
				$prepared = true;
			}
			if($breeze->isLeaping()){
				self::assertTrue($prepared, "gets ready before leaping");
				$targetAtTakeOff ??= $cow->getPosition();
				$leapt = true;
			}
			$landed = $wasLeaping && !$breeze->isLeaping();
		}
		self::assertTrue($leapt && $landed, "leaps towards a target out of shooting range and lands");
		self::assertNotNull($targetAtTakeOff);
		$distance = $breeze->getPosition()->distance($targetAtTakeOff);
		self::assertTrue($distance > 2.5 && $distance < 9.5, "lands 4-8 blocks from the target, got $distance");

		//too close: no leap
		$close = new Breeze($this->loc(-15.5, 1, -15.5));
		$closeCow = $this->dummy(-13.5, -15.5);
		$closeCow->getGoalSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$close->setAttackTarget($closeCow);
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			if($close->isPreparingJump()){
				self::assertGreaterThan(4.0, $close->getPosition()->distance($closeCow->getPosition()), "no leap within 4 blocks");
			}
		}
	}

	public function testNoJumpUnderALowCeiling() : void{
		for($x = -6; $x <= 22; ++$x){
			for($z = -10; $z <= 10; ++$z){
				$this->set($x, 4, $z, VanillaBlocks::STONE());
			}
		}
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(18.5, 0.5);
		$breeze->setAttackTarget($cow);
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			self::assertFalse($breeze->isPreparingJump() || $breeze->isLeaping(), "no leap with only 3 blocks of room above");
		}
	}

	public function testHurtBreezeLeapsSooner() : void{
		$breeze = new Breeze($this->loc(0.5, 1, 0.5));
		$cow = $this->dummy(18.5, 0.5);
		$breeze->setAttackTarget($cow);
		$landing = function() use ($breeze, $cow) : void{
			self::assertNotSame(-1, $this->ticksUntil(fn() => $breeze->isLeaping(), 200), "leaps");
			self::assertNotSame(-1, $this->ticksUntil(fn() => !$breeze->isLeaping(), 100), "lands");
			//out of shooting range again, so that the next thing it does is a leap
			$pos = $breeze->getPosition();
			$cow->teleport($pos->add($pos->x > 0 ? -17 : 17, 0, 0));
		};
		$landing();
		$this->runTicks(1);
		$normal = $this->ticksUntil(fn() => $breeze->isPreparingJump(), 60);
		self::assertGreaterThanOrEqual(BreezeAttackGoal::JUMP_COOLDOWN_TICKS - 2, $normal, "0.5 s between leaps");

		$landing();
		$breeze->attack(new EntityDamageEvent($breeze, EntityDamageEvent::CAUSE_MAGIC, 1));
		$this->runTicks(1);
		$hurt = $this->ticksUntil(fn() => $breeze->isPreparingJump(), 60);
		self::assertNotSame(-1, $hurt);
		self::assertLessThanOrEqual(BreezeAttackGoal::JUMP_COOLDOWN_WHEN_HURT_TICKS + 1, $hurt, "hurt: it leaps away right after landing");
	}
}
