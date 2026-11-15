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

namespace pocketmine\world\raid;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;

final class VillageTest extends TestCase{

	public function testBedAndVillagerMakeAVillage() : void{
		$villages = Village::findAll([[PoiType::BED, new Vector3(10, 64, 10)]], [new Vector3(12, 64, 12)]);
		self::assertCount(1, $villages);
		self::assertSame(1, $villages[0]->getVillagerCount());
		self::assertTrue($villages[0]->getCenter()->equals(new Vector3(10.5, 64, 10.5)));
	}

	public function testBedAndBellMakeAVillage() : void{
		$villages = Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)], [PoiType::BELL, new Vector3(5, 64, 0)]], []);
		self::assertCount(1, $villages);
		self::assertCount(1, $villages[0]->getBells());
		self::assertSame(0, $villages[0]->getVillagerCount());
	}

	public function testBedAloneIsNotAVillage() : void{
		self::assertCount(0, Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)]], []));
	}

	public function testNoVillageWithoutBeds() : void{
		self::assertCount(0, Village::findAll([[PoiType::BELL, new Vector3(0, 64, 0)], [PoiType::JOB_SITE, new Vector3(2, 64, 0)]], [new Vector3(1, 64, 1)]));
	}

	public function testVillagerOutsideTheBoundaryDoesNotCount() : void{
		//boundary: bed ±32 horizontally
		self::assertCount(0, Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)]], [new Vector3(40, 64, 0)]));
		self::assertCount(1, Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)]], [new Vector3(30, 64, 0)]));
		//±12 vertically
		self::assertCount(0, Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)]], [new Vector3(0, 80, 0)]));
	}

	public function testBoundaryGrowsWithPois() : void{
		$villages = Village::findAll([
			[PoiType::BED, new Vector3(0, 64, 0)],
			[PoiType::JOB_SITE, new Vector3(50, 64, 0)], //within the expansion range: joins and grows the boundary
		], [new Vector3(75, 64, 0)]);
		self::assertCount(1, $villages);
		$bounds = $villages[0]->getBounds();
		self::assertEqualsWithDelta(-32.0, $bounds->minX, 0.001);
		self::assertEqualsWithDelta(83.0, $bounds->maxX, 0.001);
		self::assertCount(1, $villages[0]->getJobSites());
		self::assertSame(1, $villages[0]->getVillagerCount());
		self::assertEqualsWithDelta(25.5, $villages[0]->getCenter()->x, 0.001);
	}

	public function testDistantBedsMakeTwoVillages() : void{
		$villages = Village::findAll([
			[PoiType::BED, new Vector3(0, 64, 0)],
			[PoiType::BELL, new Vector3(1, 64, 0)],
			[PoiType::BED, new Vector3(200, 64, 0)],
			[PoiType::BELL, new Vector3(201, 64, 0)],
		], []);
		self::assertCount(2, $villages);
	}

	public function testChainedPoisJoinOneVillage() : void{
		//a job site far from the bed joins once a closer one has grown the boundary
		$villages = Village::findAll([
			[PoiType::JOB_SITE, new Vector3(120, 64, 0)],
			[PoiType::BED, new Vector3(0, 64, 0)],
			[PoiType::JOB_SITE, new Vector3(60, 64, 0)],
			[PoiType::BELL, new Vector3(2, 64, 0)],
		], []);
		self::assertCount(1, $villages);
		self::assertCount(2, $villages[0]->getJobSites());
	}

	public function testOmenRange() : void{
		$village = Village::findAll([[PoiType::BED, new Vector3(0, 64, 0)], [PoiType::BELL, new Vector3(0, 64, 2)]], [])[0];
		self::assertTrue($village->isInOmenRange(new Vector3(20, 65, 20)));
		self::assertTrue($village->isInOmenRange(new Vector3(-31, 70, 0)));
		self::assertFalse($village->isInOmenRange(new Vector3(40, 64, 0)));
		self::assertFalse($village->isInOmenRange(new Vector3(0, 80, 0)));
		//still inside the boundary, but not close enough for the omen
		self::assertTrue($village->contains(new Vector3(0, 75, 0)));
	}
}
