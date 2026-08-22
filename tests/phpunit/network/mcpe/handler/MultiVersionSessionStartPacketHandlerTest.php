<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;

final class MultiVersionSessionStartPacketHandlerTest extends TestCase{

	/** @return array<string, array{int}> */
	public static function supportedProtocols() : array{
		return [
			'1.20.0' => [ProtocolInfo::PROTOCOL_1_20_0],
			'1.21.20' => [ProtocolInfo::PROTOCOL_1_21_20],
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30],
			'26.40 and 26.44' => [ProtocolInfo::PROTOCOL_1_26_40],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45]
		];
	}

	#[DataProvider('supportedProtocols')]
	public function testNetworkNegotiationAcceptsSupportedProtocols(int $protocolId) : void{
		$compressor = $this->createMock(Compressor::class);
		$compressor->expects(self::once())->method('getNetworkId')->willReturn(0);

		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method('setProtocolId')->with($protocolId);
		$session->expects(self::once())->method('getCompressor')->willReturn($compressor);
		$session->expects(self::once())->method('sendDataPacket')->with(self::isInstanceOf(NetworkSettingsPacket::class))->willReturn(true);
		$session->expects(self::never())->method('disconnectIncompatibleProtocol');

		$succeeded = false;
		$handler = new SessionStartPacketHandler($session, static function() use (&$succeeded) : void{
			$succeeded = true;
		});

		self::assertTrue($handler->handleRequestNetworkSettings(RequestNetworkSettingsPacket::create($protocolId)));
		self::assertTrue($succeeded);
	}

	public function testNetworkNegotiationRejectsUnsupportedProtocol() : void{
		$unsupportedProtocol = ProtocolInfo::PROTOCOL_1_26_45 + 1;
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method('disconnectIncompatibleProtocol')->with($unsupportedProtocol);
		$session->expects(self::never())->method('setProtocolId');
		$session->expects(self::never())->method('sendDataPacket');

		$succeeded = false;
		$handler = new SessionStartPacketHandler($session, static function() use (&$succeeded) : void{
			$succeeded = true;
		});

		self::assertTrue($handler->handleRequestNetworkSettings(RequestNetworkSettingsPacket::create($unsupportedProtocol)));
		self::assertFalse($succeeded);
	}

	public function testConcreteGameVersionIsRetainedPerNetworkSession() : void{
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		self::assertNull($session->getGameVersion());
		$session->setGameVersion('1.26.44');
		self::assertSame('1.26.44', $session->getGameVersion());
	}
}
