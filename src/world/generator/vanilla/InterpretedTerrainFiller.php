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

namespace pocketmine\world\generator\vanilla;

use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use pocketmine\world\generator\vanilla\structure\Beardifier;
use function intdiv;
use function ksort;
use function spl_object_id;

/**
 * Reference terrain filler: evaluates the density function object graph directly (NoiseChunk equivalent with
 * 4x8x4 cell interpolation). Slow, but simple; the generated-code filler must produce identical output.
 */
final class InterpretedTerrainFiller implements TerrainFiller{
	/**
	 * @var InterpolatedFunction[]
	 * @phpstan-var list<InterpolatedFunction>
	 */
	private array $interpolated = [];
	private int $cellWidth = 4;
	private int $cellHeight = 8;

	public function __construct(
		private NoiseRouter $router,
		private ClimateBiomeTree $biomes,
		private bool $aquifers = true
	){
		$seen = [];
		$this->collect($router->finalDensity, $seen);
		foreach($this->interpolated as $i => $f){
			if($i === 0){
				$this->cellWidth = $f->cellWidth;
				$this->cellHeight = $f->cellHeight;
			}elseif($f->cellWidth !== $this->cellWidth || $f->cellHeight !== $this->cellHeight){
				throw new \RuntimeException("All interpolated functions of the final density must use the same cell size");
			}
		}
	}

	/**
	 * @param true[] $seen
	 * @phpstan-param array<int, true> $seen
	 */
	private function collect(DensityFunction $f, array &$seen) : void{
		$id = spl_object_id($f);
		if(isset($seen[$id])){
			return;
		}
		$seen[$id] = true;
		if($f instanceof InterpolatedFunction){
			$this->interpolated[] = $f;
			return;
		}
		foreach($f->children() as $child){
			$this->collect($child, $seen);
		}
	}

