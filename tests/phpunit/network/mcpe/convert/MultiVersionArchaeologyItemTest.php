<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotterySherd;
use pocketmine\item\PotterySherdType;
use pocketmine\loot\LootItemConverter;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\generator\vanilla\feature\ArchaeologyContent;
use const pocketmine\BEDROCK_DATA_PATH;
use function is_file;
use function strtolower;

/** Pottery sherd runtime IDs must be translated separately for each accepted Bedrock protocol. */
final class MultiVersionArchaeologyItemTest extends TestCase{

	/** @return array<string, array{int}> */
	public static function protocols() : array{
		return [
			"legacy-589" => [589],
			"bedrock-1.26.40" => [ProtocolInfo::PROTOCOL_1_26_40],
			"bedrock-1.26.60" => [2225]
		];
	}

	#[DataProvider("protocols")]
	public function testArchaeologySherdNetworkIdRemainsProtocolSpecific(int $protocolId) : void{
		if($protocolId === 2225 && !is_file(BEDROCK_DATA_PATH . "required_item_list-1.26.60.json")){
			self::markTestSkipped("Requires published 2225 data; tested in the cross-repository integration job");
		}
		ArchaeologyContent::registerSherds();
		$converter = TypeConverter::getInstance($protocolId);
		foreach([PotterySherdType::ANGLER, PotterySherdType::ARCHER, PotterySherdType::FLOW, PotterySherdType::SNORT] as $type){
			$item = (new PotterySherd(new ItemIdentifier(ItemTypeIds::POTTERY_SHERD), "Pottery Sherd"))->setSherdType($type);
			$encoded = $converter->getItemTranslator()->toNetworkIdQuiet($item);
			if($encoded === null){
				//FLOW (and some later sherds) did not exist on Bedrock 1.20.0.
				//RyzeMP represents unsupported items as INFO_UPDATE, without
				//changing the real item stored in LevelDB/server inventory.
				self::assertSame(589, $protocolId, "Modern protocol $protocolId must support all these sherds");
				[$fallbackId] = $converter->getItemTranslator()->toNetworkId(VanillaBlocks::INFO_UPDATE()->asItem());
				self::assertSame($fallbackId, $converter->coreItemStackToNet($item)->getId());
				continue;
			}
			self::assertSame("minecraft:" . strtolower($type->name) . "_pottery_sherd",
				$converter->getItemTypeDictionary()->fromIntId($encoded[0]),
				"Incorrect client item dictionary for protocol $protocolId");
		}
	}

	#[DataProvider("protocols")]
	public function testArchaeologyLootItemsHaveNetworkRepresentations(int $protocolId) : void{
		if($protocolId === 2225 && !is_file(BEDROCK_DATA_PATH . "required_item_list-1.26.60.json")){
			self::markTestSkipped("Requires published 2225 data; tested in the cross-repository integration job");
		}
		ArchaeologyContent::registerLootItems();
		$converter = TypeConverter::getInstance($protocolId);
		foreach([ItemTypeNames::LEAD, BlockTypeNames::SNIFFER_EGG] as $id){
			$item = LootItemConverter::bedrockToItem($id, 0);
			self::assertNotNull($item);
			$network = $converter->getItemTranslator()->toNetworkIdQuiet($item);
			self::assertNotNull($network, "$id should be visible on protocol $protocolId");
			self::assertSame($id, $converter->getItemTypeDictionary()->fromIntId($network[0]));
		}
	}
}
