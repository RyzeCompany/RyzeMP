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

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BiomeIds;

final class BiomeHumidityTest extends TestCase{

	public function testHumidBiomes() : void{
		//downfall above 0.85
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::JUNGLE));
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::BAMBOO_JUNGLE));
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::SWAMPLAND));
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::MANGROVE_SWAMP));
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::MUSHROOM_ISLAND));
		//minecraft:humidity is_humid in the behaviour pack (downfall 0.8)
		self::assertTrue(BiomeClimate::isHumid(BiomeIds::ROOFED_FOREST));
	}

	public function testDryBiomes() : void{
		self::assertFalse(BiomeClimate::isHumid(BiomeIds::PLAINS));
		self::assertFalse(BiomeClimate::isHumid(BiomeIds::FOREST));
		self::assertFalse(BiomeClimate::isHumid(BiomeIds::DESERT));
		self::assertFalse(BiomeClimate::isHumid(BiomeIds::HELL));
	}
}
