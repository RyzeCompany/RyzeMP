<?php

declare(strict_types=1);

namespace pocketmine\tests\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use ReflectionClass;

/**
 * Fixes mismatches observed in the captured 2193 wire and Allay's
 * working encoder: ChangeDimension uses eye-position; success action
 * carries zero coordinates.
 */
final class AllayDimensionWire2193Test extends TestCase{

    public function testChangeDimensionUsesHeadPositionGoldenWire() : void{
        // Captured destination feet position was (0.5, 74, -14.5).
        // Allay uses an eye-height origin (+1.62); the captured PocketMine
        // MovePlayer origin is y=75.621, so ChangeDimension must use that
        // same player-specific network position, not feet y=74.
        $packet = ChangeDimensionPacket::create(
            DimensionIds::OVERWORLD,
            new Vector3(0.5, 75.621, -14.5),
            false,
            1
        );
        $stream = new ByteBufferWriter();
        $packet->encode($stream, ProtocolInfo::PROTOCOL_1_26_52);
        self::assertSame(
            "3d000000003f". "f43d9742". "000068c10001000000",
            bin2hex($stream->getData())
        );
    }

    public function testAllayServerSuccessActionUsesZeroCoordinates() : void{
        $zero = new BlockPosition(0, 0, 0);
        $packet = PlayerActionPacket::create(1, PlayerAction::DIMENSION_CHANGE_ACK, $zero, $zero, 0);
        $stream = new ByteBufferWriter();
        $packet->encode($stream, ProtocolInfo::PROTOCOL_1_26_52);
        self::assertSame("24011a00000000000000", bin2hex($stream->getData()));
    }

    public function testRuntimeSendsAllayAlignedFields() : void{
        $file = (new ReflectionClass(NetworkSession::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertStringContainsString('new BlockPosition(0, 0, 0)', $source);
        self::assertStringContainsString(
            '$this->player->getOffsetPosition($this->player->getPosition())',
            $source
        );
    }
}
