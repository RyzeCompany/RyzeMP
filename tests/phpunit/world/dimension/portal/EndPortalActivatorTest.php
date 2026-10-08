<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use LogicException;
use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\world\dimension\portal\block\EndPortal;
use pocketmine\world\World;

final class EndPortalActivatorTest extends TestCase{

	public function testCompleteRingActivatesThreeByThreePortal() : void{
		try{
			PortalContent::endPortal();
		}catch(LogicException){
			PortalContent::registerBlock(BlockTypeIds::newId());
		}

		$y = 64;
		$blocks = [];
		for($i = -1; $i <= 1; ++$i){
			$blocks["-2:$y:$i"] = VanillaBlocks::END_PORTAL_FRAME()->setFacing(Facing::EAST)->setEye(true);
			$blocks["2:$y:$i"] = VanillaBlocks::END_PORTAL_FRAME()->setFacing(Facing::WEST)->setEye(true);
			$blocks["$i:$y:-2"] = VanillaBlocks::END_PORTAL_FRAME()->setFacing(Facing::SOUTH)->setEye(true);
			$blocks["$i:$y:2"] = VanillaBlocks::END_PORTAL_FRAME()->setFacing(Facing::NORTH)->setEye(true);
		}

		$world = $this->createMock(World::class);
		$world->method("getBlockAt")->willReturnCallback(
			static function(int $x, int $yy, int $z) use (&$blocks) : Block{
				return clone ($blocks["$x:$yy:$z"] ?? VanillaBlocks::AIR());
			}
		);

		$placed = [];
		$world->expects(self::exactly(9))
			->method("setBlockAt")
			->willReturnCallback(
				static function(int $x, int $yy, int $z, Block $block) use (&$placed) : void{
					$placed["$x:$yy:$z"] = clone $block;
				}
			);
		$world->expects(self::once())->method("addSound");

		self::assertTrue(EndPortalActivator::tryActivate($world, -2, $y, 0));
		self::assertCount(9, $placed);
		foreach($placed as $block){
			self::assertInstanceOf(EndPortal::class, $block);
		}
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				self::assertArrayHasKey("$x:$y:$z", $placed);
			}
		}
	}
}
