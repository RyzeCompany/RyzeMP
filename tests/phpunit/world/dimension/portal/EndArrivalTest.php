<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\World;

final class EndArrivalTest extends TestCase{

	/** @param array<string, Block> $blocks */
	private function world(array &$blocks) : World{
		$world = $this->createMock(World::class);
		$world->method("getMinY")->willReturn(-64);
		$world->method("getMaxY")->willReturn(320);
		$world->method("getBlockAt")->willReturnCallback(static function(int $x, int $y, int $z) use (&$blocks) : Block{
			return clone ($blocks["$x:$y:$z"] ?? VanillaBlocks::AIR());
		});
		return $world;
	}

	public function testExistingSafeSpawnIsUsedWithoutChangingBlocks() : void{
		$blocks = ["20:69:-10" => VanillaBlocks::END_STONE()];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("setBlockAt");
		$spawn = EndArrival::findOrCreate($world, new Vector3(20, 70, -10), true);
		self::assertEquals(new Vector3(20.5, 70, -9.5), $spawn);
	}

	public function testNearbySafeFloorIsPreferredOverCreatingPlatform() : void{
		$blocks = ["22:71:-10" => VanillaBlocks::END_STONE()];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("setBlockAt");
		self::assertEquals(new Vector3(22.5, 72, -9.5), EndArrival::findOrCreate($world, new Vector3(20, 70, -10), true));
	}

	public function testEmptyVoidGetsOnlyAnObsidianFloor() : void{
		$blocks = [];
		$world = $this->world($blocks);
		$world->expects(self::exactly(25))->method("setBlockAt")->willReturnCallback(
			static function(int $x, int $y, int $z, Block $block) use (&$blocks) : void{
				self::assertSame(48, $y);
				self::assertSame(BlockTypeIds::OBSIDIAN, $block->getTypeId());
				$blocks["$x:$y:$z"] = clone $block;
			}
		);
		self::assertEquals(new Vector3(100.5, 49, 0.5), EndArrival::findOrCreate($world, new Vector3(100, 49, 0), true));
		self::assertCount(25, $blocks);
	}

	public function testObstructedVolumeDoesNotClearOrPartiallyBuild() : void{
		$blocks = ["102:50:2" => VanillaBlocks::MAGMA()];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("setBlockAt");
		self::assertNull(EndArrival::findOrCreate($world, new Vector3(100, 49, 0), true));
		self::assertCount(1, $blocks);
	}

	public function testPlatformCreationCanBeDisabled() : void{
		$blocks = [];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("setBlockAt");
		self::assertNull(EndArrival::findOrCreate($world, new Vector3(100, 49, 0), false));
	}

	public function testMagmaIsNotAcceptedAsSafeFloor() : void{
		$blocks = ["100:48:0" => VanillaBlocks::MAGMA()];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("setBlockAt");
		self::assertNull(EndArrival::findOrCreate($world, new Vector3(100, 49, 0), false));
	}

	public function testEndHeightLimitIsEnforcedEvenWithOverworldProviderBounds() : void{
		$blocks = [];
		$world = $this->world($blocks);
		$world->expects(self::never())->method("getBlockAt");
		$world->expects(self::never())->method("setBlockAt");
		self::assertNull(EndArrival::findOrCreate($world, new Vector3(100, 300, 0), true));
	}
}
