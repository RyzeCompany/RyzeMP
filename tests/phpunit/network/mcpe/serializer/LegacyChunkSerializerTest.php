<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\serializer;

use pmmp\encoding\ByteBufferWriter;
use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\LegacyBiomeMap;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;

final class LegacyChunkSerializerTest extends TestCase{
	public function testHeightRangesRemainSpecificToProtocolAndDimension() : void{
		self::assertSame([0, 15], ChunkSerializer::getDimensionChunkBounds(DimensionIds::OVERWORLD, 419));
		self::assertSame([0, 7], ChunkSerializer::getDimensionChunkBounds(DimensionIds::NETHER, 419));
		self::assertSame([0, 15], ChunkSerializer::getDimensionChunkBounds(DimensionIds::THE_END, 419));
		foreach(ProtocolInfo::ACCEPTED_PROTOCOL as $protocol){
			self::assertSame([-4, 19], ChunkSerializer::getDimensionChunkBounds(DimensionIds::OVERWORLD, $protocol));
			self::assertSame([0, 7], ChunkSerializer::getDimensionChunkBounds(DimensionIds::NETHER, $protocol));
			self::assertSame([0, 15], ChunkSerializer::getDimensionChunkBounds(DimensionIds::THE_END, $protocol));
		}
	}

	public function testLegacySubchunkCountDoesNotIncludeNegativeOrHighSections() : void{
		$chunk = new Chunk([], false);
		$chunk->setBlockStateId(0, -64, 0, 1);
		$chunk->setBlockStateId(0, 319, 0, 1);
		self::assertSame(0, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD, 419));
		self::assertSame(24, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD));
		$chunk->setBlockStateId(0, 31, 0, 1);
		self::assertSame(2, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD, 419));
		self::assertSame(24, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD));
		self::assertSame(1, $chunk->getBlockStateId(0, -64, 0));
		self::assertSame(1, $chunk->getBlockStateId(0, 319, 0));
	}

	public function testLegacyBiomeLayoutHasExactly256BytesInHistoricalZXOrder() : void{
		$chunk = $this->createMock(Chunk::class);
		$chunk->method('getHighestBlockAt')->willReturn(384);
		$sampledHeights = [];
		$chunk->method('getBiomeId')->willReturnCallback(static function(int $x, int $y, int $z) use (&$sampledHeights) : int{
			$sampledHeights[$y] = true;
			return $x === 3 && $z === 7 ? 2 : 1;
		});
		$out = new ByteBufferWriter();
		ChunkSerializer::serializeBiomes($chunk, DimensionIds::OVERWORLD, $out, 419);
		$expected = str_repeat("\x01", 256);
		$expected[(7 << 4) | 3] = "\x02";
		self::assertSame($expected, $out->getData());
		self::assertSame([255], array_keys($sampledHeights));
	}

	public function testNetherBiomeSamplingUsesTheNetherHeightLimit() : void{
		$chunk = $this->createMock(Chunk::class);
		$chunk->method('getHighestBlockAt')->willReturn(255);
		$sampledHeights = [];
		$chunk->method('getBiomeId')->willReturnCallback(static function(int $x, int $y, int $z) use (&$sampledHeights) : int{
			$sampledHeights[$y] = true;
			return 179; // soul sand valley, present in the original snapshot
		});
		$out = new ByteBufferWriter();
		ChunkSerializer::serializeBiomes($chunk, DimensionIds::NETHER, $out, 419);
		self::assertSame(str_repeat(chr(179), 256), $out->getData());
		self::assertSame([127], array_keys($sampledHeights));
	}

	public function testUnknownAndModernBiomeIdsAreNotSentTo419() : void{
		self::assertSame(0, LegacyBiomeMap::toLegacyId(192)); // cherry grove
		self::assertSame(0, LegacyBiomeMap::toLegacyId(256));
		self::assertSame(0, LegacyBiomeMap::toLegacyId(-1));
		self::assertSame(181, LegacyBiomeMap::toLegacyId(181)); // basalt deltas
		self::assertSame(9, LegacyBiomeMap::toLegacyId(9)); // End
	}

	public function testUniformPalettesUseHistoricalOneBitStorageWithoutMutatingTheChunk() : void{
		$dictionary = new BlockStateDictionary([
			new BlockStateDictionaryEntry(BlockTypeNames::AIR, [], 0, null),
			new BlockStateDictionaryEntry(BlockTypeNames::INFO_UPDATE, [], 0, null),
		]);
		$serializer = $this->createMock(BlockStateSerializer::class);
		$serializer->method('serialize')->willReturn(BlockStateData::current(BlockTypeNames::AIR, []));
		$translator = new BlockTranslator($dictionary, $serializer);
		$blocks = new PalettedBlockArray(7);
		$subchunk = new SubChunk(0, [$blocks], new PalettedBlockArray(0));
		self::assertSame(0, $blocks->getBitsPerBlock());
		$legacy = new ByteBufferWriter();
		ChunkSerializer::serializeSubChunk($subchunk, $translator, $legacy, false, true);
		// Version 8, one layer, one bit/network, 4096 zero indices, one palette ID 0.
		self::assertSame("\x08\x01\x03" . str_repeat("\x00", 512) . "\x02\x00", $legacy->getData());
		self::assertSame(0, $blocks->getBitsPerBlock());
		$modern = new ByteBufferWriter();
		ChunkSerializer::serializeSubChunk($subchunk, $translator, $modern, false);
		self::assertSame("\x08\x01\x01\x00", $modern->getData());
	}

	public function testUnknownDimensionIsRejectedForLegacyAsWell() : void{
		$this->expectException(\InvalidArgumentException::class);
		ChunkSerializer::getDimensionChunkBounds(99, 419);
	}
}
