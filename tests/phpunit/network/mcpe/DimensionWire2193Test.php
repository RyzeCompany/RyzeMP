<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\world\format\Chunk;

/**
 * Protocol 2193 independent wire golden vectors and native chunk payload parser.
 * A successful test is not proof of compatibility with the proprietary client.
 */
final class DimensionWire2193Test extends TestCase{
    private const PROTOCOL = 2193;

    public function testChangeDimensionHasFixedU32LoadingScreen() : void{
        foreach([DimensionIds::OVERWORLD, DimensionIds::NETHER, DimensionIds::THE_END] as $dimension){
            foreach([0, 1, 0x12345678] as $screen){
                $packet = ChangeDimensionPacket::create($dimension, new Vector3(10.5, 64, -2.5), false, $screen);
                $writer = new ByteBufferWriter();
                $packet->encode($writer, self::PROTOCOL);
                $golden = chr(61) . chr($dimension << 1) . pack('g', 10.5) . pack('g', 64.0) . pack('g', -2.5) . "\x00" . pack('V', $screen);
                self::assertSame(bin2hex($golden), bin2hex($writer->getData()));
                $in = new ByteBufferReader($writer->getData());
                $decoded = new ChangeDimensionPacket();
                $decoded->decode($in, self::PROTOCOL);
                self::assertSame(0, $in->getUnreadLength());
                self::assertSame($dimension, $decoded->dimension);
                self::assertSame($screen, $decoded->getLoadingScreenId());
            }
        }
    }

    public function testLevelChunk2193WireMatchesExternalContract() : void{
        foreach([0, 1, 2] as $dim){
            $payload = "\x08\x01\x00";
            $packet = LevelChunkPacket::create(new ChunkPosition(0, -1), $dim, 11, false, null, $payload);
            $writer = new ByteBufferWriter();
            $packet->encode($writer, self::PROTOCOL);
            // ID 58, X=0, Z=-1, dimension, subCount=11, optional=null,
            // cache=false, cacheHashes=[], length-prefixed payload.
            $golden = chr(58) . "\x00\x01" . chr($dim << 1) . "\x0b\x00\x00\x00\x03" . $payload;
            self::assertSame(bin2hex($golden), bin2hex($writer->getData()));
            $in = new ByteBufferReader($writer->getData());
            $decoded = new LevelChunkPacket();
            $decoded->decode($in, self::PROTOCOL);
            self::assertSame(0, $in->getUnreadLength());
            self::assertSame($dim, $decoded->getDimensionId());
            self::assertSame($payload, $decoded->getExtraPayload());
        }
    }

    public function testTeleportMovePlayerHasOptionalTelemetryIn2193() : void{
        $packet = MovePlayerPacket::simple(123, new Vector3(10, 65, 12), 90, 45, 45, MovePlayerPacket::MODE_TELEPORT, false, 0, 0);
        $writer = new ByteBufferWriter();
        $packet->encode($writer, self::PROTOCOL);
        $golden = chr(19) . chr(123) . pack('g', 10.0) . pack('g', 65.0) . pack('g', 12.0) . pack('g', 90.0) . pack('g', 45.0) . pack('g', 45.0) . "\x02\x00\x00\x01" . pack('V2', 0, 0) . "\x00";
        self::assertSame(39, strlen($golden));
        self::assertSame(bin2hex($golden), bin2hex($writer->getData()));
    }

    public function testGeneratedDimensionChunksHaveCompleteIndependentPayloadBoundaries() : void{
        foreach([
            [DimensionIds::OVERWORLD, 72, BiomeIds::PLAINS, VanillaBlocks::STONE()->getStateId()],
            [DimensionIds::NETHER, 64, BiomeIds::HELL, VanillaBlocks::NETHERRACK()->getStateId()],
            [DimensionIds::THE_END, 48, BiomeIds::THE_END, VanillaBlocks::END_STONE()->getStateId()],
        ] as [$dimension, $y, $biome, $blockId]){
            $chunk = new Chunk([], false);
            $chunk->setBlockStateId(1, $y, 1, $blockId);
            $chunk->setBiomeId(1, $y, 1, $biome);
            $subCount = ChunkSerializer::getSubChunkCount($chunk, $dimension);
            $payload = ChunkSerializer::serializeFullChunk($chunk, $dimension, TypeConverter::getInstance(self::PROTOCOL), '');
            $reader = new ByteBufferReader($payload);
            for($index = 0; $index < $subCount; ++$index){
                self::assertSame(8, Byte::readUnsigned($reader), 'Subchunk version');
                $layers = Byte::readUnsigned($reader);
                self::assertGreaterThanOrEqual(1, $layers);
                self::assertLessThanOrEqual(2, $layers);
                for($i=0; $i<$layers; ++$i){
                    self::readNetworkPalette($reader, 4096);
                }
            }
            [$minY, $maxY] = ChunkSerializer::getDimensionChunkBounds($dimension);
            for($index=$minY; $index<=$maxY; ++$index){
                self::readNetworkPalette($reader, 64);
            }
            self::assertSame(0, Byte::readUnsigned($reader), 'Zero border-block count');
            self::assertSame(0, $reader->getUnreadLength(), 'Chunk has trailing bytes or missing palettes');
        }
    }

    private static function readNetworkPalette(ByteBufferReader $reader, int $cells) : void{
        $header = Byte::readUnsigned($reader);
        self::assertSame(1, $header & 1, 'Network palette bit');
        $bits = $header >> 1;
        self::assertContains($bits, [0, 1, 2, 3, 4, 5, 6, 8, 16]);
        if($bits > 0){
            $wordCount = (int) ceil($cells / intdiv(32, $bits));
            $reader->readByteArray($wordCount * 4);
            $count = VarInt::readSignedInt($reader);
            self::assertGreaterThan(0, $count);
            self::assertLessThanOrEqual($cells, $count);
        }else{
            $count = 1;
        }
        for($i=0; $i<$count; ++$i){
            self::assertGreaterThanOrEqual(0, VarInt::readSignedInt($reader));
        }
    }
}
