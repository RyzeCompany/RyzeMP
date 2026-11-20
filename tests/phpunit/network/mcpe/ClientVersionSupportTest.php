<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\ItemTypeDictionaryFromDataHelper;
use pocketmine\network\mcpe\protocol\ProtocolInfo;

final class ClientVersionSupportTest extends TestCase{
	public function testTheLoginAllowlistMatchesTheProtocolDependency() : void{
		self::assertSame(ProtocolInfo::ACCEPTED_PROTOCOL, array_keys(ClientVersionSupport::getVersions()));
		self::assertCount(32, ClientVersionSupport::getVersions());
		self::assertFalse(ClientVersionSupport::isSupported(0));
	}

	/** @return array<string, array{int}> */
	public static function protocols() : array{
		$cases = [];
		foreach(ClientVersionSupport::getVersions() as $id => $version){
			$cases[$version] = [$id];
		}
		return $cases;
	}

	#[DataProvider('protocols')]
	public function testEveryAcceptedProtocolHasWorkingBlockAndItemPalettes(int $protocolId) : void{
		$blocks = BlockTranslator::loadFromProtocolId($protocolId);
		self::assertIsInt($blocks->internalIdToNetworkId(VanillaBlocks::STONE()->getStateId()));
		self::assertNotEmpty(ItemTypeDictionaryFromDataHelper::loadFromProtocolId($protocolId)->getEntries());
	}
}
