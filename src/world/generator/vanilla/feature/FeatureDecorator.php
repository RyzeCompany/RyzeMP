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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\BiomeMapping;
use pocketmine\world\generator\vanilla\feature\block\BlockSurvival;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\native\NativeBackendException;
use pocketmine\world\generator\vanilla\native\NativeDecorator;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\World;
use function array_key_first;
use function array_keys;
use function count;
use function hrtime;
use function pack;
use function sort;
use function strlen;
use function unpack;

/**
 * Vanilla's biome decoration (ChunkGenerator.applyBiomeDecoration) for one chunk, with its 3x3 neighbours as the
 * region: for every decoration step, every feature of the biomes present in the region is placed in the global
 * feature order, each with its random seeded by WorldgenRandom.setFeatureSeed(decorationSeed, featureIndex, step).
 *
 * Decoration follows Java Edition 26.3 exactly for the same seed (Bedrock's own decoration is different code inside
 * the game and is not public); the blocks are mapped to their Bedrock equivalents.
 */
final class FeatureDecorator{
	public readonly JavaBlocks $blocks;
	public readonly FeatureRegistry $registry;
	public readonly FeatureLevel $level;
	public readonly FeatureContext $context;
	private readonly FeatureRandom $random;
	/** @phpstan-var array<int, list<int>> Bedrock biome id => registry biome indexes */
	private array $bedrockToJava = [];
	/** @phpstan-var array<int, int> tree biome index => registry biome index */
	private array $treeToRegistry = [];
	/** @phpstan-var array<int, string> chunk hash => packed terrain heightmaps (see recordTerrain()) */
	private array $terrainHeights = [];
	/** @phpstan-var array<int, true> blocks that only features place (ignored when rebuilding terrain heightmaps) */
	private array $featureOnlyBlocks = [];
	private const TERRAIN_CACHE_SIZE = 2048;
	/** GenerationStep.Decoration count */
	public const DECORATION_STEPS = 11;

	/** Structures placed at the start of each decoration step (null: no structures). */
	public ?StructureManager $structures = null;
	/** Whether the decoration features are placed (structures can be generated without them). */
	public bool $featuresEnabled = true;
	/**
	 * RandomState.getOrCreateRandomFactory("minecraft:worldgen_region_random"): WorldGenRegion.getRandom() of a chunk
	 * being decorated is this factory at the chunk's origin (null: not set by the generator).
	 */
	public ?PositionalRandomFactory $regionRandomFactory = null;
	private ?StructurePlaceContext $structureContext = null;
	/**
	 * Nanoseconds spent per placed feature id (null: not profiled). Development aid for tools/.
	 * @phpstan-var array<string, int>|null
	 */
	public ?array $profile = null;
	/** Native placement of the features the native core implements (null: everything in PHP). */
	private ?NativeDecorator $native = null;

	/**
	 * @param string[] $treeBiomeNames biome names of the climate tree (index = terrain biome index)
	 * @phpstan-param list<string> $treeBiomeNames
	 * @phpstan-param \Closure(int, int, int) : int $sampleTreeBiome terrain biome index of a cell (quart coordinates)
	 * @param string[]|null $possibleBiomes biomes of the dimension in the biome source's order (null: the overworld's)
	 * @phpstan-param (\Closure(string) : void)|null $logger
	 * @phpstan-param list<string>|null $possibleBiomes
	 */
	public function __construct(
		WorldgenData $data,
		private readonly int $seed,
		int $minY,
		int $height,
		int $seaLevel,
		array $treeBiomeNames,
		\Closure $sampleTreeBiome,
		?\Closure $logger = null,
		?array $possibleBiomes = null
	){
		$this->blocks = JavaBlocks::get($data->getRoot());
		$this->registry = new FeatureRegistry($data, $this->blocks, $logger, $possibleBiomes);
		foreach($treeBiomeNames as $treeIndex => $name){
			$registryIndex = $this->registry->biomeIndex[$name] ?? throw new \RuntimeException("Biome $name has no feature data");
			$this->treeToRegistry[$treeIndex] = $registryIndex;
			$this->bedrockToJava[BiomeMapping::toBedrock($name)][] = $registryIndex;
		}
		$treeToRegistry = $this->treeToRegistry;
		$biomes = new FeatureBiomes(
			$seed,
			$minY,
			$height,
			$this->bedrockToJava,
			fn(int $qx, int $qy, int $qz) : int => $treeToRegistry[$sampleTreeBiome($qx, $qy, $qz)]
		);
		$this->level = new FeatureLevel($this->blocks, $minY, $height, $seaLevel, $seed, $biomes);
		$this->level->terrainHeightProvider = fn(int $chunkX, int $chunkZ, Chunk $chunk) : array => $this->terrainHeights($chunkX, $chunkZ, $chunk);
		$this->initFeatureOnlyBlocks();
		$this->random = new FeatureRandom();
		$this->context = new FeatureContext(
			$this->level,
			$this->random,
			$this->blocks,
			new BlockSurvival($this->blocks),
			$this->registry,
			$minY,
			$height,
			$seaLevel,
			$seed
		);
	}

