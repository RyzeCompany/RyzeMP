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

use pocketmine\world\generator\vanilla\Aquifer;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\surface\rule\MaterialRule;
use function abs;
use function min;

/**
 * Carving of one chunk (the target): the ellipsoids of every tunnel that reaches it are carved into its materials,
 * in vanilla's order. Blocks are carved at most once (carving mask); bedrock and the other uncarvable blocks stay;
 * the replacement comes from the chunk's aquifer at density 0 (water, lava, air, or nothing near aquifer barriers),
 * and dirt uncovered under carved grass gets the biome's top material again.
 */
final class CarvingRun{
	/** Blocks under the world top that carvers never touch (WorldCarver: 7 unless the chunk is being upgraded). */
	private const PROTECTED_BLOCKS_ON_TOP = 7;

	public readonly float $middleX;
	public readonly float $middleZ;
	private readonly int $minBlockX;
	private readonly int $minBlockZ;
	private readonly int $carveMinY;
	private readonly int $carveMaxY;

	/**
	 * Carved blocks (same index as TerrainChunk::$materials).
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	public array $mask = [];

	/**
	 * @param int[]  $materials  TerrainChunk::$materials
	 * @param int[]  $heights    highest non-air block per column ((x << 4) | z), kept up to date
	 * @param true[] $uncarvable material ids that are never carved
	 * @phpstan-param array<int, int> $materials
	 * @phpstan-param array<int, int> $heights
	 * @phpstan-param array<int, true> $uncarvable
	 * @phpstan-param \Closure(array<int, int> $heights, int $localX, int $y, int $localZ, bool $fluid) : int $topMaterial
	 */
	public function __construct(
		int $chunkX,
		int $chunkZ,
		private readonly int $minY,
		private readonly int $height,
		private readonly int $seaLevel,
		public array $materials,
		public array $heights,
		private readonly ?Aquifer $aquifer,
		private readonly array $uncarvable,
		private readonly int $grass,
		private readonly int $mycelium,
		private readonly int $dirt,
		private readonly \Closure $topMaterial,
		private readonly int $seaMaterial = Aquifer::MATERIAL_WATER
	){
		$this->minBlockX = $chunkX << 4;
		$this->minBlockZ = $chunkZ << 4;
		$this->middleX = (float) ($this->minBlockX + 8);
		$this->middleZ = (float) ($this->minBlockZ + 8);
		$this->carveMinY = $minY + 1;
		$this->carveMaxY = $minY + $height - 1 - self::PROTECTED_BLOCKS_ON_TOP;
	}

	/**
	 * WorldCarver.carveEllipsoid(): carves the blocks of the target chunk inside the ellipsoid, column by column, top
	 * to bottom.
	 *
	 * @param float[]|null $widthFactors canyon: squared width factor per height (index y - minY); null for caves,
	 *                                   which skip blocks at or below $floorLevel (relative to the vertical radius)
	 * @phpstan-param list<float>|null $widthFactors
	 */
	public function carveEllipsoid(float $x, float $y, float $z, float $horizontalRadius, float $verticalRadius, float $floorLevel, ?array $widthFactors) : void{
		$limit = 16.0 + $horizontalRadius * 2.0;
		if(abs($x - $this->middleX) > $limit || abs($z - $this->middleZ) > $limit){
			return;
		}
		$minX = CarverMath::floor($x - $horizontalRadius) - $this->minBlockX - 1;
		if($minX < 0){
			$minX = 0;
		}
		$maxX = min(CarverMath::floor($x + $horizontalRadius) - $this->minBlockX, 15);
		$minYExclusive = CarverMath::floor($y - $verticalRadius) - 1;
		if($minYExclusive < $this->carveMinY){
			$minYExclusive = $this->carveMinY;
		}
		$maxY = min(CarverMath::floor($y + $verticalRadius) + 1, $this->carveMaxY);
		$minZ = CarverMath::floor($z - $horizontalRadius) - $this->minBlockZ - 1;
		if($minZ < 0){
			$minZ = 0;
		}
		$maxZ = min(CarverMath::floor($z + $horizontalRadius) - $this->minBlockZ, 15);

		$minY = $this->minY;
		$height = $this->height;
		for($lx = $minX; $lx <= $maxX; ++$lx){
			$dx = ($this->minBlockX + $lx + 0.5 - $x) / $horizontalRadius;
			for($lz = $minZ; $lz <= $maxZ; ++$lz){
				$dz = ($this->minBlockZ + $lz + 0.5 - $z) / $horizontalRadius;
				if($dx * $dx + $dz * $dz >= 1.0){
					continue;
				}
				$column = ($lx << 4) | $lz;
				$base = $column * $height - $minY;
				$reachedSurface = false;
				for($by = $maxY; $by > $minYExclusive; --$by){
					$dy = ($by - 0.5 - $y) / $verticalRadius;
					if($widthFactors === null){
						if($dy <= $floorLevel || $dx * $dx + $dy * $dy + $dz * $dz >= 1.0){
							continue;
						}
					}elseif(($dx * $dx + $dz * $dz) * $widthFactors[$by - $minY - 1] + $dy * $dy / 6.0 >= 1.0){
						continue;
					}
					$index = $base + $by;
					if(isset($this->mask[$index])){
						continue;
					}
					$this->mask[$index] = true;
					$this->carveBlock($index, $column, $lx, $by, $lz, $reachedSurface);
				}
			}
		}
	}

	private function carveBlock(int $index, int $column, int $lx, int $y, int $lz, bool &$reachedSurface) : void{
		$material = $this->materials[$index];
		if($material === $this->grass || $material === $this->mycelium){
			$reachedSurface = true;
		}
		if(isset($this->uncarvable[$material])){
			return;
		}
		if($this->aquifer !== null){
			$state = $this->aquifer->computeSubstance($this->minBlockX + $lx, $y, $this->minBlockZ + $lz, 0.0);
			if($state === -1){
				return;
			}
		}else{
			$state = $y < min(NoiseRouter::LAVA_LEVEL, $this->seaLevel) ? Aquifer::MATERIAL_LAVA : ($y < $this->seaLevel ? $this->seaMaterial : Aquifer::MATERIAL_AIR);
		}
		$this->setBlock($index, $column, $y, $state);
		if($reachedSurface && $y - 1 >= $this->minY && $this->materials[$index - 1] === $this->dirt){
			$top = ($this->topMaterial)($this->heights, $lx, $y - 1, $lz, $state === Aquifer::MATERIAL_WATER || $state === Aquifer::MATERIAL_LAVA);
			if($top !== MaterialRule::NONE){
				$this->setBlock($index - 1, $column, $y - 1, $top);
			}
		}
	}

	/**
	 * Sets a block and updates the column height like vanilla's WORLD_SURFACE_WG heightmap.
	 */
	private function setBlock(int $index, int $column, int $y, int $material) : void{
		$this->materials[$index] = $material;
		$top = $this->heights[$column];
		if($material !== Aquifer::MATERIAL_AIR){
			if($y > $top){
				$this->heights[$column] = $y;
			}
		}elseif($y === $top){
			$base = $index - $y;
			$minY = $this->minY;
			for($by = $y - 1; $by >= $minY; --$by){
				if($this->materials[$base + $by] !== Aquifer::MATERIAL_AIR){
					$this->heights[$column] = $by;
					return;
				}
			}
			$this->heights[$column] = $minY - 1;
		}
	}
}
