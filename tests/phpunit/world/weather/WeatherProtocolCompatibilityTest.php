<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/** Packet encoding smoke tests: older and current Bedrock schemas. Not live-client tests. */
final class WeatherProtocolCompatibilityTest extends TestCase{
	public function testWeatherAndThunderPacketsEncodeOnOldAndCurrentProtocols() : void{
		$packets = [
			LevelEventPacket::create(LevelEvent::START_RAIN, 32767, null),
			LevelEventPacket::create(LevelEvent::START_THUNDER, 32767, null),
			LevelEventPacket::create(LevelEvent::STOP_RAIN, 0, null),
			LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null),
			LevelSoundEventPacket::nonActorSound(LevelSoundEvent::THUNDER, new Vector3(0, 80, 0), false),
		];
		foreach([ProtocolInfo::PROTOCOL_1_21_50, ProtocolInfo::CURRENT_PROTOCOL] as $protocolId){
			foreach($packets as $packet){
				$writer = new ByteBufferWriter();
				$packet->encode($writer, $protocolId);
				self::assertNotSame("", $writer->getData());
			}
		}
	}
}
