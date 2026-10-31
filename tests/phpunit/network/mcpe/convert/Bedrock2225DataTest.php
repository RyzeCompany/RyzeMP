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
	public function testFull2225TypeConverterBootstraps() : void{
		$this->requireStagedAssets();
		// Builds ItemIdMetaDowngrader, the canonical hash translator,
		// and per-version ID registries together; catching missing schema
		// routing that a raw JSON/NBT load does not exercise.
		$converter = TypeConverter::getInstance(2225);
		self::assertSame(2225, $converter->getProtocolId());
		self::assertCount(2080, $converter->getItemTypeDictionary()->getEntries());
		self::assertCount(23960, $converter->getBlockTranslator()->getBlockStateDictionary()->getStates());
	}

	public function testChorusPlantMapsToItsRealNetworkStateAndRetainsAllConnectionVariants() : void{
		$this->requireStagedAssets();
		$converter = TypeConverter::getInstance(2225);
		$dictionary = $converter->getBlockTranslator()->getBlockStateDictionary();
		[, , $runtimeId] = $converter->getItemTranslator()->toNetworkId(\pocketmine\block\VanillaBlocks::CHORUS_PLANT()->asItem());
		self::assertNotNull($runtimeId);
		self::assertSame("minecraft:chorus_plant", $dictionary->generateDataFromStateId($runtimeId)?->getName());
		$variants = 0;
		foreach($dictionary->getStates() as $id => $state){
			if($state->getStateName() !== "minecraft:chorus_plant"){continue;}
			++$variants;
			self::assertSame([], $state->generateCurrentStateData()->getStates());
			self::assertCount(6, $state->generateStateData()->getStates());
			self::assertSame($id, $dictionary->lookupStateIdFromData($state->generateStateData()));
		}
		self::assertSame(64, $variants);
	}

}
