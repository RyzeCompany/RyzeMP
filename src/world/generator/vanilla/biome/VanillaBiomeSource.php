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

namespace pocketmine\world\generator\vanilla\biome;

use pocketmine\world\generator\vanilla\compiler\CompiledKernel;
use pocketmine\world\generator\vanilla\compiler\KernelFactory;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_map;

/**
 * The biomes of a vanilla-generated dimension computed straight from the climate noise, without generating chunks
 * (what /locate biome searches). Gives the same biomes as the generator: the multi-noise biome tree for the Overworld
 * and the Nether, the End islands source for the End.
 */
final class VanillaBiomeSource{
	private NoiseRouter $router;
	private ?ClimateBiomeTree $tree = null;
	private ?TheEndBiomeSource $end = null;
	private ?CompiledKernel $kernel = null;
	/**
	 * Biome names (without namespace) by biome index.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $names;

	/**
	 * @param string|null $kernelCacheDir directory for the compiled noise kernel (faster sampling); null samples the
	 *                                    density functions directly
	 */
	public function __construct(WorldgenData $data, int $seed, string $dimension = "overworld", ?string $kernelCacheDir = null){
		$this->router = new NoiseRouter($data, $seed, $dimension);
		if($dimension === "end"){
			$this->end = new TheEndBiomeSource($this->router->erosion);
			$names = TheEndBiomeSource::NAMES;
		}else{
			$this->tree = ClimateBiomeTree::fromData($data, $dimension);
			$names = $this->tree->getBiomeNames();
			if($kernelCacheDir !== null){
				$this->kernel = KernelFactory::create($this->router, $kernelCacheDir);
			}
		}
		$this->names = array_map(fn(string $name) : string => WorldgenData::stripNamespace($name), $names);
	}

	/**
	 * Biome names (Java Edition ids without namespace, e.g. "desert") by biome index.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getBiomeNames() : array{
		return $this->names;
	}

	public function getMinY() : int{
		return $this->router->minY;
	}

	public function getMaxY() : int{
		return $this->router->minY + $this->router->height - 1;
	}

	/**
	 * Biome index at the given block position (sampled at its quart position, like the generator).
	 */
	public function getBiome(int $x, int $y, int $z) : int{
		return $this->getBiomesInColumn($x, $z, [$y])[0];
	}

	/**
	 * Biome indexes at several heights of one block column: the horizontal climate is sampled once.
	 *
	 * @param int[] $ys
	 * @phpstan-param list<int> $ys
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getBiomesInColumn(int $x, int $z, array $ys) : array{
		$qx = $x >> 2;
		$qz = $z >> 2;
		if($this->end !== null){
			$biome = $this->end->getNoiseBiome($qx, 0, $qz);
			return array_map(fn(int $y) : int => $biome, $ys);
		}
		$tree = $this->tree ?? throw new \LogicException("Multi-noise dimension without a biome tree");
		$fx = (float) ($qx << 2);
		$fz = (float) ($qz << 2);
		$kernel = $this->kernel;
		$router = $this->router;
		if($kernel !== null){
			$t = ClimateBiomeTree::quantize($kernel->temperature($fx, 0.0, $fz));
			$v = ClimateBiomeTree::quantize($kernel->vegetation($fx, 0.0, $fz));
			$c = ClimateBiomeTree::quantize($kernel->continents($fx, 0.0, $fz));
			$e = ClimateBiomeTree::quantize($kernel->erosion($fx, 0.0, $fz));
			$r = ClimateBiomeTree::quantize($kernel->ridges($fx, 0.0, $fz));
		}else{
			$t = ClimateBiomeTree::quantize($router->temperature->compute($fx, 0.0, $fz));
			$v = ClimateBiomeTree::quantize($router->vegetation->compute($fx, 0.0, $fz));
			$c = ClimateBiomeTree::quantize($router->continents->compute($fx, 0.0, $fz));
			$e = ClimateBiomeTree::quantize($router->erosion->compute($fx, 0.0, $fz));
			$r = ClimateBiomeTree::quantize($router->ridges->compute($fx, 0.0, $fz));
		}
		$result = [];
		foreach($ys as $y){
			$fy = (float) (($y >> 2) << 2);
			$depth = $kernel !== null ? $kernel->depth($fx, $fy, $fz) : $router->depth->compute($fx, $fy, $fz);
			$result[] = $tree->search($t, $v, $c, $e, ClimateBiomeTree::quantize($depth), $r);
		}
		return $result;
	}
}
