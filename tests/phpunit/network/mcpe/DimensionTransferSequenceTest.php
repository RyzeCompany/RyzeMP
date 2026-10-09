<?php

declare(strict_types=1);

namespace pocketmine\tests\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\dimension\DimensionTransferSequence;

final class DimensionTransferSequenceTest extends TestCase{

    public function testMovePacketMustWaitUntilTargetChunkIsQueued() : void{
        $sequence = new DimensionTransferSequence();
        $sequence->begin();
        self::assertTrue($sequence->deferTeleport());
        self::assertFalse($sequence->takeReady());
        $sequence->targetChunkQueued();
        self::assertTrue($sequence->takeReady());
        self::assertFalse($sequence->takeReady(), "Repeated chunk callbacks must not resend ACK/teleport");
        self::assertFalse($sequence->deferTeleport());
    }

    public function testSynchronousTargetChunkCallbackBeforeTeleport() : void{
        $sequence = new DimensionTransferSequence();
        $sequence->begin();
        $sequence->targetChunkQueued();
        self::assertFalse($sequence->takeReady());
        self::assertTrue($sequence->deferTeleport());
        self::assertTrue($sequence->takeReady());
    }

    public function testSecondTransferInvalidatesPreviousState() : void{
        $sequence = new DimensionTransferSequence();
        $sequence->begin();
        $sequence->deferTeleport();
        $sequence->begin(); // e.g., a new world change before the old one completes
        $sequence->targetChunkQueued();
        self::assertFalse($sequence->takeReady());
        $sequence->deferTeleport();
        self::assertTrue($sequence->takeReady());
    }

    public function testCancelOnWorldOrSessionExit() : void{
        $sequence = new DimensionTransferSequence();
        $sequence->begin();
        $sequence->deferTeleport();
        $sequence->cancel();
        $sequence->targetChunkQueued();
        self::assertFalse($sequence->takeReady());
    }

    public function testTargetChunkWithoutTeleportCannotSendAck() : void{
        $sequence = new DimensionTransferSequence();
        $sequence->begin();
        $sequence->targetChunkQueued();
        self::assertFalse($sequence->takeReady());
    }
}
