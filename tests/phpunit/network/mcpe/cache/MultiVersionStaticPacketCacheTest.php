<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class MultiVersionStaticPacketCacheTest extends TestCase{

	/** @return array<string, array{int, string}> */
	public static function supportedGameVersions() : array{
		return [
			'1.20.0' => [ProtocolInfo::PROTOCOL_1_20_0, '1.20.0'],
			'1.21.20' => [ProtocolInfo::PROTOCOL_1_21_20, '1.21.20'],
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30, '1.26.30'],
			'26.40' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.40'],
			'26.44' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.44'],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45, '1.26.45']
		];
	}

	#[DataProvider('supportedGameVersions')]
	public function testBiomeDefinitionsEncodeAndDecodeForEveryClient(int $protocolId, string $gameVersion) : void{
		$packet = StaticPacketCache::getInstance()->getBiomeDefs($protocolId);
		$out = new ByteBufferWriter();
		$packet->encode($out, $protocolId, $gameVersion);

		$decoded = new BiomeDefinitionListPacket();
		$decoded->decode(new ByteBufferReader($out->getData()), $protocolId, $gameVersion);

		if($protocolId < ProtocolInfo::PROTOCOL_1_21_80){
			self::assertNotNull($decoded->getLegacyDefinitions());
		}else{
			self::assertCount(88, $decoded->buildDefinitionsFromData());
		}
	}

	public function testNetherBiomeParticleDensitiesArePreserved() : void{
		$definitions = [];
		foreach(StaticPacketCache::getInstance()->getBiomeDefs(ProtocolInfo::PROTOCOL_1_26_45)->buildDefinitionsFromData() as $definition){
			$definitions[$definition->getBiomeName()] = $definition;
		}

		self::assertSame(2.0, $definitions['minecraft:basalt_deltas']->getWhiteAshDensity());
		self::assertSame(0.25, $definitions['minecraft:crimson_forest']->getRedSporeDensity());
		self::assertSame(0.05, $definitions['minecraft:soulsand_valley']->getAshDensity());
		self::assertSame(0.25, $definitions['minecraft:warped_forest']->getBlueSporeDensity());
	}
}
