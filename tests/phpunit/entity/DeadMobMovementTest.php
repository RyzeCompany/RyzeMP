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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\monster\Husk;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

/**
 * The body of a mob keeps moving during its death animation (BDS 1.26.60: a husk killed by a sword slides about 2
 * blocks back, like after the earlier hits).
 */
final class DeadMobMovementTest extends MobTestCase{

	private function hit(Living $victim, Living $damager, float $damage) : void{
		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($victim, 0);
		$victim->attack(new EntityDamageByEntityEvent($damager, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage));
	}

	/**
	 * @return float how far the husk moved along +Z in 15 ticks after a hit of the given damage from the north
	 */
	private function pushAfterHit(float $damage) : float{
		$attacker = new Zombie(new Location(0.5, 1, -1.5, $this->world, 0, 0));
		$attacker->spawnToAll();
		$husk = new Husk(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$husk->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 1000, 255, false)); //only the knockback moves it
		$husk->spawnToAll();
		$this->runTicks(2);
		$startZ = $husk->getLocation()->z;

		$this->hit($husk, $attacker, $damage);
		$this->runTicks(15);
		$moved = $husk->getLocation()->z - $startZ;
		$attacker->flagForDespawn();
		$husk->flagForDespawn();
		$this->runTicks(1);
		return $moved;
	}

	public function testKillingBlowKnocksTheBodyBack() : void{
		$alive = $this->pushAfterHit(7);
		$dead = $this->pushAfterHit(40);
		self::assertGreaterThan(0.5, $alive, "a hit knocks the husk back");
		self::assertGreaterThan(0.5, $dead, "the killing blow knocks the body back too");
		self::assertEqualsWithDelta($alive, $dead, 0.2, "the body slides like a living husk");
	}

	public function testBodyFallsDuringTheDeathAnimation() : void{
		$husk = new Husk(new Location(0.5, 4, 0.5, $this->world, 0, 0));
		$husk->spawnToAll();
		$husk->kill();
		self::assertFalse($husk->isAlive());
		$this->runTicks(15);
		self::assertFalse($husk->isClosed(), "still in its death animation");
		self::assertEqualsWithDelta(1.0, $husk->getLocation()->y, 0.01, "the body fell to the floor");
	}
}
