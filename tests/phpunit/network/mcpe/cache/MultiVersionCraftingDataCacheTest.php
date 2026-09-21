<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\timings\Timings;

final class MultiVersionCraftingDataCacheTest extends TestCase{

	private static CraftingManager $craftingManager;

	public static function setUpBeforeClass() : void{
		Timings::init();
		self::$craftingManager = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
	}

	/** @return array<string, array{int, string}> */
	public static function supportedGameVersions() : array{
		return [
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30, '1.26.30'],
			'26.40' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.40'],
			'26.44' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.44'],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45, '1.26.45'],
			'26.50' => [ProtocolInfo::PROTOCOL_1_26_50, '1.26.50'],
			'26.51' => [ProtocolInfo::PROTOCOL_1_26_51, '1.26.51']
		];
	}

	#[DataProvider('supportedGameVersions')]
	public function testEveryRecipeAndPotionEncodesAndDecodesForItsClient(int $protocolId, string $gameVersion) : void{
		$packet = CraftingDataCache::getInstance($protocolId)->getCache(self::$craftingManager);
		self::assertCount(2258, $packet->recipesWithTypeIds);
		self::assertCount(195, $packet->potionTypeRecipes);

		$out = new ByteBufferWriter();
		$packet->encode($out, $protocolId, $gameVersion);
		$decoded = new CraftingDataPacket();
		$decoded->decode(new ByteBufferReader($out->getData()), $protocolId, $gameVersion);

		self::assertCount(2258, $decoded->recipesWithTypeIds);
		self::assertCount(195, $decoded->potionTypeRecipes);
		self::assertSame($packet->cleanRecipes, $decoded->cleanRecipes);
	}
}
