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

namespace pocketmine\world\gamerule;

use pocketmine\entity\ai\MobTestCase;

/**
 * mobs.disabled-worlds: the world of the test ("redstone_test") is listed, so its mobs are off.
 */
final class DisabledMobWorldTest extends MobTestCase{

	protected function getPocketmineYml() : array{
		return [
			"redstone" => ["enabled" => false],
			"mobs" => ["enabled" => true, "disabled-worlds" => ["redstone_test"], "spawning" => ["enabled" => true]]
		];
	}

	public function testMobsAreOff() : void{
		self::assertFalse($this->world->areMobsEnabled());
		self::assertNull($this->world->getNaturalMobSpawner(), "no natural spawning in the world");
		self::assertNull($this->world->getPatrolSpawner());
	}
}
