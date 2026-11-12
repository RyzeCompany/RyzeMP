<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\feature;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests the RyzeMP adaptation of VanillaMine's second block layer without
 * mutating or extending core Chunk/SubChunk API.
 */
final class FeatureLayerBridgeTest extends TestCase{

	public function testWaterloggedFeatureUsesExistingRyzeSubChunkPalettes() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		$java = (new ReflectionClass(JavaBlocks::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(JavaBlocks::class, 'pmAir'))->setValue($java, $air);

		$region = (new ReflectionClass(FeatureLevel::class))->newInstanceWithoutConstructor();
		$chunk = new Chunk([], false);
		(new ReflectionProperty(FeatureLevel::class, 'blocks'))->setValue($region, $java);
		(new ReflectionProperty(FeatureLevel::class, 'minY'))->setValue($region, -64);
		(new ReflectionProperty(FeatureLevel::class, 'sections'))->setValue($region, 24);
		(new ReflectionProperty(FeatureLevel::class, 'chunks'))->setValue($region, [0 => $chunk]);
		(new ReflectionProperty(FeatureLevel::class, 'subChunks'))->setValue($region, [
			0 => $chunk->getSubChunk(-4)
		]);

		$method = new ReflectionMethod(FeatureLevel::class, 'setLiquidLayerStateId');
		$method->invoke($region, 0, 3, 7, 12, $water);
		$sub = $chunk->getSubChunk(-4);
		$layers = $sub->getBlockLayers();
		self::assertCount(2, $layers);
		self::assertSame($air, $layers[0]->get(3, 7, 12));
		self::assertSame($water, $layers[1]->get(3, 7, 12));
		self::assertSame(BiomeIds::OCEAN, $sub->getBiomeArray()->get(3, 7, 12));

		$method->invoke($region, 0, 3, 7, 12, $air);
		self::assertSame($air, $sub->getBlockLayers()[1]->get(3, 7, 12));
	}

	public function testPendingTileNbtIsRejectedInsteadOfSilentlyLost() : void{
		$level = (new ReflectionClass(FeatureLevel::class))->newInstanceWithoutConstructor();
		$this->expectException(\RuntimeException::class);
		$level->setBlockEntity(1, 64, 1, \pocketmine\nbt\tag\CompoundTag::create());
	}
}
