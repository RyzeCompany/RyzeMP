<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;
use pmmp\thread\ThreadSafeArray;
use raklib\utils\InternetAddress;

final class NetherNetSharedPortSocketTest extends TestCase{
	private static function stun(string $username) : string{
		$attribute = pack("nn", 6, strlen($username)) . $username;
		$attribute .= str_repeat("\0", (4 - (strlen($username) % 4)) % 4);
		return pack("nn", 1, strlen($attribute)) . "\x21\x12\xa4\x42"
			. str_repeat("\x11", 12) . $attribute;
	}

	public function testOnlyLegitimateStunUsernameExtractsUfrag() : void{
		self::assertSame("server-123", NetherNetSharedPortSocket::requestedUfrag(self::stun("server-123:client-xyz")));
		self::assertNull(NetherNetSharedPortSocket::requestedUfrag(self::stun("malformed")));
		self::assertNull(NetherNetSharedPortSocket::requestedUfrag(self::stun("bad!::peer")));
		self::assertNull(NetherNetSharedPortSocket::requestedUfrag("\x01\x00" . str_repeat("\0", 30)));
	}

	public function testAuthorizedStunUsesTheOnlyPublicUdpSocketAndRakNetStillPasses() : void{
		$backend = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($backend);
		self::assertTrue(socket_bind($backend, "127.0.0.1", 0));
		self::assertTrue(socket_getsockname($backend, $backendIp, $backendPort));
		self::assertTrue(socket_set_nonblock($backend));

		$commands = new ThreadSafeArray();
		$public = new NetherNetSharedPortSocket(new InternetAddress("127.0.0.1", 0, 4), $backendPort, $commands);
		self::assertTrue(socket_getsockname($public->getSocket(), $publicIp, $publicPort));
		$client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($client);
		self::assertTrue(socket_bind($client, "127.0.0.1", 0));
		self::assertTrue(socket_getsockname($client, $clientIp, $clientPort));
		self::assertTrue(socket_set_nonblock($client));

		$offer = self::stun("server-123:client-456");
		try{
			// An unknown ICE username must NEVER create a relay.
			self::assertSame(strlen($offer), socket_sendto($client, $offer, strlen($offer), 0, "127.0.0.1", $publicPort));
			$source = $port = null;
			self::assertNull($public->readPacket($source, $port));
			$buf = "";
			self::assertFalse(@socket_recvfrom($backend, $buf, 4096, MSG_DONTWAIT, $backendSource, $backendSourcePort));

			// Authenticated signaling publishes the server ICE ufrag over IPC.
			$commands[] = "+server-123";
			self::assertSame(strlen($offer), socket_sendto($client, $offer, strlen($offer), 0, "127.0.0.1", $publicPort));
			self::assertNull($public->readPacket($source, $port));
			$payload = "";
			self::assertSame(strlen($offer), socket_recvfrom($backend, $payload, 4096, MSG_DONTWAIT, $relayIp, $relayPort));
			self::assertSame($offer, $payload);
			self::assertSame("127.0.0.1", $relayIp);
			self::assertNotSame($publicPort, $relayPort);

			// A native ICE response must emerge from the ORIGINAL public port.
			$answer = self::stun("server-123:client-456");
			self::assertSame(strlen($answer), socket_sendto($backend, $answer, strlen($answer), 0, $relayIp, $relayPort));
			$public->readPacket($source, $port);
			$clientAnswer = "";
			self::assertSame(strlen($answer), socket_recvfrom($client, $clientAnswer, 4096, MSG_DONTWAIT, $senderIp, $senderPort));
			self::assertSame($answer, $clientAnswer);
			self::assertSame($publicPort, $senderPort);

			// Traditional RakNet packets are NOT consumed by ICE routing.
			$raknet = "\x01" . str_repeat("\x00", 32);
			self::assertSame(strlen($raknet), socket_sendto($client, $raknet, strlen($raknet), 0, "127.0.0.1", $publicPort));
			self::assertSame($raknet, $public->readPacket($source, $port));
			self::assertSame($clientPort, $port);

			// Revocation closes the route and rejects subsequent packets.
			$commands[] = "-server-123";
			$public->readPacket($source, $port);
			self::assertSame(strlen($offer), socket_sendto($client, $offer, strlen($offer), 0, "127.0.0.1", $publicPort));
			self::assertNull($public->readPacket($source, $port));
			$ignored = "";
			self::assertFalse(@socket_recvfrom($backend, $ignored, 4096, MSG_DONTWAIT, $ignoredIp, $ignoredPort));
		}finally{
			$public->close();
			socket_close($client);
			socket_close($backend);
		}
	}
}
