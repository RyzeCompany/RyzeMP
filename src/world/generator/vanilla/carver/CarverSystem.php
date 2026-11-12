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

namespace pocketmine\world\generator\vanilla\carver;

use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\generator\vanilla\Aquifer;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\compiler\CompiledKernel;
use pocketmine\world\generator\vanilla\compiler\KernelFunction;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use pocketmine\world\generator\vanilla\TerrainChunk;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\World;
use function array_key_first;
use function count;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Vanilla's carver stage (ChunkGenerator.applyCarvers()), after the material rules: every chunk within 8 chunks of
 * the target may start tunnels (caves, canyons) according to the carvers of its biome; each carver of the list gets
 * its own java.util.Random seeded with the large feature seed (world seed + carver index, chunk position) and starts
 * when its first float is within the carver's probability.
 */
final class CarverSystem{
	/** Start chunks considered around the target: the carvers' range * 2 (applyCarvers uses 8). */
	public const RADIUS = 8;
	private const BIOME_CACHE_SIZE = 4096;

	/**
	 * Carvers per biome index (ClimateBiomeTree names).
	 * @var WorldCarver[][]
	 * @phpstan-var array<int, list<WorldCarver>>
	 */
	private array $biomeCarvers = [];
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $uncarvable = [];
	private int $grass;
	private int $mycelium;
	private int $dirt;
	/**
	 * Biome index of start chunks (World::chunkHash), searched without the tree's last-result hint so that the value
	 * does not depend on what was generated before.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $biomeCache = [];
	/** @phpstan-var array{DensityFunction, DensityFunction, DensityFunction, DensityFunction, DensityFunction, DensityFunction}|null */
	private ?array $aquiferFunctions = null;

	private function __construct(
		private readonly NoiseRouter $router,
		private readonly ClimateBiomeTree $tree,
		private readonly int $seed,
		private readonly MaterialSystem $materials,
		private readonly ?CompiledKernel $kernel
	){}

	/**
	 * Returns null when the data has no carver definitions (cache made before 26.3 / without worldgen/carver) or no
	 * biome has carvers (the End).
	 */
	public static function create(WorldgenData $data, NoiseRouter $router, ClimateBiomeTree $tree, int $seed, MaterialSystem $materials, ?CompiledKernel $kernel) : ?self{
		if(!$data->hasCarvers()){
			return null;
		}
		$system = new self($router, $tree, $seed, $materials, $kernel);
		$palette = $materials->palette;
		$any = false;
		/** @phpstan-var array<string, WorldCarver> $carvers */
		$carvers = [];
		foreach($tree->getBiomeNames() as $index => $name){
			$list = $data->biome($name)["carvers"] ?? [];
			if(is_string($list)){
				$list = [$list];
			}
			if(!is_array($list)){
				throw new \RuntimeException("Invalid carver list of biome $name");
			}
			$biomeCarvers = [];
			foreach($list as $id){
				if(!is_string($id) || str_starts_with($id, "#")){
					throw new \RuntimeException("Unsupported carver reference in biome $name");
				}
				$key = "minecraft:" . WorldgenData::stripNamespace($id);
				$biomeCarvers[] = $carvers[$key] ??= WorldCarver::fromJson($key, $data->carver($key), $router->minY, $router->height);
			}
			$system->biomeCarvers[$index] = $biomeCarvers;
			$any = $any || count($biomeCarvers) > 0;
		}
		if(!$any){
			return null;
		}
		foreach($data->blockTag("minecraft:uncarvable") ?? ["minecraft:bedrock"] as $block){
			$material = $palette->get($block);
			if($material !== null){
				$system->uncarvable[$material] = true;
			}
		}
		$system->grass = $palette->get("minecraft:grass_block") ?? -1;
		$system->mycelium = $palette->get("minecraft:mycelium") ?? -1;
		$system->dirt = $palette->get("minecraft:dirt") ?? -1;
		if($router->aquifersEnabled){
			$k = $kernel;
			$f = fn(int $role, ?DensityFunction $original) : DensityFunction => $k !== null ?
				new KernelFunction($k, $role, $original ?? throw new AssumptionFailedError()) :
				($original ?? throw new AssumptionFailedError());
			$system->aquiferFunctions = [
				$f(CompiledKernel::ROLE_BARRIER, $router->aquiferBarrier),
				$f(CompiledKernel::ROLE_FLOODEDNESS, $router->aquiferFloodedness),
				$f(CompiledKernel::ROLE_SPREAD, $router->aquiferSpread),
				$f(CompiledKernel::ROLE_LAVA, $router->aquiferLava),
				$f(CompiledKernel::ROLE_EXCLUSION, $router->aquiferExclusion),
				$f(CompiledKernel::ROLE_SURFACE_LEVEL, $router->aquiferSurfaceLevel),
			];
		}
		return $system;
	}

