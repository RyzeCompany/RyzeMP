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

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\feature\block\BlockSurvival;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function array_key_exists;
use function count;
use function file_exists;
use function intdiv;
use function is_a;
use function is_dir;

/**
 * @internal
 *
 * Places configured features of the vanilla data (trees, huge fungi, huge mushrooms) in a loaded world, for the blocks
 * that grow them (saplings, mangrove propagules, azaleas, fungi, mushrooms), like vanilla's TreeGrower and
 * MushroomBlock.growMushroom().
 *
 * The feature is placed in the 3x3 chunks around the origin (the same region the generator decorates), copying each
 * subchunk it writes, so the world is not touched: the changed blocks come back as a {@link BlockTransaction} which
 * the caller passes to StructureGrowEvent and applies. Nothing is placed when one of these chunks is not loaded
 * (chunks are never loaded or generated for this).
 *
 * Only worlds of the vanilla generators have a grower; the vanilla data and the features grown by blocks are loaded
 * when such a world loads ({@link WorldFeatureGrower::prepare()}), not on the first growth.
 */
final class WorldFeatureGrower{
	/**
	 * Vanilla data loaded per data root (null: not available).
	 * @phpstan-var array<string, array{JavaBlocks, FeatureRegistry}|null>
	 */
	private static array $shared = [];
	/** @phpstan-var array<string, self> by data root and build height */
	private static array $instances = [];
	/**
	 * Grower of each world (false: none, the world keeps PocketMine's trees).
	 * @phpstan-var \WeakMap<World, self|false>|null
	 */
	private static ?\WeakMap $worlds = null;

	private readonly FeatureLevel $level;
	private readonly FeatureRandom $random;
	private readonly FeatureContext $context;
	/** @phpstan-var array<int, true>|null */
	private ?array $flowers = null;

	private function __construct(
		private readonly JavaBlocks $blocks,
		private readonly FeatureRegistry $registry,
		int $minY,
		int $height
	){
		$biomes = new FeatureBiomes(0, $minY, $height, [], fn(int $qx, int $qy, int $qz) : int => 0);
		$this->level = new FeatureLevel($blocks, $minY, $height, 63, 0, $biomes);
		$this->random = new FeatureRandom();
		$this->context = new FeatureContext($this->level, $this->random, $blocks, new BlockSurvival($blocks), $registry, $minY, $height, 63, 0);
	}

	/**
	 * Returns the grower for the given world, or null if the world has none: only worlds of the vanilla generators
	 * grow the vanilla features (other worlds, e.g. flat lobbies, keep PocketMine's trees and don't load the vanilla
	 * data), and only if the vanilla data of the generator is available. Cached per world.
	 */
	public static function get(World $world) : ?self{
		$worlds = self::$worlds ??= new \WeakMap();
		$grower = $worlds[$world] ?? null;
		if($grower === null){
			$root = self::vanillaDataRoot($world);
			$grower = ($root !== null ? self::forRoot($root, $world->getMinY(), $world->getMaxY()) : null) ?? false;
			$worlds[$world] = $grower;
		}
		return $grower !== false ? $grower : null;
	}

	/**
	 * Makes a world grow the vanilla features of the given data root (or none), whatever its generator.
	 * For tests.
	 */
	public static function useDataRoot(World $world, ?string $root) : void{
		$worlds = self::$worlds ??= new \WeakMap();
		$worlds[$world] = ($root !== null ? self::forRoot($root, $world->getMinY(), $world->getMaxY()) : null) ?? false;
	}

	/**
	 * Main thread, when a world of a vanilla generator is loaded: loads the vanilla data and decodes the given
	 * features, so the first block that grows one does not stall a tick with it.
	 *
	 * @param string[] $features
	 * @phpstan-param list<string> $features
	 */
	public static function prepare(string $root, array $features) : void{
		$shared = self::shared($root);
		if($shared === null){
			return;
		}
		foreach($features as $feature){
			try{
				$shared[1]->configuredRef($feature);
			}catch(\RuntimeException $e){
				\GlobalLogger::get()->debug("Vanilla feature $feature unavailable: " . $e->getMessage());
			}
		}
	}

	/**
	 * The vanilla data root of the world's generator, or null if the world is not made by a vanilla generator.
	 */
	private static function vanillaDataRoot(World $world) : ?string{
		$worldData = $world->getProvider()->getWorldData();
		$entry = GeneratorManager::getInstance()->getGenerator($worldData->getGenerator());
		if($entry === null || !is_a($entry->getGeneratorClass(), VanillaGenerator::class, true)){
			return null;
		}
		try{
			return VanillaGenerator::dataDirectory(VanillaGenerator::parseOptions($worldData->getGeneratorOptions()));
		}catch(InvalidGeneratorOptionsException){
			return null;
		}
	}

	private static function forRoot(string $root, int $minY, int $maxY) : ?self{
		$key = $root . ":" . $minY . ":" . $maxY;
		if(isset(self::$instances[$key])){
			return self::$instances[$key];
		}
		$shared = self::shared($root);
		if($shared === null){
			return null;
		}
		return self::$instances[$key] = new self($shared[0], $shared[1], $minY, $maxY - $minY);
	}

