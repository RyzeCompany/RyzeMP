<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\encryption;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ProtocolEncryptionTest extends TestCase{
	public function testMixedSessionsKeepIndependentCipherStreams() : void{
		$key = str_repeat("\x13", 32);
		$enabled = EncryptionContext::$ENABLED;
		$oldSender = EncryptionContext::forProtocol($key, 419);
		$oldReceiver = EncryptionContext::cfb8($key);
		$newSender = EncryptionContext::forProtocol($key, ProtocolInfo::CURRENT_PROTOCOL);
		$newReceiver = EncryptionContext::fakeGCM($key);
		foreach(['first', 'second', 'third'] as $payload){
			$oldWire = $oldSender->encrypt($payload);
			$newWire = $newSender->encrypt($payload);
			self::assertNotSame($oldWire, $newWire);
			self::assertSame($payload, $oldReceiver->decrypt($oldWire));
			self::assertSame($payload, $newReceiver->decrypt($newWire));
			self::assertSame($enabled, EncryptionContext::$ENABLED);
		}
	}

	public function testEveryAcceptedProtocolRetainsModernEncryption() : void{
		$key = str_repeat("\x25", 32);
		foreach(ProtocolInfo::ACCEPTED_PROTOCOL as $protocol){
			self::assertSame(
				EncryptionContext::fakeGCM($key)->encrypt('baseline'),
				EncryptionContext::forProtocol($key, $protocol)->encrypt('baseline')
			);
		}
	}
}