	/**
	 * Decorates the chunk; its 3x3 neighbours must be available in the chunk manager (they may be modified too).
	 */
	public function decorate(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$level = $this->level;
		$structures = $this->structures;
		$start = $this->profile !== null ? hrtime(true) : 0;
		$starts = $structures !== null ? $structures->getStartsAffecting($chunkX, $chunkZ) : [];
		if($this->profile !== null){
			$this->profile["(structure starts)"] = ($this->profile["(structure starts)"] ?? 0) + (hrtime(true) - $start);
		}
		$level->begin($world, $chunkX, $chunkZ);
		$level->regionRandom = $this->regionRandomFactory?->at($chunkX << 4, 0, $chunkZ << 4);
		try{
			$decorationSeed = $this->random->setDecorationSeed($this->seed, $chunkX << 4, $chunkZ << 4);
			$biomes = $this->featuresEnabled ? $this->collectBiomes($world, $chunkX, $chunkZ) : [];
			$registry = $this->registry;
			$native = $this->featuresEnabled ? $this->native : null;
			if($native !== null){
				try{
					$native->begin($level, $chunkX, $chunkZ, $this->recordedHeights($chunkX, $chunkZ));
					$level->logWrites = true;
				}catch(NativeBackendException $e){
					$native = $this->disableNative($e);
				}
			}
			$structureContext = null;
			if($structures !== null && count($starts) > 0){
				$structureContext = $this->structureContext ??= new StructurePlaceContext($this->context, $structures->env);
			}
			//consecutive native features go in one request, across steps too unless PHP places something in between
			$batch = [];
			for($step = 0; $step < self::DECORATION_STEPS; ++$step){
				if($structures !== null && $structureContext !== null){
					if(count($batch) > 0 && $structures->placesInStep($starts, $step)){
						$native = $this->placeNative($native, $batch, $decorationSeed);
						$batch = [];
					}
					$start = $this->profile !== null ? hrtime(true) : 0;
					$structures->placeStep($structureContext, $starts, $step, $chunkX, $chunkZ, $decorationSeed, $this->random);
					if($this->profile !== null){
						$this->profile["(structures)"] = ($this->profile["(structures)"] ?? 0) + (hrtime(true) - $start);
					}
				}
				$features = $registry->stepFeatures[$step] ?? null;
				if($features === null || !$this->featuresEnabled){
					continue;
				}
				$indexes = [];
				foreach($biomes as $biome){
					foreach($registry->biomeStepIndexes[$biome][$step] ?? [] as $index){
						$indexes[$index] = true;
					}
				}
				if(count($indexes) === 0){
					continue;
				}
				$indexes = array_keys($indexes);
				sort($indexes);
				foreach($indexes as $index){
					if($native !== null && $native->isNative($step, $index)){
						$batch[] = [$step, $index];
						continue;
					}
					if(count($batch) > 0){
						$native = $this->placeNative($native, $batch, $decorationSeed);
						$batch = [];
					}
					$this->placePhp($step, $index, $decorationSeed);
				}
			}
			if(count($batch) > 0){
				$native = $this->placeNative($native, $batch, $decorationSeed);
			}
			$structureContext?->postProcess();
		}finally{
			$level->logWrites = false;
			$level->writeLog = [];
			$level->finish();
			if($this->structureContext !== null){
				$this->structureContext->postProcess = [];
			}
		}
	}

