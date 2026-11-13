<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetEndpointTest extends TestCase{

	public function testKeepsConfiguredBindAddressAndPort() : void{
		$endpoint = new NetherNetEndpoint("192.0.2.25", 20014);
		self::assertSame("192.0.2.25", $endpoint->getAddress());
		self::assertSame(20014, $endpoint->getPort());
		self::assertSame("192.0.2.25:20014", $endpoint->signalingAddress());
	}

	public function testAcceptsWildcardIpv4Bind() : void{
		$endpoint = new NetherNetEndpoint("0.0.0.0", 19132);
		self::assertSame("0.0.0.0:19132", $endpoint->signalingAddress());
	}

	public function testRejectsInvalidPort() : void{
		$this->expectException(\InvalidArgumentException::class);
		new NetherNetEndpoint("0.0.0.0", 65536);
	}
}
