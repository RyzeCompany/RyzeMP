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
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function abs;

class EndWorldTest extends DimensionWorldTestCase{

	protected function getDimension() : Dimension{
		return Dimension::END;
	}

	public function testBuildRange() : void{
		self::assertSame(0, $this->world->getMinY());
		self::assertSame(256, $this->world->getMaxY());
		self::assertSame(15, $this->world->computeSkyLightReduction());
	}

	public function testNetherPortalsDoNotLight() : void{
		$this->buildFrameX(0, 10, 0, 2, 3);
		self::assertFalse(NetherPortalShape::tryLight($this->world, new Vector3(0, 10, 0)));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 10, 0)->getTypeId());
	}

	public function testSpawnPlatform() : void{
		$this->fill(98, 49, -2, 102, 51, 2, VanillaBlocks::END_STONE());
		EndSpawnPlatform::build($this->world);
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				self::assertSame(BlockTypeIds::OBSIDIAN, $this->get(100 + $dx, 48, $dz)->getTypeId());
				self::assertSame(BlockTypeIds::AIR, $this->get(100 + $dx, 49, $dz)->getTypeId());
				self::assertSame(BlockTypeIds::AIR, $this->get(100 + $dx, 51, $dz)->getTypeId());
			}
		}
		self::assertTrue(EndSpawnPlatform::getArrivalPosition()->equals(new Vector3(100.5, 49, 0.5)));
	}

	private function buildRing(Vector3 $center, bool $eyes, bool $facingCenter = true) : void{
		foreach(EndPortalRing::getFramePositions($center) as $pos){
			$dx = (int) ($pos->x - $center->x);
			$dz = (int) ($pos->z - $center->z);
			$facing = match(true){
				$dx === 2 => Facing::WEST,
				$dx === -2 => Facing::EAST,
				$dz === 2 => Facing::NORTH,
				default => Facing::SOUTH
			};
			$this->world->setBlock($pos, VanillaBlocks::END_PORTAL_FRAME()->setEye($eyes)->setFacing($facingCenter ? $facing : Facing::opposite($facing)));
		}
	}

	public function testRingPositions() : void{
		$positions = EndPortalRing::getFramePositions(new Vector3(0, 10, 0));
		self::assertCount(12, $positions);
		$unique = [];
		foreach($positions as $pos){
			self::assertTrue(
				(abs((int) $pos->x) === 2 && abs((int) $pos->z) <= 1) || (abs((int) $pos->z) === 2 && abs((int) $pos->x) <= 1),
				"unexpected frame position $pos"
			);
			$unique[(string) $pos] = true;
		}
		self::assertCount(12, $unique);
	}

	public function testCompleteRingOpensPortal() : void{
		$center = new Vector3(5, 20, 5);
		$this->buildRing($center, true);
		//any frame of the ring finds it
		self::assertTrue(EndPortalRing::tryActivate($this->world, new Vector3(5, 20, 7)));
		foreach(EndPortalRing::getInteriorPositions($center) as $pos){
			self::assertSame(BlockTypeIds::END_PORTAL, $this->world->getBlock($pos)->getTypeId());
		}
		self::assertFalse(EndPortalRing::tryActivate($this->world, new Vector3(4, 20, 3)), "already open");
	}

	public function testRingFacingOutwardStaysClosed() : void{
		$center = new Vector3(5, 20, 5);
		$this->buildRing($center, true, false);
		self::assertFalse(EndPortalRing::tryActivate($this->world, new Vector3(5, 20, 7)));
		self::assertSame(BlockTypeIds::AIR, $this->get(5, 20, 5)->getTypeId());

		//a single frame facing the wrong way is enough
		$this->buildRing($center, true);
		$this->world->setBlock(new Vector3(7, 20, 6), VanillaBlocks::END_PORTAL_FRAME()->setEye(true)->setFacing(Facing::NORTH));
		self::assertFalse(EndPortalRing::tryActivate($this->world, new Vector3(5, 20, 7)));
	}

	public function testRingMissingAnEyeStaysClosed() : void{
		$center = new Vector3(5, 20, 5);
		$this->buildRing($center, true);
		$this->world->setBlock(new Vector3(7, 20, 6), VanillaBlocks::END_PORTAL_FRAME()->setEye(false));
		self::assertFalse(EndPortalRing::tryActivate($this->world, new Vector3(3, 20, 5)));
		self::assertSame(BlockTypeIds::AIR, $this->get(5, 20, 5)->getTypeId());
	}
}
