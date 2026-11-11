<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\block\GlobalBlockStateHandlers;
use pocketmine\world\weather\Precipitation;

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

	public function testSnowCauldronOnlyAcceptsSnowAndHasSixLevels() : void{
		$block = VanillaBlocks::POWDER_SNOW_CAULDRON();
		self::assertSame(1, $block->getFillLevel());
		self::assertSame(6, $block->setFillLevel(6)->getFillLevel());
		self::assertSame(Precipitation::SNOW, Precipitation::SNOW);
	}
}
