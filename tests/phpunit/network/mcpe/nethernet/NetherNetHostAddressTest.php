<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetHostAddressTest extends TestCase{
	public function testConcreteServerIpIsUsedWithoutAnyAdditionalConfiguration() : void{
		self::assertSame("192.0.2.42", NetherNetHostAddress::resolve("192.0.2.42", null));
		self::assertSame("192.0.2.42", NetherNetHostAddress::resolve("192.0.2.42", "203.0.113.2:19132"));
	}

	public function testWildcardBindResolvesNumericHostWithoutAdvertisingWildcard() : void{
		self::assertSame("203.0.113.55", NetherNetHostAddress::resolve("0.0.0.0", "203.0.113.55:19132"));
		self::assertSame("127.0.0.1", NetherNetHostAddress::resolve("0.0.0.0", "127.0.0.1"));
		self::assertNull(NetherNetHostAddress::resolve("0.0.0.0", "0.0.0.0"));
		self::assertNull(NetherNetHostAddress::resolve("0.0.0.0", "minecraft.example.com:19132"));
		self::assertNull(NetherNetHostAddress::resolve("0.0.0.0", "203.0.113.12:0"));
		self::assertNull(NetherNetHostAddress::resolve("0.0.0.0", null));
	}

	public function testHttpHostHeadersAcceptNormalSyntaxAndRejectAmbiguity() : void{
		self::assertSame("203.0.113.55:19132", NetherNetHostAddress::requestHost("POST /v1/join/abc HTTP/1.1\r\nHost: 203.0.113.55:19132\r\nContent-Length: 20\r\n\r\n"));
		self::assertSame("203.0.113.55", NetherNetHostAddress::requestHost("POST /v1/join/abc HTTP/1.1\r\nhost:203.0.113.55\r\n\r\n"));
		self::assertNull(NetherNetHostAddress::requestHost("GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nHost: 127.0.0.2\r\n\r\n"));
		self::assertNull(NetherNetHostAddress::requestHost("GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nHost : 127.0.0.2\r\n\r\n"));
		self::assertNull(NetherNetHostAddress::requestHost("GET / HTTP/1.1\nHost: 127.0.0.1\n\n"));
	}
}
