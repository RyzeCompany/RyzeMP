<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\Byte;
use pmmp\encoding\VarInt;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;

final class ChunkBiomeCompatibilityTest extends TestCase{
	public function testNewAndUnknownBiomesAreConvertedOnlyForTransmission() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$source = [BiomeIds::PALE_GARDEN, BiomeIds::SULFUR_CAVES, 32767, BiomeIds::LUSH_CAVES];
		$sections = [];
		foreach($source as $y => $biome){
			$sections[$y] = new SubChunk($air, [], new PalettedBlockArray($biome));
		}
		$chunk = new Chunk($sections, true);
		foreach(ProtocolInfo::ACCEPTED_PROTOCOL as $protocol){
			$buffer = new ByteBufferWriter();
			ChunkSerializer::serializeBiomes($chunk, DimensionIds::OVERWORLD, $buffer, $protocol);
			$reader = new ByteBufferReader($buffer->getData());
			for($y = -4; $y < 0; ++$y){
				self::assertSame(1, Byte::readUnsigned($reader));
				VarInt::readSignedInt($reader);
			}
			$expected = [
				$protocol < ProtocolInfo::PROTOCOL_1_21_50 ? BiomeIds::ROOFED_FOREST : BiomeIds::PALE_GARDEN,
				$protocol < ProtocolInfo::PROTOCOL_1_26_30 ? BiomeIds::DRIPSTONE_CAVES : BiomeIds::SULFUR_CAVES,
				BiomeIds::OCEAN,
				BiomeIds::LUSH_CAVES
			];
			foreach($expected as $y => $biome){
				self::assertSame(1, Byte::readUnsigned($reader));
				self::assertSame($biome, VarInt::readSignedInt($reader), "Protocol $protocol, subchunk $y");
				self::assertSame($source[$y], $chunk->getBiomeId(0, $y << 4, 0));
			}
		}
	}
}
