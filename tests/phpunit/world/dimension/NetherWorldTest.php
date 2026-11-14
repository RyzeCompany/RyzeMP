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

namespace pocketmine\world\dimension;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;

class NetherWorldTest extends DimensionWorldTestCase{

	protected function getDimension() : Dimension{
		return Dimension::NETHER;
	}

	public function testBuildRangeAndLight() : void{
		self::assertSame(0, $this->world->getMinY());
		self::assertSame(128, $this->world->getMaxY());
		self::assertFalse($this->world->isInWorld(0, 128, 0));
		self::assertFalse($this->world->isInWorld(0, -1, 0));
		self::assertSame(15, $this->world->computeSkyLightReduction());
	}

	public function testScaledArrivalIntoTheNether() : void{
		$arrival = PortalTravel::getScaledArrival(new Vector3(-801.5, 200, 803.9), Dimension::OVERWORLD, $this->world);
		self::assertEquals(-101.0, $arrival->x); //floor(-100.1875)
		self::assertEquals(100.0, $arrival->z);
		self::assertEquals(118.0, $arrival->y, "kept under the bedrock roof");
	}

	public function testBuilderStaysUnderTheRoof() : void{
		$this->fill(-20, 0, -20, 20, 30, 20, VanillaBlocks::NETHERRACK());
		$this->fill(-20, 123, -20, 20, 127, 20, VanillaBlocks::BEDROCK());
		$shape = NetherPortalBuilder::create($this->world, new Vector3(0, 118, 0));
		self::assertSame(31, $shape->origin->getFloorY());
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->get($shape->origin->getFloorX(), 30, $shape->origin->getFloorZ())->getTypeId());
	}
}
