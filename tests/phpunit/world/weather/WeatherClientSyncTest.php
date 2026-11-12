<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

final class WeatherClientSyncTest extends TestCase{
	public function testRainOnlyFinishesWithStartRainEvent() : void{
		$session = $this->getMockBuilder(NetworkSession::class)
			->disableOriginalConstructor()->onlyMethods(["sendDataPacket"])->getMock();
		$sent = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		$session->syncWorldWeather(1.0, 0.0);
		self::assertEquals([
			LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null),
			LevelEventPacket::create(LevelEvent::START_RAIN, 65535, null)
		], $sent);
	}

	public function testThunderStillStartsRainBeforeThunder() : void{
		$session = $this->getMockBuilder(NetworkSession::class)
			->disableOriginalConstructor()->onlyMethods(["sendDataPacket"])->getMock();
		$sent = [];
		$session->method("sendDataPacket")->willReturnCallback(static function(ClientboundPacket $packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		$session->syncWorldWeather(1.0, 0.5);
		self::assertEquals([
			LevelEventPacket::create(LevelEvent::START_RAIN, 65535, null),
			LevelEventPacket::create(LevelEvent::START_THUNDER, 32767, null)
		], $sent);
	}

	public function testLoadedChunkLightningThreshold() : void{
		self::assertFalse(Weather::isSuccessfulLightningRoll(0, 0));
		self::assertTrue(Weather::isSuccessfulLightningRoll(0, 1));
		self::assertTrue(Weather::isSuccessfulLightningRoll(15, 16));
		self::assertFalse(Weather::isSuccessfulLightningRoll(16, 16));
		self::assertFalse(Weather::isSuccessfulLightningRoll(10, 4));
	}
}
