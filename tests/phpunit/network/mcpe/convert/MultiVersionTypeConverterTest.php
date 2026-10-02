<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\MetaWildcardRecipeIngredient;
use pocketmine\data\bedrock\ItemTagToIdMap;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class MultiVersionTypeConverterTest extends TestCase{

	/** @return array<string, array{int, int}> */
	public static function protocolVersions() : array{
		return [
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30, 261],
			'26.40' => [ProtocolInfo::PROTOCOL_1_26_40, 271],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45, 271],
			'26.50' => [ProtocolInfo::PROTOCOL_1_26_50, 271],
			'26.51' => [ProtocolInfo::PROTOCOL_1_26_51, 271]
		];
	}

	#[DataProvider('protocolVersions')]
	public function testProtocolSpecificConverters(int $protocolId, int $expectedSchemaId) : void{
		$converter = TypeConverter::getInstance($protocolId);
		self::assertSame($expectedSchemaId, ItemTranslator::getItemSchemaId($protocolId));
		$airId = $converter->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId());
		self::assertSame('minecraft:air', $converter->getBlockTranslator()->getBlockStateDictionary()->generateDataFromStateId($airId)?->getName());
		self::assertGreaterThan(0, $converter->getItemTypeDictionary()->fromStringId('minecraft:shield'));
		self::assertNotEmpty(ItemTagToIdMap::getInstance($protocolId)->getIdsForTag('minecraft:boats'));
	}

	public function testBedrock2640ItemUpgradeSchema() : void{
		$upgrader = GlobalItemDataHandlers::getUpgrader()->getIdMetaUpgrader();
		self::assertArrayHasKey(271, $upgrader->getSchemas());
		self::assertSame(['minecraft:music_disc_bounce', 0], $upgrader->upgrade('minecraft:record_bounce', 0));
		self::assertSame(['minecraft:poplar_chest_boat', 0], $upgrader->upgrade('minecraft:chest_boat', 10));
	}

	public function testItemRuntimeIdsRemainVersionSpecific() : void{
		$legacy = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_30)->getItemTypeDictionary();
		$modern = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_40)->getItemTypeDictionary();
		$hotfix = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_45)->getItemTypeDictionary();
		self::assertSame(411, $legacy->fromStringId('minecraft:acacia_boat'));
		self::assertSame(382, $modern->fromStringId('minecraft:acacia_boat'));
		self::assertSame(382, $hotfix->fromStringId('minecraft:acacia_boat'));
		self::assertSame(632, $legacy->fromStringId('minecraft:debug_stick'));
		self::assertSame('minecraft:warped_fungus_on_a_stick', $modern->fromIntId(632));
	}

	public function testExperimentalTagsDoNotLeakIntoLegacyClients() : void{
		$legacy = ItemTagToIdMap::getInstance(ProtocolInfo::PROTOCOL_1_26_30);
		$modern = ItemTagToIdMap::getInstance(ProtocolInfo::PROTOCOL_1_26_40);
		$hotfix = ItemTagToIdMap::getInstance(ProtocolInfo::PROTOCOL_1_26_45);
		self::assertFalse($legacy->tagContainsId('minecraft:boat', 'minecraft:poplar_boat'));
		self::assertTrue($modern->tagContainsId('minecraft:boat', 'minecraft:poplar_boat'));
		self::assertTrue($hotfix->tagContainsId('minecraft:boat', 'minecraft:poplar_boat'));
		self::assertSame([], $legacy->getIdsForTag('minecraft:mushrooms_for_stew'));
		self::assertNotEmpty($modern->getIdsForTag('minecraft:mushrooms_for_stew'));
	}

	#[DataProvider('protocolVersions')]
	public function testProtocolSpecificRecipeDescriptors(int $protocolId, int $expectedSchemaId) : void{
		$converter = TypeConverter::getInstance($protocolId);
		$exact = $converter->coreRecipeIngredientToNet(new ExactRecipeIngredient(VanillaItems::STICK()))->getDescriptor();
		$wildcard = $converter->coreRecipeIngredientToNet(new MetaWildcardRecipeIngredient('minecraft:stick'))->getDescriptor();
		$expectedClass = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? StringIdMetaItemDescriptor::class : IntIdMetaItemDescriptor::class;
		self::assertInstanceOf($expectedClass, $exact);
		self::assertInstanceOf($expectedClass, $wildcard);
		self::assertSame($expectedSchemaId, ItemTranslator::getItemSchemaId($protocolId));
	}
}
