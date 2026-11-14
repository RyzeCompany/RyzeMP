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

namespace pocketmine\entity\object;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;

final class EyeOfEnderSignalTest extends MobTestCase{

	public function testFliesTowardsTheStrongholdThenDisappears() : void{
		$eye = new EyeOfEnderSignal(new Location(0.5, 5, 0.5, $this->world, 0, 0), new Vector3(1000, 30, 0));
		self::assertEqualsWithDelta(12.5, $eye->getTarget()->x, 0.001, "at most 12 blocks at a time");
		self::assertEqualsWithDelta(13, $eye->getTarget()->y, 0.001, "8 blocks up");
		$eye->spawnToAll();
		$this->runTicks(40);
		$pos = $eye->getPosition();
		self::assertGreaterThan(0.5, $pos->x, "moved towards +X");
		self::assertGreaterThan(5, $pos->y, "rising");
		self::assertEqualsWithDelta(0.5, $pos->z, 0.01);
		$this->runTicks(EyeOfEnderSignal::LIFETIME);
		self::assertTrue($eye->isClosed() || $eye->isFlaggedForDespawn());
	}

	public function testNearStrongholdGoesStraightToIt() : void{
		$eye = new EyeOfEnderSignal(new Location(0.5, 5, 0.5, $this->world, 0, 0), new Vector3(5, -20, 3));
		self::assertTrue($eye->getTarget()->equals(new Vector3(5, -20, 3)));
		$eye->close();
	}
}
