<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use const pocketmine\BEDROCK_DATA_PATH;

final class BlockDataRegressionTest extends TestCase{
	public function testEntryPreservesNbtScalarTypes() : void{
		$states = ['upside_down_bit' => new ByteTag(1), 'weirdo_direction' => new IntTag(3)];
		$entry = new BlockStateDictionaryEntry('minecraft:oak_stairs', $states, 7, null);
		self::assertEquals($states, $entry->generateCurrentStateData()->getStates());
		self::assertInstanceOf(ByteTag::class, $entry->generateCurrentStateData()->getStates()['upside_down_bit']);
	}

	public function testModernNetworkIdsAreBidirectional() : void{
		$dictionary = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51)->getBlockStateDictionary();
		self::assertCount(22091, $dictionary->getStates());
		self::assertSame(-2144268767, $dictionary->lookupStateIdFromData(BlockStateData::current('minecraft:stone', [])));
		foreach($dictionary->getStates() as $id => $entry){
			self::assertSame($id, $dictionary->lookupStateIdFromData($entry->generateStateData()));
			self::assertEquals($entry->generateStateData(), $dictionary->generateDataFromStateId($id));
		}
	}

	public function testLegacyMetadataHasExactlyOneValuePerState() : void{
		$palette = file_get_contents(BEDROCK_DATA_PATH . 'canonical_block_states-1.26.30.nbt');
		$metadata = file_get_contents(BEDROCK_DATA_PATH . 'block_state_meta_map-1.26.30.json');
		self::assertCount(16913, BlockStateDictionary::loadPaletteFromString($palette));
		self::assertCount(16913, json_decode($metadata, true, flags: JSON_THROW_ON_ERROR));
		BlockStateDictionary::loadFromString($palette, $metadata);
		$this->expectException(\InvalidArgumentException::class);
		BlockStateDictionary::loadFromString($palette, substr($metadata, 0, strrpos($metadata, ']')) . ', 0]');
	}

	public function testAdventureRestrictionsSurviveNetworkConversion() : void{
		foreach([ProtocolInfo::PROTOCOL_1_26_30, ProtocolInfo::PROTOCOL_1_26_51] as $protocol){
			$converter = TypeConverter::getInstance($protocol);
			$item = VanillaBlocks::STONE()->asItem();
			$item->setCanPlaceOn(['minecraft:dirt']);
			$item->setCanDestroy(['minecraft:glass']);
			$before = clone $item->getNamedTag();
			$network = $converter->coreItemStackToNet($item);
			$id = $converter->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::STONE()->getStateId());
			self::assertSame($id, $network->getBlockRuntimeId());
			$decoded = $converter->netItemStackToCore($network);
			self::assertSame($item->getCanPlaceOn(), $decoded->getCanPlaceOn());
			self::assertSame($item->getCanDestroy(), $decoded->getCanDestroy());
			self::assertTrue($before->equals($item->getNamedTag()));
		}
	}
}
