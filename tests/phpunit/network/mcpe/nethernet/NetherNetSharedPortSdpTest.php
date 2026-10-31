<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetSharedPortSdpTest extends TestCase{
	private const ANSWER = "v=0\r\n"
		. "a=ice-ufrag:server-123\r\n"
		. "a=ice-pwd:private-password\r\n"
		. "a=fingerprint:sha-256 AB:CD\r\n"
		. "a=candidate:host1 1 udp 12345 127.0.0.1 19134 typ host generation 0\r\n";

	public function testOnlyPublicUdpPortAdvertisedAndIdentityPreserved() : void{
		$result = NetherNetSharedPortSdp::rewriteAnswer(self::ANSWER, "203.0.113.10", 19132);
		self::assertSame("server-123", $result["ufrag"]);
		self::assertStringContainsString("a=candidate:host1 1 udp 12345 203.0.113.10 19132 typ host\r\n", $result["sdp"]);
		self::assertStringNotContainsString("127.0.0.1 19134", $result["sdp"]);
		self::assertStringContainsString("a=fingerprint:sha-256 AB:CD\r\n", $result["sdp"]);
		self::assertStringContainsString("a=ice-pwd:private-password\r\n", $result["sdp"]);
	}

	public function testRejectsMissingUfragOrUnsupportedCandidate() : void{
		foreach([
			str_replace("a=ice-ufrag:server-123\r\n", "", self::ANSWER),
			str_replace("udp", "tcp", self::ANSWER),
			str_replace("typ host", "typ relay", self::ANSWER)
		] as $answer){
			try{
				NetherNetSharedPortSdp::rewriteAnswer($answer, "203.0.113.10", 19132);
				self::fail("Expected invalid ICE SDP");
			}catch(\InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}

	public function testCannotAdvertiseWildcardBindAddressOrDifferentPort() : void{
		$this->expectException(\InvalidArgumentException::class);
		NetherNetSharedPortSdp::rewriteAnswer(self::ANSWER, "0.0.0.0", 19132);
	}
}
