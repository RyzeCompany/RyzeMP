<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\world\format\Chunk;

final class Legacy419TypeConverterTest extends TestCase{
	public function testOriginalPaletteLoadsWithoutInventingBlockMetadata() : void{
		$converter = TypeConverter::getInstance(419);
		$dictionary = $converter->getBlockTranslator()->getBlockStateDictionary();
		self::assertCount(6611, $dictionary->getStates());
		self::assertSame(355, $converter->getItemTypeDictionary()->fromStringId('minecraft:shield'));
		foreach($dictionary->getStates() as $id => $entry){
			$mapped = $dictionary->lookupStateIdFromData($entry->generateStateData());
			self::assertNotNull($mapped);
			self::assertTrue($entry->generateCurrentStateData()->equals($dictionary->generateCurrentDataFromStateId($mapped)), "Invalid state projection $id");
			self::assertNull($dictionary->getMetaFromStateId($id));
		}
	}

	public function testLegacyInventoryItemsRoundTripThroughNativeSlots() : void{
		$converter = TypeConverter::getInstance(419);
		foreach([VanillaBlocks::STONE()->asItem(), VanillaBlocks::GRANITE()->asItem(), VanillaBlocks::OAK_LOG()->asItem(), VanillaItems::DIAMOND_SWORD()] as $item){
			$item->setCount(1)->setCustomName('419 test');
			$net = $converter->coreItemStackToNet($item);
			$out = new ByteBufferWriter();
			CommonTypes::putItemStackWithoutStackId($out, $net, 419);
			$in = new ByteBufferReader($out->getData());
			$decoded = CommonTypes::getItemStackWithoutStackId($in, 419);
			self::assertSame(0, $in->getUnreadLength());
			$restored = $converter->netItemStackToCore($decoded);
			self::assertTrue($item->equalsExact($restored), $item->getName());
		}
	}

	public function testOldChunksUseReal419RuntimeIdsWithoutChangingStoredTerrain() : void{
		$converter = TypeConverter::getInstance(419);
		$chunk = new Chunk([], false);
		$stone = VanillaBlocks::STONE()->getStateId();
		$chunk->setBlockStateId(0, 0, 0, $stone);
		$chunk->setBlockStateId(0, -64, 0, $stone);
		$chunk->setBlockStateId(0, 319, 0, $stone);
		$payload = ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, $converter);
		self::assertStringStartsWith("\x08\x01", $payload);
		self::assertSame(1, ChunkSerializer::getSubChunkCount($chunk, DimensionIds::OVERWORLD, 419));
		self::assertSame($stone, $chunk->getBlockStateId(0, -64, 0));
		self::assertSame($stone, $chunk->getBlockStateId(0, 319, 0));
		$id = $converter->getBlockTranslator()->internalIdToNetworkId($stone);
		self::assertSame('minecraft:stone', $converter->getBlockTranslator()->getBlockStateDictionary()->generateDataFromStateId($id)->getName());
	}
}
