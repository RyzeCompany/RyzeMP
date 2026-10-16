<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Filesystem;

final class LevelDBDimensionTest extends TestCase{

	private string $path;

	protected function setUp() : void{
		$this->path = sys_get_temp_dir() . "/ryzemp-dim-" . bin2hex(random_bytes(12));
		LevelDB::generate($this->path, "dimensions", WorldCreationOptions::create()->setSeed(1));
	}

	protected function tearDown() : void{
		(new Filesystem())->remove($this->path);
	}

	private function chunk(int $dimension) : ChunkData{
		$subChunks = [];
		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
			$subChunks[$y] = new SubChunk(Block::EMPTY_STATE_ID, [], new PalettedBlockArray(100 + $y));
		}
		$subChunks[0]->setBlockStateId(1, 2, 3, ($dimension === 1 ? VanillaBlocks::NETHERRACK() : VanillaBlocks::END_STONE())->getStateId());
		return new ChunkData($subChunks, true,
			[CompoundTag::create()->setString("marker", "entity-$dimension")],
			[CompoundTag::create()->setString("marker", "tile-$dimension")]);
	}

	public function testNativeRoundtripAndCrossDimensionIsolation() : void{
		$overworldVersion = null;
		foreach([0, 1, 2] as $dimension){
			$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
			// Explicitly create each source in this synthetic multidimension database.
			if($dimension !== 0){
				$provider->getDatabase()->put(LevelDB::chunkIndex(-2, 3, $dimension) . ChunkDataKey::OLD_VERSION, chr(ChunkVersion::v1_0_0));
			}
			$provider->configureDimension($dimension, $dimension);
			$provider->saveChunk(-2, 3, $this->chunk($dimension), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
			if($dimension === 0){ $overworldVersion = $provider->getDatabase()->get(LevelDB::chunkIndex(-2, 3) . ChunkDataKey::NEW_VERSION); }
			$provider->close();
		}
		foreach([1, 2, 0] as $dimension){
			$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
			$provider->configureDimension($dimension);
			self::assertSame($dimension, $provider->getStorageDimension());
			self::assertSame(1, $provider->calculateChunkCount()); // old + new version keys count once
			$data = $provider->loadChunk(-2, 3)?->getData();
			self::assertNotNull($data);
			self::assertSame("entity-$dimension", $data->getEntityNBT()[0]->getString("marker"));
			self::assertSame("tile-$dimension", $data->getTileNBT()[0]->getString("marker"));
			self::assertSame(($dimension === 1 ? VanillaBlocks::NETHERRACK() : VanillaBlocks::END_STONE())->getStateId(), $data->getSubChunks()[0]->getBlockStateId(1, 2, 3));
			self::assertSame(100, $data->getSubChunks()[0]->getBiomeArray()->get(0, 0, 0));
			self::assertSame($dimension === 1 ? 107 : ($dimension === 2 ? 115 : 119), $data->getSubChunks()[19]->getBiomeArray()->get(0, 0, 0));
			self::assertCount(1, iterator_to_array($provider->getAllChunks(), false));
			self::assertSame($overworldVersion, $provider->getDatabase()->get(LevelDB::chunkIndex(-2, 3) . ChunkDataKey::NEW_VERSION));
			$provider->close();
		}
	}

	public function testSeparateNetherKeepsOverworldStorageAndCannotSwitchAfterUse() : void{
		$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
		$provider->saveChunk(0, 0, $this->chunk(1), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
		$provider->close();
		$provider = new LevelDB($this->path, $this->createMock(\Logger::class));
		$provider->configureDimension(1);
		self::assertSame(0, $provider->getStorageDimension());
		self::assertNotNull($provider->loadChunk(0, 0));
		try{
			$provider->configureDimension(2);
			self::fail("Storage changed after use");
		}catch(\LogicException){
			self::assertSame(0, $provider->getStorageDimension());
		}finally{ $provider->close(); }
	}
}
