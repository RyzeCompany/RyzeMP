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

namespace pocketmine\world;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;

class WindBurstTest extends TestCase{

	public function testEntityAtCenterIsLaunchedUp() : void{
		$impulse = WindBurst::calculateImpulse(new Vector3(10, 64, 10), new Vector3(10, 64, 10), 1.62, 1.2, 1.22);
		self::assertNotNull($impulse);
		self::assertEqualsWithDelta(0.0, $impulse->x, 0.0001);
		self::assertEqualsWithDelta(1.22, $impulse->y, 0.0001);
		self::assertEqualsWithDelta(0.0, $impulse->z, 0.0001);
	}

	public function testStrengthFallsOffWithDistance() : void{
		$center = new Vector3(0, 64, 0);
		//feet 1.2 blocks away with a 1.2 radius: half strength, pointing from the center to the eyes
		$impulse = WindBurst::calculateImpulse($center, new Vector3(1.2, 64, 0), 0.0, 1.2, 1.0);
		self::assertNotNull($impulse);
		self::assertEqualsWithDelta(0.5, $impulse->length(), 0.0001);
		self::assertEqualsWithDelta(0.5, $impulse->x, 0.0001);

		$eyes = WindBurst::calculateImpulse($center, new Vector3(1.2, 64, 0), 1.2, 1.2, 1.0);
		self::assertNotNull($eyes);
		self::assertEqualsWithDelta(0.5, $eyes->length(), 0.0001);
		self::assertEqualsWithDelta($eyes->x, $eyes->y, 0.0001); //45 degrees up
	}

	public function testOutOfReach() : void{
		self::assertNull(WindBurst::calculateImpulse(new Vector3(0, 0, 0), new Vector3(2.5, 0, 0), 1.62, 1.2, 1.22));
		self::assertNotNull(WindBurst::calculateImpulse(new Vector3(0, 0, 0), new Vector3(2.4, 0, 0), 1.62, 1.2, 1.22));
	}
}
