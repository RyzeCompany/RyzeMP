<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Filesystem;

final class ImportedBlockStateTest extends TestCase{

	public function testAllReportedBlocksKeepTheirBaseStates() : void{
		foreach([VanillaBlocks::SPRUCE_STAIRS(), VanillaBlocks::STONE_BRICK_STAIRS(), VanillaBlocks::COBBLESTONE_STAIRS(), VanillaBlocks::BRICK_STAIRS()] as $stair){
			foreach([2, 3, 4, 5] as $facing){
				foreach([false, true] as $upsideDown){
					$block = $stair->setFacing($facing)->setUpsideDown($upsideDown);
					foreach(["none", "inner_left", "inner_right", "outer_left", "outer_right"] as $corner){
						$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
						$states = $data->getStates();
						$states["minecraft:corner"] = new StringTag($corner);
						$imported = new BlockStateData($data->getName(), $states, $data->getVersion());
						$normalized = ImportedBlockStateNormalizer::normalize($imported);
						self::assertSame($block->getStateId(), GlobalBlockStateHandlers::getDeserializer()->deserialize($normalized));
						self::assertArrayHasKey("minecraft:corner", $imported->getStates());
					}
				}
			}
		}
		foreach([VanillaBlocks::OAK_FENCE(), VanillaBlocks::GLASS_PANE(), VanillaBlocks::STAINED_GLASS_PANE(), VanillaBlocks::IRON_BARS()] as $block){
			$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
			for($mask = 0; $mask < 16; ++$mask){
				$states = $data->getStates();
				foreach(["north", "south", "west", "east"] as $bit => $face){
					$states["minecraft:connection_" . $face] = new ByteTag(($mask >> $bit) & 1);
				}
				self::assertSame($block->getStateId(), GlobalBlockStateHandlers::getDeserializer()->deserialize(ImportedBlockStateNormalizer::normalize(new BlockStateData($data->getName(), $states, $data->getVersion()))));
			}
		}
	}

	public function testUnrelatedUnknownOrInvalidPropertiesAreNotSilentlyRemoved() : void{
		foreach([
			BlockStateData::current("minecraft:stone", ["minecraft:corner" => new StringTag("none")]),
			BlockStateData::current("custom:oak_fence", ["minecraft:connection_north" => new ByteTag(1)]),
			BlockStateData::current("minecraft:oak_fence", ["minecraft:connection_north" => new ByteTag(2)]),
			BlockStateData::current("minecraft:spruce_stairs", ["minecraft:corner" => new StringTag("invalid")]),
		] as $data){
			self::assertSame($data, ImportedBlockStateNormalizer::normalize($data));
		}
		$data = GlobalBlockStateHandlers::getSerializer()->serialize(VanillaBlocks::SPRUCE_STAIRS()->getStateId());
		$states = $data->getStates();
		$states["minecraft:corner"] = new StringTag("none");
		$states["other:unknown"] = new ByteTag(1);
		$this->expectException(BlockStateDeserializeException::class);
		GlobalBlockStateHandlers::getDeserializer()->deserialize(ImportedBlockStateNormalizer::normalize(new BlockStateData($data->getName(), $states, $data->getVersion())));
	}

	public function testImportedCurrentAndLegacyChunksAreSavedOnceWithoutLosingNbt() : void{
		foreach([false, true] as $legacy){
			$path = sys_get_temp_dir() . "/ryzemp-import-" . bin2hex(random_bytes(10));
			LevelDB::generate($path, "import", WorldCreationOptions::create());
			$provider = new LevelDB($path, $this->createMock(\Logger::class));
			try{
				$subChunks = [];
				for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
					$subChunks[$y] = new SubChunk(Block::EMPTY_STATE_ID, [], new PalettedBlockArray(8));
				}
				$entities = [CompoundTag::create()->setString("marker", "entity")];
				$tiles = [CompoundTag::create()->setString("marker", "tile")];
				$provider->saveChunk(-1, -1, new ChunkData($subChunks, true, $entities, $tiles), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
				$index = LevelDB::chunkIndex(-1, -1);
				$block = VanillaBlocks::SPRUCE_STAIRS()->setFacing(4)->setUpsideDown(true);
				$nbt = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId())->toNbt();
				$nbt->getCompoundTag("states")->setString("minecraft:corner", "inner_right");
				$nbt->setLong("PMMPDataVersion", 2);
				$provider->getDatabase()->put($index . ChunkDataKey::SUBCHUNK . "\x00", "\x08\x01\x00" . (new LittleEndianNbtSerializer())->write(new TreeRoot($nbt)));
				if($legacy){
					$provider->getDatabase()->put($index . ChunkDataKey::NEW_VERSION, chr(ChunkVersion::v1_0_0));
					$provider->getDatabase()->delete($index . ChunkDataKey::HEIGHTMAP_AND_3D_BIOMES);
					$provider->getDatabase()->put($index . ChunkDataKey::HEIGHTMAP_AND_2D_BIOMES, str_repeat("\x00", 512) . str_repeat("\x08", 256));
				}
				$loaded = $provider->loadChunk(-1, -1);
				self::assertTrue($loaded->isUpgraded());
				self::assertSame($block->getStateId(), $loaded->getData()->getSubChunks()[0]->getBlockStateId(0, 0, 0));
				$provider->saveChunk(-1, -1, $loaded->getData(), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
				$provider->close();
				$provider = new LevelDB($path, $this->createMock(\Logger::class));
				$again = $provider->loadChunk(-1, -1);
				self::assertFalse($again->isUpgraded());
				self::assertSame($block->getStateId(), $again->getData()->getSubChunks()[0]->getBlockStateId(15, 15, 15));
				self::assertSame("entity", $again->getData()->getEntityNBT()[0]->getString("marker"));
				self::assertSame("tile", $again->getData()->getTileNBT()[0]->getString("marker"));
			}finally{
				$provider->close();
				(new Filesystem())->remove($path);
			}
		}
	}
}
