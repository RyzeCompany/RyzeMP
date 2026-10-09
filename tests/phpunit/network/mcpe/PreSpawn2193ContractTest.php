<?php

declare(strict_types=1);

namespace pocketmine\tests\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\protocol\DimensionDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\VoxelShapesPacket;
use ReflectionClass;

/**
 * Independent reference bytes for the 2193 empty vanilla-dimension and
 * custom-voxel-shape registries sent by Mykoss/Allay before StartGame.
 */
final class PreSpawn2193ContractTest extends TestCase{
    public function testEmptyDimensionDataGoldenWire2193() : void{
        $packet = DimensionDataPacket::create([]);
        $out = new ByteBufferWriter();
        $packet->encode($out, ProtocolInfo::PROTOCOL_1_26_52);
        // ID 0xb4 followed by a zero definition count.
        self::assertSame("b40100", bin2hex($out->getData()));
        $decoded = new DimensionDataPacket();
        $decoded->decode(new ByteBufferReader($out->getData()), ProtocolInfo::PROTOCOL_1_26_52);
        self::assertSame([], $decoded->getDefinitions());
    }

    public function testEmptyVoxelShapeRegistryGoldenWire2193() : void{
        $packet = VoxelShapesPacket::create([], [], 0);
        $out = new ByteBufferWriter();
        $packet->encode($out, ProtocolInfo::PROTOCOL_1_26_52);
        // ID 0x151, zero shapes, zero names, zero custom shapes (uint16LE).
        self::assertSame("d10200000000", bin2hex($out->getData()));
        $decoded = new VoxelShapesPacket();
        $decoded->decode(new ByteBufferReader($out->getData()), ProtocolInfo::PROTOCOL_1_26_52);
        self::assertSame([], $decoded->getShapes());
        self::assertSame([], $decoded->getNameMap());
        self::assertSame(0, $decoded->getCustomShapeCount());
    }

    public function testPreSpawnOrderMatchesAllay2193() : void{
        $file = (new ReflectionClass(PreSpawnPacketHandler::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        $from = strpos($source, 'public function setUp() : void');
        self::assertIsInt($from);
        $setUpSource = substr($source, $from);
        $parts = [
            'DimensionDataPacket::create([])',
            'self::jigsawStructureData()',
            'VoxelShapesPacket::create([], [], 0)',
            'StartGamePacket::create('
        ];
        $last = -1;
        foreach($parts as $part){
            $pos = strpos($setUpSource, $part);
            self::assertNotFalse($pos, "Missing prespawn packet: $part");
            self::assertGreaterThan($last, $pos, "The packets must be sent in Allay handshake order");
            $last = $pos;
        }
        self::assertStringContainsString('if($protocolId === ProtocolInfo::PROTOCOL_1_26_52)', $setUpSource);
    }
}
