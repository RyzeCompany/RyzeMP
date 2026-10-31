<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetSessionGatewayTest extends TestCase{
	public function testEndpointMustHaveRealIpAndPort() : void{
		foreach([["192.0.2.40", 19132], ["2001:db8::1", 19132]] as [$ip, $port]){
			NetherNetSessionGateway::validatePeerEndpoint($ip, $port);
			self::assertTrue(true);
		}
		foreach([["0.0.0.0", 19132], ["::", 19132], ["play.example.net", 19132], ["", 19132], ["192.0.2.40", 0], ["192.0.2.40", 65536]] as [$ip, $port]){
			try{
				NetherNetSessionGateway::validatePeerEndpoint($ip, $port);
				self::fail("Expected rejection for $ip:$port");
			}catch(\InvalidArgumentException){
				self::assertTrue(true);
			}
		}
	}

	public function testPacketSenderPassesRawBedrockPayloadWithoutRaknetPrefix() : void{
		$sent = [];
		$closed = [];
		$sender = new NetherNetPacketSender(
			static function(string $bytes, bool $immediate, ?int $receiptId) use (&$sent) : void{
				$sent[] = [$bytes, $immediate, $receiptId];
			},
			static function(string $reason) use (&$closed) : void{
				$closed[] = $reason;
			}
		);
		$sender->send("\x00game", true, null);
		self::assertSame([["\x00game", true, null]], $sent);
		$sender->close("done");
		$sender->close("again");
		$sender->send("ignored", false, null);
		self::assertSame(["done"], $closed);
		self::assertCount(1, $sent);
	}

	public function testPacketSenderCannotPretendLocalQueueIsPeerAck() : void{
		$sender = new NetherNetPacketSender(static function() : void{}, static function() : void{});
		$this->expectException(\LogicException::class);
		$sender->send("data", false, 42);
	}
}
