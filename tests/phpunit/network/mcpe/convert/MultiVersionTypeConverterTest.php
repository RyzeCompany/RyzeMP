<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class MultiVersionTypeConverterTest extends TestCase{

	/** @return array<string, array{int, int}> */
	public static function protocolVersions() : array{
		return [
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30, 261],
			'26.40' => [ProtocolInfo::PROTOCOL_1_26_40, 271],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45, 271]
		];
	}

	#[DataProvider('protocolVersions')]
	public function testProtocolSpecificConverters(int $protocolId, int $expectedSchemaId) : void{
		$converter = TypeConverter::getInstance($protocolId);
		self::assertSame($expectedSchemaId, ItemTranslator::getItemSchemaId($protocolId));
		self::assertGreaterThanOrEqual(0, $converter->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId()));
		self::assertGreaterThan(0, $converter->getItemTypeDictionary()->fromStringId('minecraft:shield'));
	}

	public function testBedrock2640ItemUpgradeSchema() : void{
		$upgrader = GlobalItemDataHandlers::getUpgrader()->getIdMetaUpgrader();
		self::assertArrayHasKey(271, $upgrader->getSchemas());
		self::assertSame(['minecraft:music_disc_bounce', 0], $upgrader->upgrade('minecraft:record_bounce', 0));
		self::assertSame(['minecraft:poplar_chest_boat', 0], $upgrader->upgrade('minecraft:chest_boat', 10));
	}
}
