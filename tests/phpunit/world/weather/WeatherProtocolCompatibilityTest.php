<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\entity\object\LightningBolt;
use pocketmine\world\sound\CauldronEmptyPowderSnowSound;
use pocketmine\world\sound\CauldronFillPowderSnowSound;

/** Packet encoding smoke tests: older and current Bedrock schemas. Not live-client tests. */
final class WeatherProtocolCompatibilityTest extends TestCase{
	public function testWeatherAndThunderPacketsEncodeOnOldAndCurrentProtocols() : void{
		$packets = [
			LevelEventPacket::create(LevelEvent::START_RAIN, 32767, null),
			LevelEventPacket::create(LevelEvent::START_THUNDER, 32767, null),
			LevelEventPacket::create(LevelEvent::STOP_RAIN, 0, null),
			LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null),
			LevelSoundEventPacket::nonActorSound(LevelSoundEvent::THUNDER, new Vector3(0, 80, 0), false),
			AddActorPacket::create(
				1, 1, LightningBolt::getNetworkTypeId(),
				new Vector3(0.5, 80, 0.5), new Vector3(0, 0, 0),
				0.0, 0.0, 0.0, 0.0,
				[], [], new PropertySyncData([], []), []
			),
		];
		$position = new Vector3(0, 80, 0);
		foreach([new CauldronFillPowderSnowSound(), new CauldronEmptyPowderSnowSound()] as $sound){
			foreach($sound->encode($position) as $packet){
				$packets[] = $packet;
			}
		}
		foreach([ProtocolInfo::PROTOCOL_1_20_0, ProtocolInfo::PROTOCOL_1_21_50, ProtocolInfo::CURRENT_PROTOCOL] as $protocolId){
			foreach($packets as $packet){
				$writer = new ByteBufferWriter();
				$packet->encode($writer, $protocolId);
				self::assertNotSame("", $writer->getData());
			}
		}
	}
}
