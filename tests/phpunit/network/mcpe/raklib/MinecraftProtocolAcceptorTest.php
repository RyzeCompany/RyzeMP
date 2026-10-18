<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\raklib;

use PHPUnit\Framework\TestCase;

final class MinecraftProtocolAcceptorTest extends TestCase{
	public function testLegacyTransportIsClosedUntilEnabled() : void{
		$acceptor = new MinecraftProtocolAcceptor(11, false);
		self::assertSame(11, $acceptor->getPrimaryVersion());
		self::assertTrue($acceptor->accepts(11));
		self::assertFalse($acceptor->accepts(10));
	}

	public function testEnablingLegacyRetainsPrimaryAndRejectsOtherVersions() : void{
		$acceptor = new MinecraftProtocolAcceptor(11, true);
		self::assertSame(11, $acceptor->getPrimaryVersion());
		self::assertTrue($acceptor->accepts(10));
		self::assertTrue($acceptor->accepts(11));
		foreach([0, 9, 12, 419] as $version){
			self::assertFalse($acceptor->accepts($version));
		}
	}
}
