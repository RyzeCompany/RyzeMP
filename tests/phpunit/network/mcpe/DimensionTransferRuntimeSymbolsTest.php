<?php

declare(strict_types=1);

namespace pocketmine\tests\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use ReflectionClass;

/**
 * Regression for the 2026-10-09 cross-dimension crash.
 * PHP syntax checking alone cannot detect unqualified class names that
 * resolve only when an uncommon runtime network path is executed.
 */
final class DimensionTransferRuntimeSymbolsTest extends TestCase{
    public function testSuccessActionRuntimeNamesResolveInNetworkSession() : void{
        $session = new ReflectionClass(NetworkSession::class);
        $sourceFile = $session->getFileName();
        self::assertIsString($sourceFile);
        $source = file_get_contents($sourceFile);
        self::assertIsString($source);

        self::assertStringContainsString(
            'use pocketmine\\network\\mcpe\\protocol\\PlayerActionPacket;',
            $source,
            "NetworkSession must import the packet from the protocol namespace"
        );
        self::assertStringContainsString(
            'use pocketmine\\network\\mcpe\\protocol\\types\\PlayerAction;',
            $source,
            "NetworkSession must import the dimension action constants"
        );
        self::assertTrue(class_exists(PlayerActionPacket::class));
        self::assertIsInt(PlayerAction::DIMENSION_CHANGE_ACK);
    }
}
