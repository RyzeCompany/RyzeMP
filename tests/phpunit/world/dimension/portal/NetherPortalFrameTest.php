<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\NetherPortal;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\world\World;

final class NetherPortalFrameTest extends TestCase{

	public function testDetectsAndLightsMinimumXAxisFrame() : void{
		$blocks = [];
		for($x = 0; $x <= 3; ++$x){
			$blocks["$x:0:0"] = VanillaBlocks::OBSIDIAN();
			$blocks["$x:4:0"] = VanillaBlocks::OBSIDIAN();
		}
		for($y = 1; $y <= 3; ++$y){
			$blocks["0:$y:0"] = VanillaBlocks::OBSIDIAN();
			$blocks["3:$y:0"] = VanillaBlocks::OBSIDIAN();
		}
		$blocks["1:1:0"] = VanillaBlocks::FIRE();

		$world = $this->createMock(World::class);
		$world->method("getMinY")->willReturn(0);
		$world->method("getBlockAt")->willReturnCallback(
			static function(int $x, int $y, int $z) use (&$blocks) : Block{
				return clone ($blocks["$x:$y:$z"] ?? VanillaBlocks::AIR());
			}
		);

		$placed = [];
		$world->expects(self::exactly(6))
			->method("setBlockAt")
			->willReturnCallback(
				static function(int $x, int $y, int $z, Block $block) use (&$placed) : void{
					$placed["$x:$y:$z"] = clone $block;
				}
			);

		$frame = NetherPortalFrame::find($world, 1, 1, 0);
		self::assertNotNull($frame);
		$frame->light();

		self::assertCount(6, $placed);
		foreach($placed as $block){
			self::assertInstanceOf(NetherPortal::class, $block);
			self::assertSame(Axis::X, $block->getAxis());
		}
		foreach([1, 2] as $x){
			foreach([1, 2, 3] as $y){
				self::assertArrayHasKey("$x:$y:0", $placed);
			}
		}
	}
}
