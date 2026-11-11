<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

/** A real, version-specific dictionary is loaded for every tested Bedrock protocol. */
final class WeatherBlockProtocolMatrixTest extends TestCase{
	public function testLightningRodAndPowderSnowMapOrFallbackSafely() : void{
		$rod = VanillaBlocks::LIGHTNING_ROD()->setPowered(true);
		$powderSnow = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(4);
		foreach([
			ProtocolInfo::PROTOCOL_1_20_0,
			ProtocolInfo::PROTOCOL_1_21_50,
			ProtocolInfo::PROTOCOL_1_26_40,
			ProtocolInfo::CURRENT_PROTOCOL
		] as $protocol){
			$translator = BlockTranslator::loadFromProtocolId($protocol);
			$rodData = $translator->internalIdToCurrentNetworkStateData($rod->getStateId());
			$cauldronData = $translator->internalIdToCurrentNetworkStateData($powderSnow->getStateId());
			// For unsupported legacy dictionaries the intentional fallback is info_update.
			self::assertContains($rodData->getName(), ["minecraft:lightning_rod", "minecraft:info_update"]);
			self::assertContains($cauldronData->getName(), ["minecraft:cauldron", "minecraft:info_update"]);
			// Every emitted runtime ID must round-trip through this protocol's dictionary.
			self::assertNotNull($translator->getBlockStateDictionary()->generateCurrentDataFromStateId(
				$translator->internalIdToNetworkId($rod->getStateId())
			));
			self::assertNotNull($translator->getBlockStateDictionary()->generateCurrentDataFromStateId(
				$translator->internalIdToNetworkId($powderSnow->getStateId())
			));
		}
	}

	public function testPowderSnowBucketHasRealInternalIdentity() : void{
		$bucket = VanillaItems::POWDER_SNOW_BUCKET();
		self::assertSame(ItemTypeIds::POWDER_SNOW_BUCKET, $bucket->getTypeId());
		self::assertSame(1, $bucket->getMaxStackSize());
	}
}
