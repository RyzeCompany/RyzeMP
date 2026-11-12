<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\world\ChunkManager;
use pocketmine\world\generator\Generator;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

/**
 * Experimental opt-in VanillaMine density/material/carver pipeline for secondary dimensions.
 * Does not replace RyzeMP's existing Nether/End generators, dimension IDs or portals.
 * Feature/structure decoration is deliberately NOT claimed until ported and tested.
 */
abstract class VanillaDimensionCoreGenerator extends Generator{
	protected const DIMENSION = "";

	private VanillaDensityChunkWriter $writer;

	/** @throws InvalidGeneratorOptionsException */
	public function __construct(int $seed, string $preset){
		$path = VanillaDensityChunkWriter::validatePreset($preset, static::DIMENSION);
		parent::__construct($seed, $preset);
		$this->writer = new VanillaDensityChunkWriter($seed, $path, static::DIMENSION, VanillaDataIdentity::expectedHash($preset), VanillaDataIdentity::hasPlacedFeatures($preset), VanillaDataIdentity::hasCompiledTerrain($preset));
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Missing chunk $chunkX,$chunkZ");
		if($chunk->isPopulated()){
			return;
		}
		if($world->getMinY() > 0 || $world->getMaxY() < 128){
			throw new \InvalidArgumentException("VanillaMine " . static::DIMENSION . " core-v1 requires world range covering Y 0..127");
		}
		$this->writer->fill($chunk, $chunkX, $chunkZ);
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		if($this->writer->hasPlacedFeatures()){
			$this->writer->populate($world, $chunkX, $chunkZ);
		}elseif(static::DIMENSION === "end"){
			// Core-v1/v2 keep the original isolated End spike implementation.
			// Core-v3 includes spikes among the upstream placed features.
			VanillaEndSpikePopulator::populate($world, $chunkX, $chunkZ, $this->seed);
		}
	}
}
