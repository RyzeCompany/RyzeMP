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

namespace pocketmine\world\generator\vanilla\surface;

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\noise\NoiseStack;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use function floor;
use function max;
use function min;
use function spl_object_id;
use const PHP_INT_MIN;

/**
 * State of the block being decorated by the material rules (vanilla's material rule context): position, depth of
 * solid blocks above/below, fluid level above, the column's surface depth noise, plus lazily computed column and
 * block values (secondary surface noise, preliminary surface level, steepness, biome).
 */
final class MaterialContext{
	public int $blockX = 0;
	public int $blockY = 0;
	public int $blockZ = 0;
	public int $localX = 0;
	public int $localZ = 0;
	public int $stoneDepthAbove = 0;
	public int $stoneDepthBelow = 0;
	public int $waterHeight = PHP_INT_MIN;
	/** Per column: (int) (surface noise * 2.75 + 3 + random * 0.25) */
	public int $surfaceDepth = 0;

	/** Incremented for every chunk / column / block, for caches. */
	public int $chunkStamp = 0;
	public int $columnStamp = 0;
	public int $blockStamp = 0;

	/**
	 * Highest non-air block per column ((x << 4) | z), like the WORLD_SURFACE_WG heightmap; kept up to date while
	 * blocks are changed.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	public array $heights = [];

	private float $surfaceSecondary = 0.0;
	private int $surfaceSecondaryStamp = -1;
	private int $minSurfaceLevel = 0;
	private int $minSurfaceLevelStamp = -1;
	private bool $steep = false;
	private int $steepStamp = -1;
	private int $biome = 0;
	private int $biomeStamp = -1;
	/**
	 * @var float[]
	 * @phpstan-var array<int, float>
	 */
	private array $noise2d = [];
	/**
	 * @var float[]
	 * @phpstan-var array<int, float>
	 */
	private array $noise3d = [];
	/**
	 * Block stamp of each $noise3d value.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $noise3dStamps = [];

	/**
	 * @var InterpolatedFunction[]
	 * @phpstan-var array<int, InterpolatedFunction>
	 */
	private readonly array $chunkSurfaceLevelNodes;

	public function __construct(
		private readonly NoiseStack $surfaceNoise,
		private readonly NoiseStack $surfaceSecondaryNoise,
		private readonly PositionalRandomFactory $random,
		private readonly DensityFunction $chunkSurfaceLevel,
		public readonly InterpolationCache $interpolation,
		public readonly SurfaceBiomes $biomes,
		public readonly int $minY,
		public readonly int $height,
		public readonly int $seaLevel
	){
		$this->chunkSurfaceLevelNodes = InterpolationCache::collect($chunkSurfaceLevel);
	}

	public function beginChunk() : void{
		++$this->chunkStamp;
	}

	public function updateXZ(int $localX, int $localZ, int $blockX, int $blockZ) : void{
		++$this->columnStamp;
		++$this->blockStamp;
		$this->localX = $localX;
		$this->localZ = $localZ;
		$this->blockX = $blockX;
		$this->blockZ = $blockZ;
		$this->noise2d = [];
		$this->surfaceDepth = (int) ($this->surfaceNoise->get3D($blockX, 0.0, $blockZ) * 2.75 + 3.0 + $this->random->at($blockX, 0, $blockZ)->nextDouble() * 0.25);
	}

	/**
	 * Moves to another block of the column (MaterialSystem sets the same fields inline in its loop).
	 */
	public function updateY(int $stoneDepthAbove, int $stoneDepthBelow, int $waterHeight, int $y) : void{
		++$this->blockStamp;
		$this->stoneDepthAbove = $stoneDepthAbove;
		$this->stoneDepthBelow = $stoneDepthBelow;
		$this->waterHeight = $waterHeight;
		$this->blockY = $y;
	}

	public function surfaceSecondary() : float{
		if($this->surfaceSecondaryStamp !== $this->columnStamp){
			$this->surfaceSecondaryStamp = $this->columnStamp;
			$this->surfaceSecondary = $this->surfaceSecondaryNoise->get3D($this->blockX, 0.0, $this->blockZ);
		}
		return $this->surfaceSecondary;
	}

	/** Preliminary surface level of the column (interpolated per chunk) + surface depth - 8. */
	public function minSurfaceLevel() : int{
		if($this->minSurfaceLevelStamp !== $this->columnStamp){
			$this->minSurfaceLevelStamp = $this->columnStamp;
			$this->interpolation->inject($this->chunkSurfaceLevelNodes, $this->blockX, 0, $this->blockZ);
			$this->minSurfaceLevel = (int) floor($this->chunkSurfaceLevel->compute($this->blockX, 0.0, $this->blockZ)) + $this->surfaceDepth - 8;
		}
		return $this->minSurfaceLevel;
	}

	/** Steep slope facing south (along z) or west (along x), from the heights of the neighbouring columns in the chunk. */
	public function steep() : bool{
		if($this->steepStamp !== $this->columnStamp){
			$this->steepStamp = $this->columnStamp;
			$x = $this->localX;
			$z = $this->localZ;
			$h = $this->heights;
			$north = $h[($x << 4) | max($z - 1, 0)];
			$south = $h[($x << 4) | min($z + 1, 15)];
			if($south >= $north + 4){
				$this->steep = true;
			}else{
				$west = $h[(max($x - 1, 0) << 4) | $z];
				$east = $h[(min($x + 1, 15) << 4) | $z];
				$this->steep = $west >= $east + 4;
			}
		}
		return $this->steep;
	}

	public function biome() : int{
		if($this->biomeStamp !== $this->blockStamp){
			$this->biomeStamp = $this->blockStamp;
			$this->biome = $this->biomes->getBiome($this->localX, $this->blockY, $this->localZ);
		}
		return $this->biome;
	}

	public function noise2d(NoiseStack $noise) : float{
		return $this->noise2d[spl_object_id($noise)] ??= $noise->get3D($this->blockX, 0.0, $this->blockZ);
	}

	public function noise3d(NoiseStack $noise) : float{
		$id = spl_object_id($noise);
		if(($this->noise3dStamps[$id] ?? -1) !== $this->blockStamp){
			$this->noise3dStamps[$id] = $this->blockStamp;
			$this->noise3d[$id] = $noise->get3D($this->blockX, $this->blockY, $this->blockZ);
		}
		return $this->noise3d[$id];
	}
}
