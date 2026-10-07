<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\dimension\generator\end\EndGenerator;
use pocketmine\world\dimension\generator\nether\NetherGenerator;
use pocketmine\world\format\Chunk;
use pocketmine\world\SimpleChunkManager;
use function in_array;

final class NativeDimensionGeneratorTest extends TestCase{

	public function testEndGeneratorCreatesMainIslandTerrain() : void{
		$manager = new SimpleChunkManager(0, 256);
		$chunk = new Chunk([], false);
		$manager->setChunk(0, 0, $chunk);

		$generator = new EndGenerator(123456789, "");
		$generator->generateChunk($manager, 0, 0);

		self::assertSame(BiomeIds::THE_END, $chunk->getBiomeId(8, 63, 8));

		$endStone = VanillaBlocks::END_STONE()->getStateId();
		$blocks = 0;
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				for($y = 0; $y < 128; ++$y){
					if($chunk->getBlockStateId($x, $y, $z) === $endStone){
						++$blocks;
					}
				}
			}
		}
		self::assertGreaterThan(0, $blocks, "The End origin chunk must contain end stone from the main island");
	}

	public function testNetherGeneratorCreatesBoundedTerrainAndBiome() : void{
		$manager = new SimpleChunkManager(0, 128);
		$chunk = new Chunk([], false);
		$manager->setChunk(0, 0, $chunk);

		$generator = new NetherGenerator(123456789, "");
		$generator->generateChunk($manager, 0, 0);

		$bedrock = VanillaBlocks::BEDROCK()->getStateId();
		self::assertSame($bedrock, $chunk->getBlockStateId(0, 0, 0));
		self::assertSame($bedrock, $chunk->getBlockStateId(0, 127, 0));

		$biome = $chunk->getBiomeId(8, 63, 8);
		self::assertTrue(in_array($biome, [
			BiomeIds::HELL,
			BiomeIds::SOULSAND_VALLEY,
			BiomeIds::CRIMSON_FOREST,
			BiomeIds::WARPED_FOREST,
			BiomeIds::BASALT_DELTAS
		], true), "Generated Nether chunks must use a Nether biome");
	}
}
