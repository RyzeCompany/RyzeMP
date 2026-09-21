<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

final class Bedrock12651BlockStateDiagnosticTest extends TestCase{

	public function testCanonicalDictionaryIsSelfConsistentForAffectedStates() : void{
		$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
		$dictionary = $translator->getBlockStateDictionary();

		$checked = 0;
		foreach($dictionary->getStates() as $stateId => $entry){
			$name = $entry->getStateName();
			if($name !== 'minecraft:black_candle' && $name !== 'minecraft:cobblestone_wall' && $name !== 'minecraft:waxed_copper_trapdoor' && $name !== 'minecraft:normal_stone_stairs' && $name !== 'minecraft:stone_stairs' && $name !== 'minecraft:mossy_cobblestone_stairs' && $name !== 'minecraft:trapdoor'){
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

	public function test12651CanonicalStateMatchesSerializerProperties() : void{
		$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
		$dictionary = $translator->getBlockStateDictionary();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$data = $serializer->serialize(VanillaBlocks::DYED_CANDLE()->setColor(DyeColor::BLACK)->getStateId());

		$candidates = [];
		foreach($dictionary->getStates() as $stateId => $entry){
			if($entry->getStateName() === 'minecraft:black_candle'){
				$candidates[$stateId] = $entry->generateStateData()->toNbt();
			}
		}
		self::assertNotEmpty($candidates);

		$serializerRaw = BlockStateDictionaryEntry::encodeStateProperties($data->getStates());
		$matchingRawStateIds = [];
		foreach($dictionary->getStates() as $stateId => $entry){
			if($entry->getStateName() === 'minecraft:black_candle' && $entry->getRawStateProperties() === $serializerRaw){
				$matchingRawStateIds[] = $stateId;
			}
		}
		self::assertNotEmpty(
			$matchingRawStateIds,
			'Serializer raw properties do not match any canonical 1.26.51 black_candle state. Serializer=' . bin2hex($serializerRaw) .
			'; candidates=' . json_encode(array_map(fn($stateId) => [
				'id' => $stateId,
				'raw' => bin2hex($dictionary->getStates()[$stateId]->getRawStateProperties()),
			], array_keys($candidates)), JSON_THROW_ON_ERROR)
		);
		self::assertNotEmpty($matchingRawStateIds, 'Serializer state must resolve to a canonical 1.26.51 runtime ID');
	}

	public function test12651TrapdoorRawSerializerDiagnostic() : void{
		$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
		$dictionary = $translator->getBlockStateDictionary();
		// This is the exact BlockStateData reported by ItemSerializer in the 1.26.51 login failure.
		// Construct it directly so this diagnostic does not depend on generated VanillaBlocks methods.
		$data = BlockStateData::current('minecraft:trapdoor', [
			'direction' => new IntTag(3),
			'upside_down_bit' => new ByteTag(0),
			'open_bit' => new ByteTag(0),
		]);
		self::assertSame('minecraft:trapdoor', $data->getName());

		$serializerRaw = BlockStateDictionaryEntry::encodeStateProperties($data->getStates());
		$candidates = [];
		$rawMatches = [];
		foreach($dictionary->getStates() as $stateId => $entry){
			if($entry->getStateName() !== 'minecraft:trapdoor'){
				continue;
			}
			$current = $entry->generateCurrentStateData();
			$record = [
				'id' => $stateId,
				'meta' => $entry->getMeta(),
				'raw' => bin2hex($entry->getRawStateProperties()),
				'states' => array_map(
					fn($tag) => get_class($tag) . ':' . (string) $tag->getValue(),
					$current->getStates()
				),
			];
			$candidates[] = $record;
			if($entry->getRawStateProperties() === $serializerRaw){
				$rawMatches[] = $stateId;
			}
		}

		self::assertNotEmpty(
			$rawMatches,
			"1.26.51 trapdoor RAW mapping mismatch.\n" .
			"Serializer NBT: " . $data->toNbt() . "\n" .
			"Serializer raw: " . bin2hex($serializerRaw) . "\n" .
			"Canonical candidates: " . json_encode($candidates, JSON_THROW_ON_ERROR)
		);
	}

	public function test12651KnownFailingSerializerStatesResolve() : void{
		$dictionary = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51)->getBlockStateDictionary();

		$states = [
			BlockStateData::current('minecraft:waxed_copper_trapdoor', [
				'direction' => new IntTag(3),
				'upside_down_bit' => new ByteTag(0),
				'open_bit' => new ByteTag(0),
			]),
			BlockStateData::current('minecraft:trapdoor', [
				'direction' => new IntTag(3),
				'upside_down_bit' => new ByteTag(0),
				'open_bit' => new ByteTag(0),
			]),
			BlockStateData::current('minecraft:normal_stone_stairs', [
				'upside_down_bit' => new ByteTag(0),
				'weirdo_direction' => new IntTag(3),
			]),
			BlockStateData::current('minecraft:stone_stairs', [
				'upside_down_bit' => new ByteTag(0),
				'weirdo_direction' => new IntTag(3),
			]),
			BlockStateData::current('minecraft:mossy_cobblestone_stairs', [
				'upside_down_bit' => new ByteTag(0),
				'weirdo_direction' => new IntTag(3),
			]),
		];

		foreach($states as $data){
			$lookup = $dictionary->lookupStateIdFromData($data);
			if($lookup === null && $data->getName() === 'minecraft:waxed_copper_trapdoor'){
				$candidates = [];
				foreach($dictionary->getStates() as $stateId => $entry){
					if($entry->getStateName() === $data->getName()){
						$candidates[$stateId] = [
							'states' => array_map(
								fn($tag) => get_class($tag) . ':' . $tag->getValue(),
								$entry->generateCurrentStateData()->getStates()
							),
							'raw' => bin2hex($entry->getRawStateProperties()),
						];
					}
				}
				self::fail('Waxed copper trapdoor candidates in loaded 1.26.51 dictionary: ' . json_encode($candidates, JSON_THROW_ON_ERROR));
			}
			self::assertNotNull(
				$lookup,
				'Known 1.26.51 serializer state is unmapped: ' . $data->toNbt()
			);
		}
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
			'minecraft:stone_stairs' => VanillaBlocks::STONE_STAIRS(),
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
