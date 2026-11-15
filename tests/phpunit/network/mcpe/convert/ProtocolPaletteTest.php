<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ProtocolPaletteTest extends TestCase{
	public function testLegacyAndCurrentSessionsUseDifferentNetworkPalettes() : void{
		$legacy = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_40);
		$middle = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_52);
		$current = TypeConverter::getInstance(ProtocolInfo::CURRENT_PROTOCOL);

		$stone = VanillaBlocks::STONE()->getStateId();
		$legacyId = $legacy->getBlockTranslator()->internalIdToNetworkId($stone);
		$middleId = $middle->getBlockTranslator()->internalIdToNetworkId($stone);
		$currentId = $current->getBlockTranslator()->internalIdToNetworkId($stone);

		self::assertNotSame($legacyId, $middleId);
		self::assertNotSame($legacyId, $currentId);
		self::assertSame($legacy, TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_40));
		self::assertSame($current, TypeConverter::getInstance());
		self::assertGreaterThan(
			count($legacy->getItemTypeDictionary()->getEntries()),
			count($current->getItemTypeDictionary()->getEntries())
		);
	}
}