	private function placePhp(int $step, int $index, int $decorationSeed) : void{
		$id = $this->registry->stepFeatures[$step][$index];
		$this->random->setFeatureSeed($decorationSeed, $index, $step);
		if($this->profile !== null){
			$start = hrtime(true);
			$this->registry->placed($id)->placeWithBiomeCheck($this->context, $this->level->centerChunkX << 4, $this->level->minY, $this->level->centerChunkZ << 4);
			$this->profile[$id] = ($this->profile[$id] ?? 0) + (hrtime(true) - $start);
			return;
		}
		$this->registry->placed($id)->placeWithBiomeCheck($this->context, $this->level->centerChunkX << 4, $this->level->minY, $this->level->centerChunkZ << 4);
	}

	/**
	 * Places a batch of consecutive features natively; if the native core fails, it is disabled and the batch is
	 * placed by PHP. Returns the native decorator to keep using (null once disabled).
	 *
	 * @param int[][] $batch
	 * @phpstan-param list<array{int, int}> $batch
	 */
	private function placeNative(?NativeDecorator $native, array $batch, int $decorationSeed) : ?NativeDecorator{
		if($native !== null){
			try{
				$start = $this->profile !== null ? hrtime(true) : 0;
				$native->place($this->level, $this->random, $decorationSeed, $batch);
				if($this->profile !== null){
					$this->profile["(native)"] = ($this->profile["(native)"] ?? 0) + (hrtime(true) - $start);
				}
				return $native;
			}catch(NativeBackendException $e){
				$native = $this->disableNative($e);
			}
		}
		foreach($batch as [$step, $index]){
			$this->placePhp($step, $index, $decorationSeed);
		}
		return $native;
	}

	private function disableNative(NativeBackendException $e) : null{
		\GlobalLogger::get()->warning("Vanilla generator: native decoration failed, using the PHP implementation: " . $e->getMessage());
		$this->native = null;
		$this->level->logWrites = false;
		$this->level->writeLog = [];
		return null;
	}

	/**
	 * The packed terrain heights recorded for a chunk (recordTerrain() / recordTerrainHeights()), if still cached.
	 */
	public function getRecordedTerrainHeights(int $chunkX, int $chunkZ) : ?string{
		return $this->terrainHeights[World::chunkHash($chunkX, $chunkZ)] ?? null;
	}