	/**
	 * @phpstan-return array{JavaBlocks, FeatureRegistry}|null
	 */
	private static function shared(string $root) : ?array{
		if(array_key_exists($root, self::$shared)){
			return self::$shared[$root];
		}
		if(!is_dir($root . "/data/minecraft/worldgen/feature") || !file_exists($root . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE)){
			return self::$shared[$root] = null;
		}
		try{
			$data = WorldgenData::shared($root);
			$blocks = JavaBlocks::get($root);
			$logger = \GlobalLogger::get();
			//no biomes: the features grown by blocks don't look at them
			$registry = new FeatureRegistry($data, $blocks, function(string $message) use ($logger) : void{
				$logger->debug($message);
			}, []);
		}catch(\RuntimeException|\JsonException $e){
			\GlobalLogger::get()->debug("Vanilla features unavailable for growing blocks: " . $e->getMessage());
			return self::$shared[$root] = null;
		}
		return self::$shared[$root] = [$blocks, $registry];
	}

	/**
	 * Whether there is a flower (Java's #flowers block tag) in the 5x3x5 box around a position (TreeGrower.hasFlowers()),
	 * which makes saplings grow the trees with bee nests.
	 */
	public function hasFlowers(World $world, int $x, int $y, int $z) : bool{
		$flowers = $this->flowers ??= $this->blocks->tag("minecraft:flowers");
		$blocks = $this->blocks;
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				for($dy = -1; $dy <= 1; ++$dy){
					if(!$world->isInWorld($x + $dx, $y + $dy, $z + $dz)){
						continue;
					}
					$state = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz, addToCache: false)->getStateId();
					if(isset($flowers[$blocks->blockOf[$blocks->fromPm($state)]])){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Places a configured feature at a position and returns the changes, or null if the feature placed nothing (or
	 * the area is not loaded). The world is not changed.
	 *
	 * @param int[][] $clear positions emptied before placing (the saplings, TreeGrower.removeSapling()): x, y, z and
	 *                       whether the position is left with water
	 * @phpstan-param list<array{int, int, int, bool}> $clear
	 */
	public function place(World $world, string $feature, int $x, int $y, int $z, array $clear, int $seed) : ?FeatureGrowth{
		if(!$world->isInWorld($x, $y, $z)){
			return null;
		}
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		$manager = new SimpleChunkManager($world->getMinY(), $world->getMaxY());
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$chunk = $world->getChunk($chunkX + $dx, $chunkZ + $dz); //loaded chunks only
				if($chunk === null){
					return null;
				}
				//the region shares the world's subchunks and copies one on its first write (FeatureLevel copy-on-write),
				//so a feature that gives up early costs only its reads
				$manager->setChunk($chunkX + $dx, $chunkZ + $dz, new Chunk($chunk->getSubChunks(), $chunk->isPopulated()));
			}
		}
		try{
			$configured = $this->registry->configuredRef($feature);
		}catch(\RuntimeException $e){
			\GlobalLogger::get()->debug("Vanilla feature $feature unavailable: " . $e->getMessage());
			return null;
		}

		$level = $this->level;
		$level->begin($manager, $chunkX, $chunkZ, copyOnWrite: true);
		$level->logWrites = true;
		try{
			foreach($clear as [$cx, $cy, $cz, $water]){
				$level->setState($cx, $cy, $cz, $water ? $this->blocks->water : $this->blocks->air);
			}
			$this->random->setSeed($seed);
			if(!$configured->place($this->context, $x, $y, $z)){
				return null;
			}

			$positions = [];
			$log = $level->writeLog;
			for($i = 0, $n = count($log); $i < $n; $i += 3){ //key, Java state, PocketMine state (FeatureLevel::$writeLog)
				$positions[$log[$i] & 0x7fffffff] = true;
			}
			$registry = RuntimeBlockStateRegistry::getInstance();
			$transaction = new BlockTransaction($world);
			$size = FeatureLevel::SIZE;
			foreach($positions as $key => $_){
				$dx = $key % $size;
				$rest = intdiv($key, $size);
				$dz = $rest % $size;
				$yRel = intdiv($rest, $size);
				$stateId = $level->getRegionSubChunk(($dx >> 4) * 3 + ($dz >> 4), $yRel >> 4)->getBlockStateId($dx & 15, $yRel & 15, $dz & 15);
				$bx = $level->minX + $dx;
				$by = $level->minY + $yRel;
				$bz = $level->minZ + $dz;
				if($world->getBlockAt($bx, $by, $bz, addToCache: false)->getStateId() !== $stateId){
					$transaction->addBlockAt($bx, $by, $bz, $registry->fromStateId($stateId));
				}
			}

			$tiles = [];
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					foreach($level->getRegionChunk($dx, $dz)->getPendingTileNBT() as $nbt){
						$tiles[] = $nbt;
					}
				}
			}
			return new FeatureGrowth($transaction, $tiles);
		}finally{
			$level->logWrites = false;
			$level->writeLog = [];
			$level->finish();
		}
	}
}
