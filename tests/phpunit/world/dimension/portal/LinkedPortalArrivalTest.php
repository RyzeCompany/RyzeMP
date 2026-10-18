<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\Position;
use pocketmine\world\World;

final class LinkedPortalArrivalTest extends TestCase{

	/** @param array<string, Block> $blocks @return array{Entity, World} */
	private function fixture(array $blocks) : array{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getMinY")->willReturn(-64);
		$world->method("getMaxY")->willReturn(320);
		$world->method("getBlockAt")->willReturnCallback(static fn(int $x, int $y, int $z) : Block => clone ($blocks["$x:$y:$z"] ?? VanillaBlocks::AIR()));
		$world->expects(self::never())->method("setBlockAt");
		$entity = $this->createMock(Entity::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($entity, true);
		$entity->method("getPosition")->willReturn(new Position(10.5, 78, -89.5, $world));
		$entity->method("getBoundingBox")->willReturn(new AxisAlignedBB(10.2, 78, -89.8, 10.8, 79.8, -89.2));
		return [$entity, $world];
	}

	public function testExactArrivalWithOrientationDoesNotUseScaleOrModifyTerrain() : void{
		[$entity, $world] = $this->fixture(["0:45:0" => VanillaBlocks::OBSIDIAN()]);
		self::assertTrue(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0.5, 46, 0.5, 90, 20), DimensionIds::NETHER));
	}

	public function testIntegerCentreRequiresSupportOnBothSidesOfChunkBoundary() : void{
		[$entity, $world] = $this->fixture(["0:45:0" => VanillaBlocks::OBSIDIAN()]);
		self::assertFalse(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0, 46, 0), DimensionIds::NETHER));
	}

	public function testFullFootprintAtIntegerCentreIsSafe() : void{
		$blocks = [];
		foreach([-1, 0] as $x){
			foreach([-1, 0] as $z){
				$blocks["$x:45:$z"] = VanillaBlocks::OBSIDIAN();
			}
		}
		[$entity, $world] = $this->fixture($blocks);
		self::assertTrue(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0, 46, 0), DimensionIds::NETHER));
	}

	public function testHeadObstructionIsRejected() : void{
		[$entity, $world] = $this->fixture(["0:45:0" => VanillaBlocks::OBSIDIAN(), "0:47:0" => VanillaBlocks::OBSIDIAN()]);
		self::assertFalse(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0.5, 46, 0.5), DimensionIds::NETHER));
	}

	public function testMagmaSupportIsRejected() : void{
		[$entity, $world] = $this->fixture(["0:45:0" => VanillaBlocks::MAGMA()]);
		self::assertFalse(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0.5, 46, 0.5), DimensionIds::NETHER));
	}

	public function testNetherHeightIsCheckedAgainstDimensionNotProvider() : void{
		[$entity, $world] = $this->fixture([]);
		self::assertFalse(LinkedPortalArrival::isWithinBounds($entity, $world, new PortalDestination("world_nether", 0.5, 127, 0.5), DimensionIds::NETHER));
	}

	public function testMissingFloorIsRejected() : void{
		[$entity, $world] = $this->fixture([]);
		self::assertFalse(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0.5, 46, 0.5), DimensionIds::NETHER));
	}

	public function testPortalBlocksAreAllowedAroundEntityBody() : void{
		[$entity, $world] = $this->fixture(["0:45:0" => VanillaBlocks::OBSIDIAN(), "0:46:0" => VanillaBlocks::NETHER_PORTAL(), "0:47:0" => VanillaBlocks::NETHER_PORTAL()]);
		self::assertTrue(LinkedPortalArrival::isSafe($entity, $world, new PortalDestination("world_nether", 0.5, 46, 0.5), DimensionIds::NETHER));
	}
}