	/**
	 * Recorded terrain heightmaps of the 3x3 chunks (see recordTerrain()), by region chunk index.
	 * @phpstan-return array<int, string|null>
	 */
	private function recordedHeights(int $chunkX, int $chunkZ) : array{
		$result = [];
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$result[($dx + 1) * 3 + ($dz + 1)] = $this->terrainHeights[World::chunkHash($chunkX + $dx, $chunkZ + $dz)] ?? null;
			}
		}
		return $result;
	}

	/**
	 * Lets the native core place the features it implements, on the connection of the native terrain client.
	 * Returns false (and logs why) if it cannot be used.
	 */
	public function enableNative(NativeTerrainClient $client) : bool{
		$bedrockToJava = [];
		foreach($this->bedrockToJava as $id => $list){
			$bedrockToJava[$id] = $list;
		}
		try{
			$this->native = new NativeDecorator(
				$client,
				$this->blocks,
				$this->level->minY,
				$this->level->height,
				$this->level->seaLevel,
				$this->registry->biomeNames,
				$bedrockToJava,
				$this->treeToRegistry,
				$this->registry->stepFeatures
			);
		}catch(NativeBackendException $e){
			\GlobalLogger::get()->warning("Vanilla generator: native decoration unavailable, using the PHP implementation: " . $e->getMessage());
			$this->native = null;
			return false;
		}
		return true;
	}

	public function getNative() : ?NativeDecorator{
		return $this->native;
	}

	/**
	 * Places one configured feature (worldgen/feature id) at a position with a random seeded from $randomSeed, like
	 * vanilla's /place feature. The 3x3 chunks around the position must be available in the chunk manager.
	 */
	public function placeFeature(ChunkManager $world, string $feature, int $x, int $y, int $z, int $randomSeed) : bool{
		$level = $this->level;
		$level->begin($world, $x >> 4, $z >> 4);
		try{
			$this->random->setFeatureSeed($randomSeed, 0, 0);
			return $this->registry->configuredRef($feature)->place($this->context, $x, $y, $z);
		}finally{
			$level->finish();
		}
	}

	/**
	 * Biomes stored in the 3x3 chunks (all sections), like vanilla. Bedrock ids shared by several Java biomes count
	 * as all of them (features still check the biome of their position).
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function collectBiomes(ChunkManager $world, int $chunkX, int $chunkZ) : array{
		$set = [];
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$chunk = $world->getChunk($chunkX + $dx, $chunkZ + $dz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getSubChunks() as $subChunk){
					foreach($subChunk->getBiomeArray()->getPalette() as $bedrock){
						foreach($this->bedrockToJava[$bedrock] ?? [] as $biome){
							$set[$biome] = true;
						}
					}
				}
			}
		}
		return array_keys($set);
	}

	private function initFeatureOnlyBlocks() : void{
		$blocks = $this->blocks;
		foreach(["logs", "leaves", "flowers", "saplings", "replaceable", "replaceable_by_trees", "cave_vines", "corals", "wall_corals", "coral_plants"] as $tag){
			if($blocks->hasTag($tag)){
				$this->featureOnlyBlocks += $blocks->tag($tag);
			}
		}
		foreach([
			"bee_nest", "cocoa", "brown_mushroom_block", "red_mushroom_block", "mushroom_stem", "moss_carpet", "pale_moss_carpet",
			"leaf_litter", "pumpkin", "melon", "sugar_cane", "cactus", "cactus_flower", "bamboo", "bamboo_sapling", "big_dripleaf",
			"big_dripleaf_stem", "small_dripleaf", "azalea", "flowering_azalea", "sweet_berry_bush", "pale_hanging_moss", "kelp",
			"kelp_plant", "sea_pickle", "lily_pad", "firefly_bush", "shelf_mushroom", "creaking_heart", "brown_mushroom",
			"red_mushroom", "hay_block", "spore_blossom", "hanging_roots", "glow_lichen", "vine", "snow", "pointed_dripstone"
		] as $name){
			if($blocks->hasBlock($name)){
				$this->featureOnlyBlocks[$blocks->blockIndex($name)] = true;
			}
		}
		//water and air are terrain
		unset($this->featureOnlyBlocks[$blocks->waterBlock], $this->featureOnlyBlocks[$blocks->lavaBlock], $this->featureOnlyBlocks[$blocks->airBlock], $this->featureOnlyBlocks[$blocks->caveAirBlock]);
	}

	/**
	 * Records the terrain heightmaps of a freshly generated (not yet decorated) chunk: vanilla features see the
	 * WORLD_SURFACE_WG / OCEAN_FLOOR_WG heightmaps as they were after the terrain step.
	 */
	public function recordTerrain(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		[$surface, $oceanFloor] = $this->computeTerrainHeights($chunk, false);
		$this->recordTerrainHeights($chunkX, $chunkZ, pack("v*", ...$surface) . pack("v*", ...$oceanFloor));
	}

	/**
	 * recordTerrain() with the heights already computed (by the native core): u16[256] WORLD_SURFACE_WG then u16[256]
	 * OCEAN_FLOOR_WG, first free y minus minY, index x | z << 4.
	 */
	public function recordTerrainHeights(int $chunkX, int $chunkZ, string $packed) : void{
		if(strlen($packed) !== 1024){
			throw new \InvalidArgumentException("Expected 1024 bytes of packed heights");
		}
		$hash = World::chunkHash($chunkX, $chunkZ);
		unset($this->terrainHeights[$hash]);
		$this->terrainHeights[$hash] = $packed;
		if(count($this->terrainHeights) > self::TERRAIN_CACHE_SIZE){
			unset($this->terrainHeights[array_key_first($this->terrainHeights)]);
		}
	}

	/**
	 * How recordTerrain() sees a PocketMine block of a freshly generated chunk: 0 air (skipped), 2 blocks motion in
	 * heightmaps (WORLD_SURFACE_WG and OCEAN_FLOOR_WG), 1 any other block (WORLD_SURFACE_WG only).
	 */
	public function terrainHeightClass(int $pmState) : int{
		$blocks = $this->blocks;
		$state = $blocks->fromPm($pmState);
		if($blocks->isAir($state)){
			return 0;
		}
		return isset($blocks->tag("minecraft:blocks_motion_in_heightmap")[$blocks->blockOf[$state]]) ? 2 : 1;
	}

	/**
	 * @phpstan-return array{list<int>, list<int>}
	 */
	private function terrainHeights(int $chunkX, int $chunkZ, Chunk $chunk) : array{
		$packed = $this->terrainHeights[World::chunkHash($chunkX, $chunkZ)] ?? null;
		if($packed !== null){
			$values = unpack("v*", $packed);
			if($values !== false){
				$minY = $this->level->minY;
				$surface = [];
				$oceanFloor = [];
				for($i = 1; $i <= 256; ++$i){
					$surface[] = (int) $values[$i] + $minY;
					$oceanFloor[] = (int) $values[$i + 256] + $minY;
				}
				return [$surface, $oceanFloor];
			}
		}
		//generated by another worker: rebuild it, ignoring the blocks only features place
		return $this->computeTerrainHeights($chunk, true);
	}

	/**
	 * @phpstan-return array{list<int>, list<int>} first free y per column (WORLD_SURFACE_WG, OCEAN_FLOOR_WG), relative
	 * to minY when packed by recordTerrain()
	 */
	private function computeTerrainHeights(Chunk $chunk, bool $ignoreFeatureBlocks) : array{
		$blocks = $this->blocks;
		$minY = $this->level->minY;
		$sections = $this->level->height >> 4;
		$motion = $blocks->tag("minecraft:blocks_motion_in_heightmap");
		$top = $minY;
		for($s = $sections - 1; $s >= 0; --$s){
			if(!$chunk->getSubChunk(($minY >> 4) + $s)->isEmptyFast()){
				$top = $minY + ($s << 4) + 16;
				break;
			}
		}
		$surface = [];
		$oceanFloor = [];
		for($i = 0; $i < 256; ++$i){
			$x = $i & 15;
			$z = $i >> 4;
			$ws = null;
			$of = null;
			for($y = $top - 1; $y >= $minY; --$y){
				$state = $blocks->fromPm($chunk->getBlockStateId($x, $y, $z));
				if($blocks->isAir($state)){
					continue;
				}
				$block = $blocks->blockOf[$state];
				if($ignoreFeatureBlocks && isset($this->featureOnlyBlocks[$block])){
					continue;
				}
				$ws ??= $y + 1;
				if(isset($motion[$block])){
					$of = $y + 1;
					break;
				}
			}
			$surface[] = $ws ?? $minY;
			$oceanFloor[] = $of ?? $minY;
		}
		if(!$ignoreFeatureBlocks){
			foreach($surface as $k => $v){
				$surface[$k] = $v - $minY;
			}
			foreach($oceanFloor as $k => $v){
				$oceanFloor[$k] = $v - $minY;
			}
		}
		return [$surface, $oceanFloor];
	}
}