	public function fill(int $chunkX, int $chunkZ, ?Beardifier $beardifier = null) : TerrainChunk{
		$router = $this->router;
		$minY = $router->minY;
		$height = $router->height;
		$result = new TerrainChunk($chunkX, $chunkZ, $minY, $height);
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;
		$w = $this->cellWidth;
		$h = $this->cellHeight;
		$cellsXZ = intdiv(16, $w);
		$cellsY = intdiv($height, $h);
		$cornersY = $cellsY + 1;

		//corner values per interpolated function: index (cx * (cellsXZ + 1) + cz) * cornersY + cy
		$corners = [];
		foreach($this->interpolated as $k => $f){
			$input = $f->input;
			$values = [];
			for($cx = 0; $cx <= $cellsXZ; ++$cx){
				for($cz = 0; $cz <= $cellsXZ; ++$cz){
					$x = $baseX + $cx * $w;
					$z = $baseZ + $cz * $w;
					for($cy = 0; $cy < $cornersY; ++$cy){
						$values[] = $input->compute($x, $minY + $cy * $h, $z);
					}
				}
			}
			$corners[$k] = $values;
		}

		$aquifer = null;
		if($this->aquifers && $router->aquifersEnabled){
			$aquifer = new Aquifer($router, $router->random->fromHashOf("minecraft:aquifer")->forkPositional(), $chunkX, $chunkZ,
				$router->aquiferBarrier ?? throw new AssumptionFailedError(),
				$router->aquiferFloodedness ?? throw new AssumptionFailedError(),
				$router->aquiferSpread ?? throw new AssumptionFailedError(),
				$router->aquiferLava ?? throw new AssumptionFailedError(),
				$router->aquiferExclusion ?? throw new AssumptionFailedError(),
				$router->aquiferSurfaceLevel ?? throw new AssumptionFailedError()
			);
		}
		$sea = $router->seaLevel;

		$final = $router->finalDensity;
		$interpolated = $this->interpolated;
		foreach($interpolated as $f){
			$f->useCurrent = true;
		}
		$materials = [];
		try{
			for($cx = 0; $cx < $cellsXZ; ++$cx){
				for($cz = 0; $cz < $cellsXZ; ++$cz){
					$i00 = ($cx * ($cellsXZ + 1) + $cz) * $cornersY;
					$i10 = (($cx + 1) * ($cellsXZ + 1) + $cz) * $cornersY;
					$i01 = ($cx * ($cellsXZ + 1) + $cz + 1) * $cornersY;
					$i11 = (($cx + 1) * ($cellsXZ + 1) + $cz + 1) * $cornersY;
					for($cy = $cellsY - 1; $cy >= 0; --$cy){
						for($ly = $h - 1; $ly >= 0; --$ly){
							$y = $minY + $cy * $h + $ly;
							$fy = $ly / $h;
							for($lx = 0; $lx < $w; ++$lx){
								$x = $baseX + $cx * $w + $lx;
								$fx = $lx / $w;
								for($lz = 0; $lz < $w; ++$lz){
									$z = $baseZ + $cz * $w + $lz;
									$fz = $lz / $w;
									foreach($interpolated as $k => $f){
										$c = $corners[$k];
										$f->current = InterpolatedFunction::lerp3($fx, $fy, $fz,
											$c[$i00 + $cy], $c[$i10 + $cy], $c[$i00 + $cy + 1], $c[$i10 + $cy + 1],
											$c[$i01 + $cy], $c[$i11 + $cy], $c[$i01 + $cy + 1], $c[$i11 + $cy + 1]
										);
									}
									$density = $final->compute($x, $y, $z);
									if($beardifier !== null){
										$beard = $beardifier->column($x, $z, $y, $y);
										if(isset($beard[1])){
											$density = Beardifier::f($density + $beard[1][0]);
										}
									}
									if($aquifer !== null){
										$m = $aquifer->computeSubstance($x, $y, $z, $density);
									}elseif($density > 0){
										$m = -1;
									}else{
										$m = $router->globalFluid($y);
									}
									$materials[((($cx * $w + $lx) << 4) | ($cz * $w + $lz)) * $height + ($y - $minY)] = $m === -1 ? Aquifer::MATERIAL_STONE : $m;
								}
							}
						}
					}
				}
			}
		}finally{
			foreach($interpolated as $f){
				$f->useCurrent = false;
			}
		}
		ksort($materials);
		$result->materials = $materials;
		$result->biomes = $this->fillBiomes($chunkX, $chunkZ);
		return $result;
	}

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function fillBiomes(int $chunkX, int $chunkZ) : array{
		$r = $this->router;
		$tree = $this->biomes;
		$quartsY = $r->height >> 2;
		$minQuartY = $r->minY >> 2;
		$biomes = [];
		$single = $tree->getSingleBiome();
		if($single !== null){
			for($i = 0; $i < 16 * $quartsY; ++$i){
				$biomes[] = $single;
			}
			return $biomes;
		}
		for($qx = 0; $qx < 4; ++$qx){
			for($qz = 0; $qz < 4; ++$qz){
				$x = ($chunkX << 4) + ($qx << 2);
				$z = ($chunkZ << 4) + ($qz << 2);
				$t = ClimateBiomeTree::quantize($r->temperature->compute($x, 0, $z));
				$v = ClimateBiomeTree::quantize($r->vegetation->compute($x, 0, $z));
				$c = ClimateBiomeTree::quantize($r->continents->compute($x, 0, $z));
				$e = ClimateBiomeTree::quantize($r->erosion->compute($x, 0, $z));
				$w = ClimateBiomeTree::quantize($r->ridges->compute($x, 0, $z));
				$base = (($qx << 2) | $qz) * $quartsY;
				for($qy = 0; $qy < $quartsY; ++$qy){
					$d = ClimateBiomeTree::quantize($r->depth->compute($x, ($minQuartY + $qy) << 2, $z));
					$biomes[$base + $qy] = $tree->search($t, $v, $c, $e, $d, $w);
				}
			}
		}
		return $biomes;
	}
}