	/**
	 * Carves the chunk (whose material rules must have just been applied by the MaterialSystem given to create()).
	 * The carved blocks are stored in TerrainChunk::$carvingMask.
	 */
	public function apply(TerrainChunk $terrain) : void{
		$router = $this->router;
		$chunkX = $terrain->chunkX;
		$chunkZ = $terrain->chunkZ;
		$aquifer = null;
		if($this->aquiferFunctions !== null){
			[$barrier, $floodedness, $spread, $lava, $exclusion, $surface] = $this->aquiferFunctions;
			$aquifer = new Aquifer($router, $router->random->fromHashOf("minecraft:aquifer")->forkPositional(), $chunkX, $chunkZ,
				$barrier, $floodedness, $spread, $lava, $exclusion, $surface);
		}
		$materials = $this->materials;
		$run = new CarvingRun(
			$chunkX, $chunkZ, $router->minY, $router->height, $router->seaLevel,
			$terrain->materials, $materials->getHeights(), $aquifer, $this->uncarvable, $this->grass, $this->mycelium, $this->dirt,
			fn(array $heights, int $localX, int $y, int $localZ, bool $fluid) : int => $materials->topMaterial($heights, $localX, $y, $localZ, $fluid),
			$router->seaMaterial
		);
		$random = new LegacyRandom(0);
		for($dx = -self::RADIUS; $dx <= self::RADIUS; ++$dx){
			for($dz = -self::RADIUS; $dz <= self::RADIUS; ++$dz){
				$startX = $chunkX + $dx;
				$startZ = $chunkZ + $dz;
				foreach($this->startCarvers($startX, $startZ) as $index => $carver){
					self::setLargeFeatureSeed($random, Int64::add($this->seed, $index), $startX, $startZ);
					if($carver->isStartChunk($random)){
						$carver->carve($run, $random, $startX, $startZ);
					}
				}
			}
		}
		$terrain->materials = $run->materials;
		$terrain->carvingMask = $run->mask;
	}

	/**
	 * Carvers that chunk ($chunkX, $chunkZ) may start, in the order of its biome's carver list.
	 * @return WorldCarver[]
	 * @phpstan-return list<WorldCarver>
	 */
	public function startCarvers(int $chunkX, int $chunkZ) : array{
		return $this->biomeCarvers[$this->startBiome($chunkX, $chunkZ)] ?? [];
	}

	/** WorldgenRandom.setLargeFeatureSeed() */
	public static function setLargeFeatureSeed(LegacyRandom $random, int $seed, int $chunkX, int $chunkZ) : void{
		$random->setSeed($seed);
		$a = $random->nextLong();
		$b = $random->nextLong();
		$random->setSeed(Int64::mul($chunkX, $a) ^ Int64::mul($chunkZ, $b) ^ $seed);
	}

	/**
	 * Biome of a start chunk: the noise biome at its minimum block x/z and y 0.
	 */
	private function startBiome(int $chunkX, int $chunkZ) : int{
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->biomeCache[$hash])){
			return $this->biomeCache[$hash];
		}
		$x = (float) ($chunkX << 4);
		$z = (float) ($chunkZ << 4);
		$k = $this->kernel;
		$r = $this->router;
		if($k !== null){
			$biome = $this->tree->searchIsolated(
				ClimateBiomeTree::quantize($k->temperature($x, 0.0, $z)),
				ClimateBiomeTree::quantize($k->vegetation($x, 0.0, $z)),
				ClimateBiomeTree::quantize($k->continents($x, 0.0, $z)),
				ClimateBiomeTree::quantize($k->erosion($x, 0.0, $z)),
				ClimateBiomeTree::quantize($k->depth($x, 0.0, $z)),
				ClimateBiomeTree::quantize($k->ridges($x, 0.0, $z))
			);
		}else{
			$biome = $this->tree->searchIsolated(
				ClimateBiomeTree::quantize($r->temperature->compute($x, 0.0, $z)),
				ClimateBiomeTree::quantize($r->vegetation->compute($x, 0.0, $z)),
				ClimateBiomeTree::quantize($r->continents->compute($x, 0.0, $z)),
				ClimateBiomeTree::quantize($r->erosion->compute($x, 0.0, $z)),
				ClimateBiomeTree::quantize($r->depth->compute($x, 0.0, $z)),
				ClimateBiomeTree::quantize($r->ridges->compute($x, 0.0, $z))
			);
		}
		if(count($this->biomeCache) >= self::BIOME_CACHE_SIZE){
			unset($this->biomeCache[array_key_first($this->biomeCache)]);
		}
		return $this->biomeCache[$hash] = $biome;
	}
}
