<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;

final class PowderSnowCauldronTest extends TestCase{
	public function testExtinguishingMeltsPowderSnowIntoWater() : void{
		$cauldron = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(3);
		$next = $cauldron->getStateAfterExtinguishing();
		self::assertInstanceOf(WaterCauldron::class, $next);
		self::assertSame(2, $next->getFillLevel());
		self::assertInstanceOf(Cauldron::class, VanillaBlocks::POWDER_SNOW_CAULDRON()->getStateAfterExtinguishing());
	}

	public function testPowderSnowBucketDoesNotTopUpPartialCauldron() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->expects(self::never())->method("setBlock");
		$cauldron = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(3);
		$cauldron->position($world, 0, 64, 0);
		$bucket = VanillaItems::POWDER_SNOW_BUCKET();
		$returnedItems = [];
		self::assertFalse($cauldron->onInteract($bucket, 1, new Vector3(0, 0, 0), null, $returnedItems));
		self::assertSame(3, $cauldron->getFillLevel());
		self::assertSame(1, $bucket->getCount());
		self::assertSame([], $returnedItems);
	}

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
		$world->method("isLoaded")->willReturn(true);
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
