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

namespace pocketmine\entity\effect;

use pocketmine\entity\Attribute;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Sprinting, Speed and Slowness are multipliers of the base movement speed, worked out again each time: Slowness 255
 * running out used to divide by a negative factor and crash the server.
 */
class MovementSpeedModifiersTest extends RedstoneTestCase{

	private function spawnZombie() : Zombie{
		$this->floor(-3, -3, 3, 3);
		$zombie = new Zombie(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$zombie->spawnToAll();
		return $zombie;
	}

	public function testSlowness255ExpiresBackToTheBaseSpeed() : void{
		$zombie = $this->spawnZombie();
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 5, 255));
		self::assertSame(0.0, $zombie->getMovementSpeed(), "Slowness 7 and up stops the mob");
		$this->runTicks(10);
		self::assertFalse($zombie->getEffects()->has(VanillaEffects::SLOWNESS()));
		self::assertSame(0.23, $zombie->getMovementSpeed());
	}

	public function testBaseSpeedChangedWhileSlowed() : void{
		$zombie = $this->spawnZombie();
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 5, 255));
		$zombie->setBaby(true);
		self::assertSame(0.0, $zombie->getMovementSpeed(), "still slowed");
		self::assertSame(0.35, $zombie->getBaseMovementSpeed());
		$this->runTicks(10);
		self::assertSame(0.35, $zombie->getMovementSpeed());

		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 600, 1));
		$zombie->setBaby(false);
		self::assertEqualsWithDelta(0.23 * 0.7, $zombie->getMovementSpeed(), 1e-9, "the new base speed is slowed too");
	}

	public function testSprintSpeedAndSlownessInAnyOrder() : void{
		$zombie = $this->spawnZombie();
		$effects = $zombie->getEffects();
		$zombie->setSprinting(true);
		$effects->add(new EffectInstance(VanillaEffects::SPEED(), 600, 1));
		$effects->add(new EffectInstance(VanillaEffects::SLOWNESS(), 600, 0));
		self::assertEqualsWithDelta(0.23 * 1.3 * 1.4 * 0.85, $zombie->getMovementSpeed(), 1e-9);

		$effects->add(new EffectInstance(VanillaEffects::SLOWNESS(), 600, 255));
		self::assertSame(0.0, $zombie->getMovementSpeed());
		$zombie->setSprinting(false);
		$effects->remove(VanillaEffects::SPEED());
		self::assertSame(0.0, $zombie->getMovementSpeed());
		$effects->remove(VanillaEffects::SLOWNESS());
		self::assertSame(0.23, $zombie->getMovementSpeed(), "exactly the base speed, no drift");

		for($i = 0; $i < 50; ++$i){
			$zombie->setSprinting(true);
			$effects->add(new EffectInstance(VanillaEffects::SPEED(), 600, $i % 3));
			$zombie->setSprinting(false);
			$effects->remove(VanillaEffects::SPEED());
		}
		self::assertSame(0.23, $zombie->getMovementSpeed());
	}

	public function testAttributeSetFromOutsideBecomesTheBase() : void{
		$zombie = $this->spawnZombie();
		$zombie->setSprinting(true);
		$zombie->getAttributeMap()->get(Attribute::MOVEMENT_SPEED)?->resetToDefault();
		$zombie->setSprinting(false);
		self::assertSame(0.1, $zombie->getMovementSpeed());
	}

	public function testInvalidSpeedStillThrows() : void{
		$zombie = $this->spawnZombie();
		$zombie->setMovementSpeed(-1.0, true);
		self::assertSame(0.0, $zombie->getMovementSpeed());
		$this->expectException(\InvalidArgumentException::class);
		$zombie->setMovementSpeed(-1.0);
	}
}
