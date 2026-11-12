<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\SimpleChunkManager;

final class RyzeOverworldGeneratorTest extends TestCase{

	public function testOptInRegistrationAndPresetValidation() : void{
		VanillaGenerators::register();
		$entry = GeneratorManager::getInstance()->getGenerator(VanillaGenerators::OVERWORLD_EXPERIMENTAL);
		self::assertNotNull($entry);
		self::assertSame(RyzeOverworldGenerator::class, $entry->getGeneratorClass());
		self::assertNotSame(GeneratorManager::getInstance()->getGenerator('normal'), $entry);
		$entry->validateGeneratorOptions('');
		$this->expectException(InvalidGeneratorOptionsException::class);
		$entry->validateGeneratorOptions('backend=native');
	}

	public function testTerrainSamplesAreStableAcrossChunkBoundariesAndSeeds() : void{
		$a = new OverworldTerrainSampler(12345);
		$b = new OverworldTerrainSampler(12345);
		$c = new OverworldTerrainSampler(54321);
		$changed = 0;
		foreach([[-17, 31], [-16, 31], [-1, -1], [0, 0], [15, 15], [16, 15], [500, -450], [-2000, 4000]] as [$x, $z]){
			$sample = $a->sample($x, $z);
			self::assertSame($sample, $b->sample($x, $z));
			self::assertGreaterThanOrEqual(-32, $sample['height']);
			self::assertLessThanOrEqual(196, $sample['height']);
			$changed += $sample !== $c->sample($x, $z) ? 1 : 0;
		}
		self::assertGreaterThan(0, $changed);
	}

	public function testGenerationAndTerrainSerializationOnNegativeHeightWorld() : void{
		$manager = new SimpleChunkManager(-64, 320);
		$chunk = new Chunk([], false);
		$manager->setChunk(-1, 0, $chunk);
		$generator = new RyzeOverworldGenerator(12345, '');
		$generator->generateChunk($manager, -1, 0);
		self::assertSame(VanillaBlocks::BEDROCK()->getStateId(), $chunk->getBlockStateId(0, -64, 0));
		self::assertNotSame(VanillaBlocks::AIR()->getStateId(), $chunk->getBlockStateId(8, 32, 8));
		$profile = (new OverworldTerrainSampler(12345))->sample(-8, 8);
		self::assertSame($profile['biome'], $chunk->getBiomeId(8, -48, 8));
		self::assertSame($profile['biome'], $chunk->getBiomeId(8, 180, 8));
		$restored = FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain($chunk));
		self::assertSame($chunk->getBlockStateId(8, 32, 8), $restored->getBlockStateId(8, 32, 8));
		self::assertSame($chunk->getBiomeId(8, 100, 8), $restored->getBiomeId(8, 100, 8));
	}

	public function testAlreadyPopulatedChunkIsUnchanged() : void{
		$manager = new SimpleChunkManager(0, 256);
		$chunk = new Chunk([], true);
		$marker = VanillaBlocks::GOLD_BLOCK()->getStateId();
		$chunk->setBlockStateId(3, 64, 3, $marker);
		$manager->setChunk(0, 0, $chunk);
		(new RyzeOverworldGenerator(12345, ''))->generateChunk($manager, 0, 0);
		self::assertSame($marker, $chunk->getBlockStateId(3, 64, 3));
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $chunk->getBlockStateId(4, 64, 3));
	}
}
