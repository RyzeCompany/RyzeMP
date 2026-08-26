<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\data;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\WorldDataVersions;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\Binary;
use pocketmine\world\format\io\exception\UnsupportedWorldFormatException;
use function bin2hex;
use function file_exists;
use function file_put_contents;
use function random_bytes;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

final class MultiVersionBedrockWorldDataTest extends TestCase{
	private string $dataPath;

	protected function setUp() : void{
		$this->dataPath = sys_get_temp_dir() . "/pm-multiversion-world-" . bin2hex(random_bytes(8)) . ".dat";
	}

	protected function tearDown() : void{
		if(file_exists($this->dataPath)){
			unlink($this->dataPath);
		}
	}

	private function writeWorldData(int $protocolVersion) : void{
		$worldData = CompoundTag::create()
			->setInt("StorageVersion", WorldDataVersions::STORAGE)
			->setInt("NetworkVersion", $protocolVersion)
			->setString("LevelName", "FRANK")
			->setString("generatorName", "default")
			->setString("generatorOptions", "")
			->setLong("RandomSeed", 1)
			->setInt("SpawnX", 0)
			->setInt("SpawnY", 83)
			->setInt("SpawnZ", 0);
		$payload = (new LittleEndianNbtSerializer())->write(new TreeRoot($worldData));
		file_put_contents($this->dataPath, Binary::writeLInt(WorldDataVersions::STORAGE) . Binary::writeLInt(strlen($payload)) . $payload);
	}

	/** @return array<string, array{int}> */
	public static function supportedWorldProtocols() : array{
		return [
			"Bedrock 1.26.0" => [ProtocolInfo::PROTOCOL_1_26_0],
			"Bedrock 1.26.30" => [ProtocolInfo::PROTOCOL_1_26_30],
			"Bedrock 1.26.40 and 1.26.44" => [ProtocolInfo::PROTOCOL_1_26_40],
			"Bedrock 1.26.45" => [ProtocolInfo::PROTOCOL_1_26_45]
		];
	}

	#[DataProvider("supportedWorldProtocols")]
	public function testSupportedBedrockWorldVersionsLoad(int $protocolVersion) : void{
		$this->writeWorldData($protocolVersion);

		$worldData = new BedrockWorldData($this->dataPath);

		self::assertSame("FRANK", $worldData->getName());
		self::assertSame($protocolVersion, $worldData->getCompoundTag()->getInt("NetworkVersion"));
	}

	public function testFutureWorldVersionsRemainUnsupported() : void{
		$this->writeWorldData(ProtocolInfo::PROTOCOL_1_26_45 + 1);

		$this->expectException(UnsupportedWorldFormatException::class);
		$this->expectExceptionMessage("LevelDB world protocol version 2170 is currently unsupported");
		new BedrockWorldData($this->dataPath);
	}

	public function testImportedWorldIsSavedWithCurrentBedrockMetadata() : void{
		$this->writeWorldData(ProtocolInfo::PROTOCOL_1_26_40);

		$worldData = new BedrockWorldData($this->dataPath);
		$worldData->save();
		$reopenedWorldData = new BedrockWorldData($this->dataPath);

		self::assertSame(ProtocolInfo::PROTOCOL_1_26_45, $reopenedWorldData->getCompoundTag()->getInt("NetworkVersion"));
		$lastOpenedWithVersion = $reopenedWorldData->getCompoundTag()->getListTag("lastOpenedWithVersion", IntTag::class);
		self::assertNotNull($lastOpenedWithVersion);
		self::assertSame([1, 26, 45, 0, 0], $lastOpenedWithVersion->getAllValues());
		self::assertSame("FRANK", $reopenedWorldData->getName());
	}
}
