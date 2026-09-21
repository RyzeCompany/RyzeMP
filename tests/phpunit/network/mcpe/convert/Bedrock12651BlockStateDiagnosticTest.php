<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

final class Bedrock12651BlockStateDiagnosticTest extends TestCase{

	public function testCanonicalDictionaryIsSelfConsistentForAffectedStates() : void{
		$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
		$dictionary = $translator->getBlockStateDictionary();

		$checked = 0;
		foreach($dictionary->getStates() as $stateId => $entry){
			$name = $entry->getStateName();
			if($name !== 'minecraft:black_candle' && $name !== 'minecraft:cobblestone_wall'){
				continue;
			}

			$data = $entry->generateCurrentStateData();
			$lookup = $dictionary->lookupStateIdFromData($data);
			self::assertSame(
				$stateId,
				$lookup,
				'Dictionary self-lookup failed for runtime ID ' . $stateId . ': ' . $data->toNbt()
			);
			$checked++;
		}

		self::assertGreaterThan(0, $checked, 'Neither affected block was found in the 1.26.51 dictionary');
	}

	public function testTypeConverterItemTranslatorUses12651Dictionary() : void{
		$typeConverter = TypeConverter::getInstance(ProtocolInfo::PROTOCOL_1_26_51);
		$itemTranslator = $typeConverter->getItemTranslator();

		foreach([
			'minecraft:black_candle' => VanillaBlocks::DYED_CANDLE()->setColor(DyeColor::BLACK)->asItem(),
			'minecraft:cobblestone_wall' => VanillaBlocks::COBBLESTONE_WALL()->asItem(),
		] as $name => $item){
			$result = $itemTranslator->toNetworkId($item);
			self::assertNotNull($result[2], 'No network runtime ID was produced for ' . $name);
		}
	}

	public function testPocketMineSerializerOutputMatches12651Dictionary() : void{
		$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
		$dictionary = $translator->getBlockStateDictionary();
		$serializer = GlobalBlockStateHandlers::getSerializer();

		foreach([
			'minecraft:black_candle' => VanillaBlocks::DYED_CANDLE()->setColor(DyeColor::BLACK),
			'minecraft:cobblestone_wall' => VanillaBlocks::COBBLESTONE_WALL(),
		] as $name => $block){
			$data = $serializer->serialize($block->getStateId());
			$lookup = $dictionary->lookupStateIdFromData($data);
			self::assertNotNull(
				$lookup,
				'Serializer produced an unmapped ' . $name . ': ' . $data->toNbt()
			);
		}
	}
}
