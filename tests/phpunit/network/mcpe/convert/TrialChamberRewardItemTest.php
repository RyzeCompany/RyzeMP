<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\item\Crossbow;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\loot\LootItemConverter;
use pocketmine\loot\LootStack;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\world\generator\vanilla\structure\TrialChamberContent;
use const pocketmine\BEDROCK_DATA_PATH;
use function is_file;

/** The reward item must survive disk conversion and have a modern client representation. */
final class TrialChamberRewardItemTest extends TestCase{
	/** @return array<string, array{int}> */
	public static function protocols() : array{
		return ["1.26.40" => [ProtocolInfo::PROTOCOL_1_26_40], "1.26.60" => [2225]];
	}

	#[DataProvider("protocols")]
	public function testModernRewardItemTranslations(int $protocolId) : void{
		if($protocolId === 2225 && !is_file(BEDROCK_DATA_PATH . "required_item_list-1.26.60.json")){
			self::markTestSkipped("Requires published 2225 data; tested in the cross-repository integration job");
		}
		TrialChamberContent::registerRewardItems();
		$converter = TypeConverter::getInstance($protocolId);
		foreach([
			ItemTypeNames::WIND_CHARGE,
			BlockTypeNames::HEAVY_CORE,
			ItemTypeNames::CROSSBOW,
			ItemTypeNames::FLOW_ARMOR_TRIM_SMITHING_TEMPLATE,
			ItemTypeNames::OMINOUS_BOTTLE,
			ItemTypeNames::FLOW_BANNER_PATTERN
		] as $id){
			$item = LootItemConverter::bedrockToItem($id, 0);
			self::assertNotNull($item, "$id is missing from RyzeMP");
			$network = $converter->getItemTranslator()->toNetworkIdQuiet($item);
			self::assertNotNull($network, "$id has no network representation on protocol $protocolId");
			self::assertSame($id, $converter->getItemTypeDictionary()->fromIntId($network[0]));
		}
		$crossbow = new Crossbow(new ItemIdentifier(ItemTypeIds::CROSSBOW), "Crossbow");
		$crossbow->setDamage(17);
		self::assertSame(17, $crossbow->getDamage());
	}

	public function testRewardMetadataSurvivesDiskSerialization() : void{
		TrialChamberContent::registerRewardItems();
		$stack = new LootStack(ItemTypeNames::OMINOUS_BOTTLE);
		$stack->ominousAmplifier = 3;
		$bottle = LootItemConverter::toItem($stack, "");
		self::assertNotNull($bottle);
		self::assertSame(3, Item::safeNbtDeserialize($bottle->nbtSerialize())->getNamedTag()->getInt("ominous_bottle_amplifier"));

		$crossbow = new Crossbow(new ItemIdentifier(ItemTypeIds::CROSSBOW), "Crossbow");
		$crossbow->setDamage(17);
		$restored = Item::safeNbtDeserialize($crossbow->nbtSerialize());
		self::assertInstanceOf(Crossbow::class, $restored);
		self::assertSame(17, $restored->getDamage());
	}
}
