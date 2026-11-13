<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Filesystem;

/** Exercises actual LevelDB + level.dat persistence without a live Bedrock client. */
final class WeatherLevelDBRoundTripTest extends TestCase{
	public function testWeatherAndPhysicalBlockStatesSurviveProviderReopen() : void{
		$path = sys_get_temp_dir() . "/ryze-weather-round-trip-" . bin2hex(random_bytes(10));
		$provider = null;
		try{
			LevelDB::generate($path, "weather_round_trip", WorldCreationOptions::create());
			$provider = new LevelDB($path, $this->createMock(\Logger::class));
			$data = $provider->getWorldData();
			$data->setRainTime(450);
			$data->setRainLevel(0.6);
			$data->setLightningTime(720);
			$data->setLightningLevel(0.25);
			(new Weather($this->createMock(World::class), $data))->save($data);

			$rodState = VanillaBlocks::LIGHTNING_ROD()->setPowered(true)->getStateId();
			$cauldronState = VanillaBlocks::POWDER_SNOW_CAULDRON()->setFillLevel(4)->getStateId();
			$subChunks = [];
			for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
				$subChunks[$y] = new SubChunk(Block::EMPTY_STATE_ID, [], new PalettedBlockArray(BiomeIds::OCEAN));
			}
			$subChunks[4]->setBlockStateId(1, 2, 3, $rodState);
			$subChunks[4]->setBlockStateId(2, 2, 3, $cauldronState);
			$provider->saveChunk(0, 0, new ChunkData($subChunks, true, [], []), Chunk::DIRTY_FLAG_BLOCKS | Chunk::DIRTY_FLAG_BIOMES);
			$data->save();
			$provider->close();

			$provider = new LevelDB($path, $this->createMock(\Logger::class));
			$reloadedData = $provider->getWorldData();
			self::assertSame(450, $reloadedData->getRainTime());
			self::assertEqualsWithDelta(0.6, $reloadedData->getRainLevel(), 0.00001);
			self::assertSame(720, $reloadedData->getLightningTime());
			self::assertEqualsWithDelta(0.25, $reloadedData->getLightningLevel(), 0.00001);
			$reloaded = $provider->loadChunk(0, 0);
			self::assertNotNull($reloaded);
			$blocks = $reloaded->getData()->getSubChunks()[4];
			self::assertSame($rodState, $blocks->getBlockStateId(1, 2, 3));
			self::assertSame($cauldronState, $blocks->getBlockStateId(2, 2, 3));
		}finally{
			$provider?->close();
			(new Filesystem())->remove($path);
		}
	}
}
