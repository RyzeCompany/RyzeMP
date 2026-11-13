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
use pocketmine\world\generator\vanilla\compiler\CompiledKernel;
use pocketmine\world\generator\vanilla\compiler\KernelFactory;
use pocketmine\world\generator\vanilla\compiler\KernelFunction;
use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\structure\Beardifier;
use function count;

/**
 * Terrain filler running the generated kernel (see {@link KernelFactory}): same output as
 * {@link InterpretedTerrainFiller}, several times faster.
 */
final class CompiledTerrainFiller implements TerrainFiller{
	private CompiledKernel $kernel;
	/** @phpstan-var array{DensityFunction, DensityFunction, DensityFunction, DensityFunction, DensityFunction, DensityFunction}|null */
	private ?array $aquiferFunctions = null;

	public function __construct(
		private NoiseRouter $router,
		private ClimateBiomeTree $biomes,
		string $cacheDir,
		private bool $aquifers = true
	){
		$this->kernel = $kernel = KernelFactory::create($router, $cacheDir);
		if($router->aquifersEnabled){
			$f = fn(int $role, ?DensityFunction $original) : KernelFunction => new KernelFunction($kernel, $role, $original ?? throw new AssumptionFailedError());
			$this->aquiferFunctions = [
				$f(CompiledKernel::ROLE_BARRIER, $router->aquiferBarrier),
				$f(CompiledKernel::ROLE_FLOODEDNESS, $router->aquiferFloodedness),
				$f(CompiledKernel::ROLE_SPREAD, $router->aquiferSpread),
				$f(CompiledKernel::ROLE_LAVA, $router->aquiferLava),
				$f(CompiledKernel::ROLE_EXCLUSION, $router->aquiferExclusion),
				$f(CompiledKernel::ROLE_SURFACE_LEVEL, $router->aquiferSurfaceLevel),
			];
		}
	}

	public function getKernel() : CompiledKernel{
		return $this->kernel;
	}

	public function fill(int $chunkX, int $chunkZ, ?Beardifier $beardifier = null) : TerrainChunk{
		$router = $this->router;
		$minY = $router->minY;
		$height = $router->height;
		$baseX = $chunkX << 4;
		$baseZ = $chunkZ << 4;
		$result = new TerrainChunk($chunkX, $chunkZ, $minY, $height);

		$density = $this->kernel->fillDensity($baseX, $baseZ);

		$aquifer = null;
		if($this->aquifers && $this->aquiferFunctions !== null){
			[$barrier, $floodedness, $spread, $lava, $exclusion, $surface] = $this->aquiferFunctions;
			$aquifer = new Aquifer($router, $router->random->fromHashOf("minecraft:aquifer")->forkPositional(), $chunkX, $chunkZ,
				$barrier, $floodedness, $spread, $lava, $exclusion, $surface);
		}
		$sea = $router->seaLevel;
		$seaMaterial = $router->seaMaterial;
		$lavaLevel = NoiseRouter::LAVA_LEVEL < $sea ? NoiseRouter::LAVA_LEVEL : $sea;
		//aquifers are only sampled between the lava floor and the highest possible local fluid level; everything
		//else gets the global fluid (same result as Aquifer::computeSubstance(), without the call)
		$skipAbove = $aquifer !== null ? $aquifer->getSkipSamplingAboveY() : $minY - 1;
		$materials = [];
		$i = 0;
		for($bx = 0; $bx < 16; ++$bx){
			$x = $baseX + $bx;
			for($bz = 0; $bz < 16; ++$bz){
				$z = $baseZ + $bz;
				//Beardifier (the final density's "beardifier" term, 0 in the kernel), for the affected part of the column
				$beard = $beardifier?->column($x, $z, $minY, $minY + $height - 1) ?? [];
				if(count($beard) > 0){
					[$beardFrom, $beardValues] = $beard;
					foreach($beardValues as $k => $value){
						$j = $i + $beardFrom - $minY + $k;
						$density[$j] = Beardifier::f($density[$j] + $value);
					}
				}
				for($ly = 0; $ly < $height; ++$ly){
					$d = $density[$i];
					if($d > 0){
						$materials[] = Aquifer::MATERIAL_STONE;
					}else{
						$y = $minY + $ly;
						if($y < $lavaLevel){
							//$lavaLevel <= -54: the global lava fluid is present at every y below it
							$materials[] = Aquifer::MATERIAL_LAVA;
						}elseif($aquifer === null || $y > $skipAbove){
							$materials[] = $y < $sea ? $seaMaterial : Aquifer::MATERIAL_AIR;
						}else{
							$m = $aquifer->computeSubstance($x, $y, $z, $d);
							$materials[] = $m === -1 ? Aquifer::MATERIAL_STONE : $m;
						}
					}
					++$i;
				}
			}
		}
		$result->materials = $materials;
		$result->biomes = $this->fillBiomes($chunkX, $chunkZ);
		return $result;
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function fillBiomes(int $chunkX, int $chunkZ) : array{
		$r = $this->router;
		$k = $this->kernel;
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
				$x = (float) (($chunkX << 4) + ($qx << 2));
				$z = (float) (($chunkZ << 4) + ($qz << 2));
				$t = ClimateBiomeTree::quantize($k->temperature($x, 0.0, $z));
				$v = ClimateBiomeTree::quantize($k->vegetation($x, 0.0, $z));
				$c = ClimateBiomeTree::quantize($k->continents($x, 0.0, $z));
				$e = ClimateBiomeTree::quantize($k->erosion($x, 0.0, $z));
				$w = ClimateBiomeTree::quantize($k->ridges($x, 0.0, $z));
				for($qy = 0; $qy < $quartsY; ++$qy){
					$d = ClimateBiomeTree::quantize($k->depth($x, (float) (($minQuartY + $qy) << 2), $z));
					$biomes[] = $tree->search($t, $v, $c, $e, $d, $w);
				}
			}
		}
		return $biomes;
	}
}
