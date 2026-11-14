<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\light;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\LightArray;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\World;
use function class_exists;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;

class LightPopulationTask extends AsyncTask{
	private const TLS_KEY_COMPLETION_CALLBACK = "onCompletion";

	public string $chunk;

	private string $resultHeightMap;
	private string $resultSkyLightArrays;
	private string $resultBlockLightArrays;

	/**
	 * @phpstan-param \Closure(array<int, LightArray> $blockLight, array<int, LightArray> $skyLight, non-empty-list<int> $heightMap) : void $onCompletion
	 */
	public function __construct(Chunk $chunk, \Closure $onCompletion){
		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->storeLocal(self::TLS_KEY_COMPLETION_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->chunk);

		[$heightMap, $skyLightArrays, $blockLightArrays] = self::computeLight($chunk);

		$this->resultHeightMap = igbinary_serialize($heightMap);
		$this->resultSkyLightArrays = igbinary_serialize($skyLightArrays);
		$this->resultBlockLightArrays = igbinary_serialize($blockLightArrays);
	}

	private static bool $nativeEnabled = true;

	/**
	 * @internal for tests and benchmarks: false makes {@link LightPopulationTask::computeLight()} always use the PHP
	 * light updates (in the calling thread)
	 */
	public static function setNativeEnabled(bool $enabled) : void{
		self::$nativeEnabled = $enabled;
	}

	/**
	 * Computes the light of a chunk standing alone (no neighbours): block light, then heightmap and sky light. Uses
	 * NativeLightPopulation of chunkutils2 >= 0.3.13 when present (the same results bit for bit, ~30-50x faster;
	 * NativeLightPopulationTest), else BlockLightUpdate and SkyLightUpdate. The chunk's light arrays and heightmap are
	 * only written by the PHP path; callers use the returned values.
	 *
	 * @internal
	 * @return array{list<int>, array<int, LightArray>, array<int, LightArray>} heightmap, sky light, block light
	 */
	public static function computeLight(Chunk $chunk) : array{
		$blockFactory = RuntimeBlockStateRegistry::getInstance();

		if(self::$nativeEnabled && class_exists(NativeLightPopulation::class)){
			$emptyBlockIds = [];
			$layers = [];
			foreach($chunk->getSubChunks() as $y => $subChunk){
				$emptyBlockIds[$y] = $subChunk->getEmptyBlockId();
				$subChunkLayers = $subChunk->getBlockLayers();
				if(count($subChunkLayers) > 0){
					$layers[$y] = $subChunkLayers[0];
				}
			}
			$result = NativeLightPopulation::populate(Chunk::MIN_SUBCHUNK_INDEX, Chunk::MAX_SUBCHUNK_INDEX, $emptyBlockIds, $layers, $blockFactory->lightFilter, $blockFactory->light, $blockFactory->blocksDirectSkyLight);
			if($result !== null){
				return $result;
			}
			//null: a table value the native code leaves to PHP
		}

		$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		$manager->setChunk(0, 0, $chunk);

		foreach([
			"Block" => new BlockLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->light),
			"Sky" => new SkyLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->blocksDirectSkyLight),
		] as $name => $update){
			$update->recalculateChunk(0, 0);
			$update->execute();
		}

		$chunk->setLightPopulated();

		$skyLightArrays = [];
		$blockLightArrays = [];
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$skyLightArrays[$y] = $subChunk->getBlockSkyLightArray();
			$blockLightArrays[$y] = $subChunk->getBlockLightArray();
		}
		return [$chunk->getHeightMapArray(), $skyLightArrays, $blockLightArrays];
	}

	public function onCompletion() : void{
		/**
		 * @var int[] $heightMapArray
		 * @phpstan-var non-empty-list<int> $heightMapArray
		 */
		$heightMapArray = igbinary_unserialize($this->resultHeightMap);

		/** @var LightArray[] $skyLightArrays */
		$skyLightArrays = igbinary_unserialize($this->resultSkyLightArrays);
		/** @var LightArray[] $blockLightArrays */
		$blockLightArrays = igbinary_unserialize($this->resultBlockLightArrays);

		/**
		 * @var \Closure
		 * @phpstan-var \Closure(array<int, LightArray> $blockLight, array<int, LightArray> $skyLight, non-empty-list<int> $heightMap) : void
		 */
		$callback = $this->fetchLocal(self::TLS_KEY_COMPLETION_CALLBACK);
		$callback($blockLightArrays, $skyLightArrays, $heightMapArray);
	}
}
