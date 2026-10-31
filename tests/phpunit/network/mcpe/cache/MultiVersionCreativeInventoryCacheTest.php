<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\inventory\CreativeInventory;
use pocketmine\lang\Language;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\player\Player;
use pocketmine\Server;

final class CreativeInventoryCacheTestPlayer extends Player{
	private Server $testServer;
	private Language $testLanguage;

	public function initializeTestContext(Server $server, Language $language) : void{
		$this->testServer = $server;
		$this->testLanguage = $language;
	}

	public function __destruct(){}

	public function getServer() : Server{ return $this->testServer; }

	public function getLanguage() : Language{ return $this->testLanguage; }
}

final class MultiVersionCreativeInventoryCacheTest extends TestCase{

	/** @return array<string, array{int, string}> */
	public static function supportedGameVersions() : array{
		return [
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30, '1.26.30'],
			'26.40' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.40'],
			'26.44' => [ProtocolInfo::PROTOCOL_1_26_40, '1.26.44'],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45, '1.26.45'],
			'26.50' => [ProtocolInfo::PROTOCOL_1_26_50, '1.26.50'],
			'26.51' => [ProtocolInfo::PROTOCOL_1_26_51, '1.26.51'],
			'26.60' => [2225, '1.26.60']
		];
	}

	#[DataProvider('supportedGameVersions')]
	public function testEntireCreativeInventoryMatchesTheClientWireFormat(int $protocolId, string $gameVersion) : void{
		if($protocolId === 2225 && !is_file(\pocketmine\BEDROCK_DATA_PATH . "required_item_list-1.26.60.json")){
			$this->markTestSkipped("2225 BedrockData requires the staged integration workflow");
		}
		$server = $this->createMock(Server::class);
		$server->method('isLanguageForced')->willReturn(true);

		$player = (new \ReflectionClass(CreativeInventoryCacheTestPlayer::class))->newInstanceWithoutConstructor();
		$player->initializeTestContext($server, new Language('eng'));

		$session = $this->createMock(NetworkSession::class);
		$session->method('getPlayer')->willReturn($player);
		$session->method('getTypeConverter')->willReturn(TypeConverter::getInstance($protocolId));

		$packet = CreativeInventoryCache::getInstance($protocolId)->buildPacket(CreativeInventory::getInstance(), $session);
		$out = new ByteBufferWriter();
		$packet->encode($out, $protocolId, $gameVersion);
		$in = new ByteBufferReader($out->getData());

		self::assertSame(CreativeContentPacket::NETWORK_ID, VarInt::readUnsignedInt($in));
		$groupCount = VarInt::readUnsignedInt($in);
		self::assertGreaterThan(10, $groupCount);
		for($i = 0; $i < $groupCount; ++$i){
			$category = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? Byte::readUnsigned($in) : LE::readSignedInt($in);
			self::assertGreaterThanOrEqual(1, $category);
			self::assertLessThanOrEqual(4, $category);
			CommonTypes::getString($in);
			CommonTypes::getItemStackWithoutStackId($in, $protocolId);
		}

		$itemCount = VarInt::readUnsignedInt($in);
		self::assertGreaterThan(1000, $itemCount);
		for($i = 0; $i < $itemCount; ++$i){
			CommonTypes::readCreativeItemNetId($in);
			CommonTypes::getItemStackWithoutStackId($in, $protocolId);
			self::assertLessThan($groupCount, VarInt::readUnsignedInt($in));
		}
		self::assertSame(0, $in->getUnreadLength(), "Unexpected creative inventory bytes remain for $gameVersion");
	}
}
