<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\GroundPathFinder;

final class GroundPathFinderHazardTest extends TestCase{
	public function testUnsafeBlocksAreNeverWalkingWaypoints() : void{
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::LAVA()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::WATER()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::FIRE()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::SOUL_FIRE()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::CACTUS()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::MAGMA()));
		self::assertTrue(GroundPathFinder::isHazardous(VanillaBlocks::SWEET_BERRY_BUSH()));
		self::assertFalse(GroundPathFinder::isHazardous(VanillaBlocks::DIRT()));
		self::assertFalse(GroundPathFinder::isHazardous(VanillaBlocks::STONE()));
	}
}
