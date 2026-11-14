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

namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\entity\projectile\LargeFireball;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\Trident;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\item\ProjectileItem;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use function deg2rad;
use function sin;
use function sqrt;

/**
 * Projectiles slowed down by water (Bedrock liquid_inertia), items and orbs floating up, currents of water and lava,
 * ender pearl and potion throw values.
 */
final class ProjectilePhysicsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Fills the box with the given liquid, without block updates (the liquid stays as placed).
	 */
	private function fill(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, Block $block) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	private static function liquidInertia(Projectile $projectile) : float{
		return (fn() => $this->getLiquidInertia())->call($projectile);
	}

	private static function checkBlockIntersections(Entity $entity) : void{
		(fn() => $this->checkBlockIntersections())->call($entity);
	}

	public function testArrowStopsWithinFewBlocksUnderwater() : void{
		$this->fill(-2, 1, -2, 30, 4, 2, VanillaBlocks::WATER());
		$arrow = new Arrow($this->loc(0.5, 3, 0.5), null, false);
		$arrow->setMotion(new Vector3(3, 0, 0));
		$this->runTicks(20);
		self::assertLessThan(8.0, $arrow->getPosition()->x, "a full-power arrow shot underwater stops after a few blocks");

		$airArrow = new Arrow($this->loc(0.5, 12, 10.5), null, false);
		$airArrow->setMotion(new Vector3(3, 0, 0));
		$this->runTicks(3);
		self::assertGreaterThan(8.0, $airArrow->getPosition()->x, "in the air it keeps going");
	}

	public function testSnowballSlowsButPearlDoesNot() : void{
		$this->fill(-2, 1, -2, 30, 6, 4, VanillaBlocks::WATER());
		$snowball = new Snowball($this->loc(0.5, 3, 0.5), null);
		$snowball->setMotion(new Vector3(1.5, 0, 0));
		$pearl = new EnderPearl($this->loc(0.5, 3, 2.5), null);
		$pearl->setMotion(new Vector3(1.5, 0, 0));
		$this->runTicks(4);
		self::assertLessThan(0.5, $snowball->getMotion()->x, "liquid_inertia 0.6");
		self::assertEqualsWithDelta(1.5, $pearl->getMotion()->x, 0.0001, "ender pearls have liquid_inertia 1");
	}

	public function testLiquidInertiaValues() : void{
		$at = $this->loc(0.5, 10, 0.5);
		self::assertSame(0.6, self::liquidInertia(new Snowball($at, null)));
		self::assertSame(0.99, self::liquidInertia(new Trident($at, VanillaItems::TRIDENT(), null)));
		self::assertSame(1.0, self::liquidInertia(new EnderPearl($at, null)));
		self::assertSame(1.0, self::liquidInertia(new LargeFireball($at, null, new Vector3(1, 0, 0))));
		self::assertSame(1.0, self::liquidInertia(new WindCharge($at, null)));
		$hook = new FishingHook($at, null);
		self::assertEqualsWithDelta(1.0 - (fn() => $this->drag)->call($hook), self::liquidInertia($hook), 1e-9, "the fishing hook floats on its own: no extra damping in water");
	}

	public function testFireballAndWindChargeKeepTheirSpeedInWater() : void{
		$this->fill(-2, 1, -2, 40, 6, 6, VanillaBlocks::WATER());
		$fireball = new LargeFireball($this->loc(0.5, 3, 0.5), null, new Vector3(1, 0, 0));
		$fireball->setMotion(new Vector3(LargeFireball::SPEED, 0, 0));
		$charge = new WindCharge($this->loc(0.5, 3, 4.5), null);
		$charge->setMotion(new Vector3(1.2, 0, 0));
		$this->runTicks(3);
		self::assertFalse($fireball->isFlaggedForDespawn());
		self::assertEqualsWithDelta(LargeFireball::SPEED, $fireball->getMotion()->length(), 0.0001);
		self::assertEqualsWithDelta(1.2, $charge->getMotion()->length(), 0.0001);
	}

	public function testEnderPearlHasNoDragAndLowGravity() : void{
		$pearl = new EnderPearl($this->loc(0.5, 30, 0.5), null);
		$pearl->setMotion(new Vector3(1.5, 0, 0));
		$this->runTicks(1);
		self::assertEqualsWithDelta(1.5, $pearl->getMotion()->x, 1e-9, "inertia 1");
		self::assertEqualsWithDelta(-0.025, $pearl->getMotion()->y, 1e-9, "gravity 0.025");
	}

	public function testItemFloatsUpToTheSurfaceAndRests() : void{
		$this->fill(-3, 1, -3, 3, 5, 3, VanillaBlocks::WATER());
		$item = new ItemEntity($this->loc(0.5, 1.0, 0.5), VanillaItems::STICK());
		$this->runTicks(400);
		self::assertGreaterThan(5.7, $item->getPosition()->y, "rose from the bottom to the surface (5 + 8/9)");
		self::assertLessThan(6.0, $item->getPosition()->y);
		self::assertFalse($item->hasMovementUpdate(), "and rests there instead of bobbing every tick");

		$y = $item->getPosition()->y;
		$this->runTicks(20);
		self::assertSame($y, $item->getPosition()->y);
	}

	public function testItemThrownDownToTheBottomRisesAgain() : void{
		$this->fill(-3, 1, -3, 3, 5, 3, VanillaBlocks::WATER());
		$item = new ItemEntity($this->loc(0.5, 4.0, 0.5), VanillaItems::STICK());
		$item->setMotion(new Vector3(0, -2, 0));
		$this->runTicks(3);
		self::assertLessThan(1.1, $item->getPosition()->y, "hit the bottom of the pool");
		$this->runTicks(400);
		self::assertGreaterThan(5.7, $item->getPosition()->y, "and floated up from there");
	}

	public function testRestingItemRisesWhenWaterIsAddedAbove() : void{
		$this->fill(-3, 1, -3, 3, 1, 3, VanillaBlocks::WATER());
		$item = new ItemEntity($this->loc(0.5, 1.0, 0.5), VanillaItems::STICK());
		$this->runTicks(200);
		self::assertFalse($item->hasMovementUpdate(), "rests at the surface of the one block deep pool");
		self::assertLessThan(2.0, $item->getPosition()->y);

		//the water above isn't remembered as missing: a block change nearby looks for it again
		$this->fill(-3, 2, -3, 3, 3, 3, VanillaBlocks::WATER());
		$item->onNearbyBlockChange();
		$this->runTicks(300);
		self::assertGreaterThan(3.7, $item->getPosition()->y, "rose to the new surface (3 + 8/9)");
	}

	public function testOrbFloatsUp() : void{
		$this->fill(-3, 1, -3, 3, 5, 3, VanillaBlocks::WATER());
		$orb = new ExperienceOrb($this->loc(0.5, 1.0, 0.5), 3);
		$this->runTicks(400);
		self::assertGreaterThan(5.5, $orb->getPosition()->y);
		self::assertLessThan(6.0, $orb->getPosition()->y);
	}

	public function testFireProofItemsFloatInLava() : void{
		$this->fill(-3, 1, -3, 3, 4, 3, VanillaBlocks::LAVA());
		$netherite = new ItemEntity($this->loc(0.5, 1.0, 0.5), VanillaItems::NETHERITE_INGOT());
		$stick = new ItemEntity($this->loc(-1.5, 2.0, 0.5), VanillaItems::STICK());
		$this->runTicks(10);
		self::assertTrue($stick->isFlaggedForDespawn() || $stick->getPosition()->y < 2.0, "other items sink (and burn)");
		$this->runTicks(390);
		self::assertFalse($netherite->isFlaggedForDespawn());
		self::assertGreaterThan(4.5, $netherite->getPosition()->y, "netherite floats up to the surface of lava");
	}

	/**
	 * Returns the horizontal push given to a netherite item in a stream of the liquid flowing east.
	 */
	public static function pushInStream(MobTestCase $test, \pocketmine\world\World $world, Block $liquidFlowing, Block $liquidLower) : float{
		$stone = VanillaBlocks::STONE();
		for($x = -1; $x <= 2; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$world->setBlockAt($x, 1, $z, $stone, false);
			}
		}
		$world->setBlockAt(0, 1, 0, $liquidFlowing, false);
		$world->setBlockAt(1, 1, 0, $liquidLower, false);
		$item = new ItemEntity(new Location(0.5, 1.0, 0.5, $world, 0, 0), VanillaItems::NETHERITE_INGOT());
		$item->setMotion(Vector3::zero());
		self::checkBlockIntersections($item);
		$push = $item->getMotion()->x;
		$item->flagForDespawn();
		return $push;
	}

	public function testLavaPushesLessThanWater() : void{
		$water = self::pushInStream($this, $this->world, VanillaBlocks::WATER()->setDecay(2), VanillaBlocks::WATER()->setDecay(4));
		self::assertEqualsWithDelta(0.014, $water, 1e-9);
		$lava = self::pushInStream($this, $this->world, VanillaBlocks::LAVA()->setDecay(2), VanillaBlocks::LAVA()->setDecay(4));
		self::assertEqualsWithDelta(0.0023333333333333335, $lava, 1e-9, "Overworld lava pushes 6 times less");
	}

	public function testThrowAngleOffset() : void{
		$direction = new \ReflectionMethod(ProjectileItem::class, "getThrowDirection");
		$flat = $direction->invoke(null, 0.0, 0.0, -20.0);
		self::assertInstanceOf(Vector3::class, $flat);
		//vanilla shootFromRotation(): (0, sin 20, 1) normalized, 18.9 degrees up
		self::assertEqualsWithDelta(sin(deg2rad(20)) / sqrt(1 + sin(deg2rad(20)) ** 2), $flat->y, 1e-9, "thrown about 18.9 degrees above a flat look direction");
		self::assertGreaterThan(0, $flat->z);
		self::assertEqualsWithDelta(1.0, $flat->length(), 1e-9);

		$up = $direction->invoke(null, 0.0, -90.0, -20.0);
		self::assertInstanceOf(Vector3::class, $up);
		self::assertEqualsWithDelta(1.0, $up->y, 1e-9, "looking straight up throws straight up");
		self::assertGreaterThan(-1e-9, $up->z, "never behind the thrower");
		$down = $direction->invoke(null, 0.0, 90.0, -20.0);
		self::assertInstanceOf(Vector3::class, $down);
		self::assertEqualsWithDelta(-1.0, $down->y, 1e-9, "looking straight down throws at the thrower's feet");
		self::assertEqualsWithDelta(0.0, $down->z, 1e-9);
		$steep = $direction->invoke(null, 0.0, -80.0, -20.0);
		self::assertInstanceOf(Vector3::class, $steep);
		self::assertGreaterThan(0, $steep->z, "looking nearly straight up still throws forward");
		$same = $direction->invoke(null, 90.0, 30.0, 0.0);
		self::assertInstanceOf(Vector3::class, $same);
		self::assertEqualsWithDelta(-1.0 * sin(deg2rad(30)), $same->y, 1e-9);
		self::assertLessThan(0, $same->x, "yaw 90 looks west");

		$bottle = VanillaItems::EXPERIENCE_BOTTLE();
		self::assertSame(0.5, $bottle->getThrowForce(), "Bedrock xp_bottle.json power");
		self::assertSame(-20.0, (fn() => $this->getThrowAngleOffset())->call($bottle));
		self::assertSame(-20.0, (fn() => $this->getThrowAngleOffset())->call(VanillaItems::SPLASH_POTION()));
		self::assertSame(0.0, (fn() => $this->getThrowAngleOffset())->call(VanillaItems::SNOWBALL()));
	}

	public function testItemStillFallsInAir() : void{
		$item = new ItemEntity($this->loc(0.5, 10.0, 0.5), VanillaItems::STICK());
		$this->runTicks(5);
		self::assertLessThan(10.0, $item->getPosition()->y);
	}
}
