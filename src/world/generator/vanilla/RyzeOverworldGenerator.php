<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\ChunkManager;
use pocketmine\world\dimension\generator\BiomeFiller;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use function max;
use function min;

/**
 * Opt-in Worldgen Phase 2 baseline. Deliberately does NOT claim Mojang-equivalent terrain.
 * Only runs on chunks which the existing PM5 generation pipeline creates as new.
 * Nether/End and pre-existing world generators are not replaced.
 */
final class RyzeOverworldGenerator extends Generator{
	private const SEA_LEVEL = 62;

	private OverworldTerrainSampler $sampler;
	private ?VanillaDensityChunkWriter $vanillaCore;
	private int $bedrock;
	private int $stone;
	private int $dirt;
	private int $grass;
	private int $sand;
	private int $water;

	/** @throws InvalidGeneratorOptionsException */
	public function __construct(int $seed, string $preset){
		if($preset !== ""){
			$dataDir = VanillaDensityChunkWriter::validatePreset($preset);
		}else{
			$dataDir = null; // Frozen legacy experimental algorithm; never silently upgrade existing worlds.
		}
		parent::__construct($seed, $preset);
		$this->vanillaCore = $dataDir !== null ? new VanillaDensityChunkWriter($seed, $dataDir, "overworld", VanillaDataIdentity::expectedHash($preset), VanillaDataIdentity::hasPlacedFeatures($preset)) : null;
		$this->sampler = new OverworldTerrainSampler($seed);
		$this->bedrock = VanillaBlocks::BEDROCK()->getStateId();
		$this->stone = VanillaBlocks::STONE()->getStateId();
		$this->dirt = VanillaBlocks::DIRT()->getStateId();
		$this->grass = VanillaBlocks::GRASS()->getStateId();
		$this->sand = VanillaBlocks::SAND()->getStateId();
		$this->water = VanillaBlocks::WATER()->getStillForm()->getStateId();
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");
		if($chunk->isPopulated()){
			//Extra defense for accidental direct calls. Standard PopulationUtils only generates missing chunks.
			return;
		}
		if($this->vanillaCore !== null){
			if($world->getMinY() !== -64 || $world->getMaxY() !== 320){
				throw new \InvalidArgumentException('VanillaMine core-v1 Overworld requires world bounds -64..319');
			}
			$this->vanillaCore->fill($chunk, $chunkX, $chunkZ);
			return;
		}
		$minY = max($world->getMinY(), Chunk::MIN_SUBCHUNK_INDEX * 16);
		$maxY = min($world->getMaxY(), (Chunk::MAX_SUBCHUNK_INDEX + 1) * 16);
		if($minY >= $maxY){
			throw new \InvalidArgumentException("World height does not overlap the supported chunk range");
		}

		$profiles = [];
		$biomes = [];
		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				$index = $x * Chunk::EDGE_LENGTH + $z;
				$profile = $this->sampler->sample($chunkX * Chunk::EDGE_LENGTH + $x, $chunkZ * Chunk::EDGE_LENGTH + $z);
				$profiles[$index] = $profile;
				$biomes[$index] = $profile['biome'];
			}
		}
		BiomeFiller::fillColumns($chunk, $biomes);
		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				$profile = $profiles[$x * Chunk::EDGE_LENGTH + $z];
				$surface = max($minY, min($maxY - 1, $profile['height']));
				$sandy = $profile['biome'] === BiomeIds::DESERT || $profile['biome'] === BiomeIds::BEACH || $profile['biome'] === BiomeIds::OCEAN;
				$chunk->setBlockStateId($x, $minY, $z, $this->bedrock);
				for($y = $minY + 1; $y <= $surface; ++$y){
					$block = $y === $surface ? ($sandy ? $this->sand : $this->grass) : ($y >= $surface - 3 ? ($sandy ? $this->sand : $this->dirt) : $this->stone);
					$chunk->setBlockStateId($x, $y, $z, $block);
				}
				for($y = $surface + 1; $y <= min(self::SEA_LEVEL, $maxY - 1); ++$y){
					$chunk->setBlockStateId($x, $y, $z, $this->water);
				}
				$chunk->setHeightMap($x, $z, min($maxY, max($surface, self::SEA_LEVEL) + 1));
			}
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$this->vanillaCore?->populate($world, $chunkX, $chunkZ);
		// Historical empty preset retains its no-decoration behaviour.
	}
}
