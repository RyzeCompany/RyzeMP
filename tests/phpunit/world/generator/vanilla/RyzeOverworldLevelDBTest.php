<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Filesystem;

final class RyzeOverworldLevelDBTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		VanillaGenerators::register();
		$this->path = sys_get_temp_dir() . '/ryzemp-worldgen-' . bin2hex(random_bytes(12));
		LevelDB::generate($this->path, 'worldgen-test', WorldCreationOptions::create()->setGeneratorClass(RyzeOverworldGenerator::class)->setSeed(12345));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->path);
	}

	public function testPersistentChunksPreserveBlocksBiomesAndNBT() : void{
		$manager = new SimpleChunkManager(-64, 320);
		$chunk = new Chunk([], false);
		$manager->setChunk(-2, 3, $chunk);
		$generator = new RyzeOverworldGenerator(12345, '');
		$generator->generateChunk($manager, -2, 3);
		$chunk->setPopulated();
		$previousBlock = $chunk->getBlockStateId(6, 40, 9);
		$previousBiome = $chunk->getBiomeId(6, -48, 9);

		$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
		self::assertSame(VanillaGenerators::OVERWORLD_EXPERIMENTAL, $provider->getWorldData()->getGenerator());
		$provider->saveChunk(-2, 3, new ChunkData(
			$chunk->getSubChunks(),
			true,
			[CompoundTag::create()->setString('marker', 'entity-stays')],
			[CompoundTag::create()->setString('marker', 'tile-stays')]
		), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
		$provider->close();

		$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
		try{
			$data = $provider->loadChunk(-2, 3)?->getData();
			self::assertNotNull($data);
			self::assertTrue($data->isPopulated());
			self::assertSame('entity-stays', $data->getEntityNBT()[0]->getString('marker'));
			self::assertSame('tile-stays', $data->getTileNBT()[0]->getString('marker'));
			$loaded = new Chunk($data->getSubChunks(), $data->isPopulated());
			self::assertSame($previousBlock, $loaded->getBlockStateId(6, 40, 9));
			self::assertSame($previousBiome, $loaded->getBiomeId(6, -48, 9));
			$manager->setChunk(-2, 3, $loaded);
			$generator->generateChunk($manager, -2, 3);
			self::assertSame($previousBlock, $loaded->getBlockStateId(6, 40, 9));
			self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $loaded->getBlockStateId(0, -64, 0));
		}finally{
			$provider->close();
		}
	}
}
