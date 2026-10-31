<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetSharedPortSignalingBridgeTest extends TestCase{
	private static function answer(string $ufrag) : string{
		return "v=0\r\n"
			. "a=ice-ufrag:" . $ufrag . "\r\n"
			. "a=ice-pwd:signedsecret\r\n"
			. "a=fingerprint:sha-256 AB:CD\r\n"
			. "a=candidate:host1 1 udp 98765 127.0.0.1 19134 typ host\r\n";
	}

	public function testAuthorizeBeforePublishingAndRevokeOnSessionClose() : void{
		$log = [];
		$bridge = new NetherNetSharedPortSignalingBridge(
			static function(string $ufrag) use (&$log) : void{ $log[] = "+$ufrag"; },
			static function(string $ufrag) use (&$log) : void{ $log[] = "-$ufrag"; }
		);
		$answer = $bridge->preparedAnswer("session-1", self::answer("server123"), "203.0.113.4", 19132);
		self::assertSame(["+server123"], $log);
		self::assertStringContainsString("203.0.113.4 19132 typ host", $answer);
		self::assertStringNotContainsString("19134", $answer);
		self::assertSame(1, $bridge->getConnectionCount());
		$bridge->close("session-1");
		$bridge->close("session-1");
		self::assertSame(["+server123", "-server123"], $log);
		self::assertSame(0, $bridge->getConnectionCount());
	}

	public function testRejectsDuplicateUfragAndShutdownRevokesEverySession() : void{
		$log = [];
		$bridge = new NetherNetSharedPortSignalingBridge(
			static function(string $ufrag) use (&$log) : void{ $log[] = "+$ufrag"; },
			static function(string $ufrag) use (&$log) : void{ $log[] = "-$ufrag"; }
		);
		$bridge->preparedAnswer("a", self::answer("server123"), "203.0.113.4", 19132);
		try{
			$bridge->preparedAnswer("b", self::answer("server123"), "203.0.113.4", 19132);
			self::fail("Duplicate ICE ufrag must not be advertised");
		}catch(\InvalidArgumentException){
			self::assertCount(1, $log);
		}
		$bridge->preparedAnswer("b", self::answer("server456"), "203.0.113.4", 19132);
		$bridge->shutdown();
		self::assertSame(["+server123", "+server456", "-server123", "-server456"], $log);
	}
}
