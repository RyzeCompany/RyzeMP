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

use PHPUnit\Framework\TestCase;
use pocketmine\entity\ai\control\MoveControl;

/**
 * The ground speed of walking mobs matches the steady state of Java 26.3's LivingEntity.travel() (bytecode checked):
 * Mob::setSpeed(s * m) also sets the forward input to s * m, applyInput() scales it by 0.98,
 * getFrictionInfluencedSpeed() returns the speed unchanged on blocks with friction <= 0.6, moveRelative() adds
 * speed * input, the entity moves by its velocity, then the velocity keeps 0.6 * 0.91 of itself.
 */
final class MobGroundSpeedTest extends TestCase{

	public function testFactorMatchesJavaTravelSteadyState() : void{
		//zombie walking (0.23 x 1.0), cow panicking (0.2 x 1.25), a fast chase (0.25 x 2.0)
		foreach([0.23, 0.25, 0.5] as $speed){
			$velocity = 0.0;
			$step = 0.0;
			for($tick = 0; $tick < 200; ++$tick){
				$velocity += $speed * ($speed * 0.98);
				$step = $velocity;
				$velocity *= 0.6 * 0.91;
			}
			self::assertEqualsWithDelta($step, $speed * $speed * MoveControl::GROUND_SPEED_FACTOR, 1e-9, "speed $speed");
		}
	}

	public function testZombieWalksAtVanillaSpeed() : void{
		//0.23^2 * 2.1586 = 0.1142 blocks per tick, about 2.3 blocks per second
		self::assertEqualsWithDelta(0.1142, 0.23 * 0.23 * MoveControl::GROUND_SPEED_FACTOR, 1e-4);
	}
}
