<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;

final class PowderSnowCauldronTest extends TestCase{
	public function testBlockStateIsRegisteredAndPreservesFillLevel() : void{
		$block = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(3);
		self::assertSame(BlockTypeIds::POWDER_SNOW_CAULDRON, $block->getTypeId());
		self::assertSame(3, $block->getFillLevel());
		self::assertTrue(RuntimeBlockStateRegistry::getInstance()->hasStateId($block->getStateId()));
		$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
		self::assertSame("powder_snow", $data->getState("cauldron_liquid")?->getValue());
		$decoded = GlobalBlockStateHandlers::getDeserializer()->deserialize($data);
		self::assertSame($block->getStateId(), $decoded);
	}

	public function testAllFillLevelsAreRegistered() : void{
		for($level = 1; $level <= 6; ++$level){
			$block = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel($level);
			self::assertTrue(RuntimeBlockStateRegistry::getInstance()->hasStateId($block->getStateId()));
		}
	}

	public function testDeterministicSnowfallIncrementsCauldron() : void{
		$world = $this->createMock(World::class);
		$cauldron = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(3);
		$cauldron->position($world, 10, 64, 20);
		$world->expects(self::once())->method("setBlock")->with(
			$cauldron->getPosition(),
			self::callback(static fn(Block $b) : bool => $b instanceof PowderSnowCauldron && $b->getFillLevel() === 4)
		);
		$cauldron->fillByPrecipitation();
		self::assertSame(3, $cauldron->getFillLevel());
	}
}
