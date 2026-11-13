<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\feature;

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\Tile;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests VanillaMine feature writes through the RyzeMP chunk representation.
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

	public function testPendingTileNbtTravelsWithNeighbourChunk() : void{
		$level = (new ReflectionClass(FeatureLevel::class))->newInstanceWithoutConstructor();
		$neighbour = new Chunk([], true);
		$neighbour->clearTerrainDirtyFlags();
		$level->minX = -16;
		$level->minZ = -16;
		(new ReflectionProperty(FeatureLevel::class, 'minY'))->setValue($level, -64);
		(new ReflectionProperty(FeatureLevel::class, 'maxY'))->setValue($level, 320);
		(new ReflectionProperty(FeatureLevel::class, 'chunks'))->setValue($level, [1 => $neighbour]);

		$nbt = BlockEntityData::lootContainer("Chest", "minecraft:chests/simple_dungeon", -4172144997902289642);
		self::assertFalse($level->setBlockEntity(-17, -20, 3, $nbt));
		self::assertTrue($level->setBlockEntity(-7, -20, 3, $nbt));
		$level->finish();
		self::assertNull($nbt->getTag(Tile::TAG_X));
		self::assertTrue($neighbour->isTerrainDirty());

		$result = FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain($neighbour));
		self::assertTrue($result->isPopulated());
		$tag = $result->getPendingTileNBTAt(9, -20, 3);
		self::assertNotNull($tag);
		self::assertSame(-7, $tag->getInt(Tile::TAG_X));
		self::assertSame(-20, $tag->getInt(Tile::TAG_Y));
		self::assertSame(3, $tag->getInt(Tile::TAG_Z));
		self::assertSame(-4172144997902289642, $tag->getLong("LootTableSeedLong"));
	}
}
