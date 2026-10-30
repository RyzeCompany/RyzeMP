<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;

final class Bedrock2225DataTest extends TestCase{

	private function requireStagedAssets() : void{
		if(!is_file(\pocketmine\BEDROCK_DATA_PATH . "required_item_list-1.26.60.json") ||
			!is_file(\pocketmine\BEDROCK_DATA_PATH . "block_state_meta_map-1.26.60.json")){
			$this->markTestSkipped("2225 BedrockData is not installed; use ci-2225-integration.yml");
		}
	}

	public function testCompleteOfficialItemRegistry2225() : void{
		$this->requireStagedAssets();
		$dictionary = ItemTypeDictionaryFromDataHelper::loadFromProtocolId(2225);
		self::assertCount(2080, $dictionary->getEntries());
		foreach([
			"minecraft:disc_press",
			"minecraft:frostbite_spawn_egg",
			"minecraft:ice_crystal",
			"minecraft:icicle",
		] as $item){
			$id = $dictionary->fromStringId($item);
			self::assertSame($item, $dictionary->fromIntId($id));
		}
	}

	public function testBlockPaletteAndMetadataArePaired2225() : void{
		$this->requireStagedAssets();
		$translator = BlockTranslator::loadFromProtocolId(2225);
		self::assertCount(23960, $translator->getBlockStateDictionary()->getStates());
		$stone = \pocketmine\block\VanillaBlocks::STONE()->getStateId();
		$runtimeId = $translator->internalIdToNetworkId($stone);
		self::assertNotNull($translator->getBlockStateDictionary()->generateDataFromStateId($runtimeId));
	}

	public function testLegacy2193StillLoadsIndependently() : void{
		$translator = BlockTranslator::loadFromProtocolId(2193);
		self::assertCount(22091, $translator->getBlockStateDictionary()->getStates());
	}
}
