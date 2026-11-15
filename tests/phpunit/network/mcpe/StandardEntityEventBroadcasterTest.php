<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use ReflectionMethod;

final class StandardEntityEventBroadcasterTest extends TestCase{
	public function testConvertedPacketsAreGroupedByClientProtocol() : void{
		$legacyConverter = $this->createMock(TypeConverter::class);
		$currentConverter = $this->createMock(TypeConverter::class);
		$legacyOne = $this->createMock(NetworkSession::class);
		$legacyTwo = $this->createMock(NetworkSession::class);
		$current = $this->createMock(NetworkSession::class);
		foreach([$legacyOne, $legacyTwo] as $session){
			$session->method("getProtocolId")->willReturn(ProtocolInfo::PROTOCOL_1_26_40);
			$session->method("getTypeConverter")->willReturn($legacyConverter);
		}
		$current->method("getProtocolId")->willReturn(ProtocolInfo::CURRENT_PROTOCOL);
		$current->method("getTypeConverter")->willReturn($currentConverter);

		$legacyPacket = RemoveActorPacket::create(40);
		$currentPacket = RemoveActorPacket::create(60);
		$sent = [];
		$broadcaster = $this->createMock(PacketBroadcaster::class);
		$broadcaster->expects(self::exactly(2))->method("broadcastPackets")->willReturnCallback(
			static function(array $recipients, array $packets) use (&$sent) : void{
				$sent[] = [$recipients, $packets];
			}
		);

		$subject = new StandardEntityEventBroadcaster($broadcaster, $currentConverter);
		$method = new ReflectionMethod($subject, "sendConvertedDataPacket");
		$method->invoke(
			$subject,
			[$legacyOne, $current, $legacyTwo],
			static fn(TypeConverter $converter) : ClientboundPacket => $converter === $legacyConverter ? $legacyPacket : $currentPacket
		);
		self::assertSame([[$legacyOne, $legacyTwo], [$legacyPacket]], $sent[0]);
		self::assertSame([[$current], [$currentPacket]], $sent[1]);
	}

	public function testNoRecipientsDoesNotCreateOrBroadcastPackets() : void{
		$converter = $this->createMock(TypeConverter::class);
		$broadcaster = $this->createMock(PacketBroadcaster::class);
		$broadcaster->expects(self::never())->method("broadcastPackets");
		$subject = new StandardEntityEventBroadcaster($broadcaster, $converter);
		$method = new ReflectionMethod($subject, "sendConvertedDataPacket");
		$method->invoke($subject, [], static function(TypeConverter $typeConverter) : ClientboundPacket{
			throw new \LogicException("No packet should be created for empty recipient lists");
		});
	}
}
