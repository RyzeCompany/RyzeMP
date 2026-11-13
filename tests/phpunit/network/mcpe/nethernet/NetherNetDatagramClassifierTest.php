<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use PHPUnit\Framework\TestCase;

final class NetherNetDatagramClassifierTest extends TestCase{

	public function testRaknetPingDoesNotLookLikeStun() : void{
		// RakNet unconnected ping includes a 64-bit timestamp and magic text.
		$rakNetPing = "\x01" . str_repeat("\x00", 8) . "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
		self::assertSame(NetherNetDatagramClassifier::RAKNET, NetherNetDatagramClassifier::classify($rakNetPing));
	}

	public function testValidStunBindingRequest() : void{
		$stun = "\x00\x01\x00\x00\x21\x12\xa4\x42" . str_repeat("\xab", 12);
		self::assertSame(NetherNetDatagramClassifier::STUN, NetherNetDatagramClassifier::classify($stun));
	}

	public function testRejectsFakeStunCookieWithWrongLength() : void{
		$stun = "\x00\x01\x00\x04\x21\x12\xa4\x42" . str_repeat("\xab", 12);
		self::assertSame(NetherNetDatagramClassifier::RAKNET, NetherNetDatagramClassifier::classify($stun));
	}

	public function testDtlsRecord() : void{
		// DTLS 1.2 ApplicationData, epoch 0, sequence 0, empty payload.
		$dtls = "\x17\xfe\xfd" . str_repeat("\x00", 8) . "\x00\x00";
		self::assertSame(NetherNetDatagramClassifier::DTLS, NetherNetDatagramClassifier::classify($dtls));
	}

	public function testUntrustedShortRecordsCannotClaimDtls() : void{
		self::assertSame(NetherNetDatagramClassifier::RAKNET, NetherNetDatagramClassifier::classify("\x17\xfe\xfd"));
	}
}
