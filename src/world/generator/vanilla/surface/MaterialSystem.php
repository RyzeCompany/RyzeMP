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

use pocketmine\world\generator\vanilla\Aquifer;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\compiler\CompiledKernel;
use pocketmine\world\generator\vanilla\noise\NoiseStack;
use pocketmine\world\generator\vanilla\noise\PerlinSimplexNoise;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\surface\rule\MaterialRule;
use pocketmine\world\generator\vanilla\TerrainChunk;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function ceil;
use function floor;
use function is_string;
use function max;
use function min;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Vanilla's surface/material stage: runs the noise settings' material rule (26.3+ "material_rule": bedrock floor,
 * ore veins, biome surfaces, badlands bands, deepslate...) on every solid block of the terrain, top to bottom per
 * column, plus the hardcoded eroded badlands pillars and frozen ocean icebergs.
 */
final class MaterialSystem{
	public readonly MaterialPalette $palette;
	private readonly MaterialRule $rule;
	private readonly MaterialContext $context;
	private readonly SurfaceBiomes $biomes;
	private readonly InterpolationCache $interpolation;

	private readonly int $erodedBadlands;
	private readonly int $frozenOcean;
	private readonly int $deepFrozenOcean;
	private readonly int $snowBlock;
	private readonly int $packedIce;

	private readonly NoiseStack $badlandsPillar;
	private readonly NoiseStack $badlandsPillarRoof;
	private readonly NoiseStack $badlandsSurface;
	private readonly NoiseStack $icebergPillar;
	private readonly NoiseStack $icebergPillarRoof;
	private readonly NoiseStack $icebergSurface;
	private readonly PositionalRandomFactory $random;
	/**
	 * Base temperature and whether the "frozen" temperature modifier applies, per frozen ocean biome index.
	 * @phpstan-var array<int, array{float, bool}>
	 */
	private array $frozenOceanClimate = [];
	private ?PerlinSimplexNoise $frozenTemperatureNoise = null;
	private ?PerlinSimplexNoise $biomeInfoNoise = null;
	private int $baseX = 0;
	private int $baseZ = 0;

	/**
	 * @param string|null         $compileDir where to cache the rules compiled to PHP; null evaluates the rule objects
	 * @param CompiledKernel|null $kernel     terrain kernel, to sample the biomes around chunks faster
	 */
	public function __construct(WorldgenData $data, NoiseRouter $router, ClimateBiomeTree $tree, int $seed, ?string $compileDir = null, ?CompiledKernel $kernel = null, string $settingsId = "overworld"){
		$settings = $data->noiseSettings($settingsId);
		$this->random = $router->random;
		//own density function graph: interpolated nodes are driven by this stage's interpolation cache
		$densityParser = $router->createParser();
		$this->palette = new MaterialPalette($router->defaultBlock);
		$this->interpolation = new InterpolationCache();
		$this->biomes = new SurfaceBiomes($router, $tree, $seed, $kernel);

		$biomeIndexes = [];
		foreach($tree->getBiomeNames() as $index => $name){
			$biomeIndexes["minecraft:" . WorldgenData::stripNamespace($name)] = $index;
		}
		$this->erodedBadlands = $biomeIndexes["minecraft:eroded_badlands"] ?? -1;
		$this->frozenOcean = $biomeIndexes["minecraft:frozen_ocean"] ?? -1;
		$this->deepFrozenOcean = $biomeIndexes["minecraft:deep_frozen_ocean"] ?? -1;

		$noiseRouter = WorldgenData::array($settings, "noise_router");
		$this->context = new MaterialContext(
			$densityParser->getNoise("minecraft:surface"),
			$densityParser->getNoise("minecraft:surface_secondary"),
			$router->random,
			$densityParser->parse($noiseRouter["chunk_surface_level"] ?? 0.0),
			$this->interpolation,
			$this->biomes,
			$router->minY,
			$router->height,
			$router->seaLevel
		);
		$ruleParser = new MaterialRuleParser($data, $densityParser, $router->random, $this->palette, $biomeIndexes, $this->interpolation, $router->minY, $router->height);
		$rule = $ruleParser->rule($settings["material_rule"] ?? throw new \RuntimeException("Noise settings without a material rule (pre-26.3 data?)"));
		$this->rule = $compileDir !== null ? MaterialRuleCompiler::create($rule, $compileDir) : $rule;

		$this->snowBlock = $this->palette->register("minecraft:snow_block");
		$this->packedIce = $this->palette->register("minecraft:packed_ice");
		$this->badlandsPillar = $densityParser->getNoise("minecraft:badlands_pillar");
		$this->badlandsPillarRoof = $densityParser->getNoise("minecraft:badlands_pillar_roof");
		$this->badlandsSurface = $densityParser->getNoise("minecraft:badlands_surface");
		$this->icebergPillar = $densityParser->getNoise("minecraft:iceberg_pillar");
		$this->icebergPillarRoof = $densityParser->getNoise("minecraft:iceberg_pillar_roof");
		$this->icebergSurface = $densityParser->getNoise("minecraft:iceberg_surface");

		foreach([$this->frozenOcean, $this->deepFrozenOcean] as $index){
			if($index < 0){
				continue;
			}
			$biome = $data->biome($tree->getBiomeNames()[$index]);
			$modifier = $biome["temperature_modifier"] ?? "none";
			$this->frozenOceanClimate[$index] = [WorldgenData::float($biome, "temperature"), is_string($modifier) && WorldgenData::stripNamespace($modifier) === "frozen"];
		}
	}

	public function apply(TerrainChunk $terrain) : void{
		$c = $this->context;
		$c->beginChunk();
		$this->interpolation->clear();
		$this->biomes->beginChunk($terrain);

		$materials = $terrain->materials;
		$minY = $terrain->minY;
		$height = $terrain->height;
		$baseX = $terrain->chunkX << 4;
		$baseZ = $terrain->chunkZ << 4;
		$rule = $this->rule;

		$heights = [];
		for($column = 0; $column < 256; ++$column){
			$base = $column * $height;
			$ly = $height - 1;
			while($ly >= 0 && $materials[$base + $ly] === Aquifer::MATERIAL_AIR){
				--$ly;
			}
			$heights[$column] = $minY + $ly;
		}
		$c->heights = $heights;

		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$column = ($x << 4) | $z;
				$base = $column * $height - $minY;
				$blockX = $baseX + $x;
				$blockZ = $baseZ + $z;
				$startingHeight = $c->heights[$column] + 1;
				$biome = $this->biomes->getBiome($x, $startingHeight, $z);
				if($biome === $this->erodedBadlands){
					$this->erodedBadlandsExtension($materials, $c, $column, $base, $blockX, $blockZ, $startingHeight);
				}

				$c->updateXZ($x, $z, $blockX, $blockZ);
				$stoneDepthAbove = 0;
				$waterHeight = PHP_INT_MIN;
				$nextCeilingStoneY = PHP_INT_MAX;
				for($y = $c->heights[$column]; $y >= $minY; --$y){
					$material = $materials[$base + $y];
					if($material === Aquifer::MATERIAL_AIR){
						$stoneDepthAbove = 0;
						$waterHeight = PHP_INT_MIN;
						continue;
					}
					if($material === Aquifer::MATERIAL_WATER || $material === Aquifer::MATERIAL_LAVA){
						if($waterHeight === PHP_INT_MIN){
							$waterHeight = $y + 1;
						}
						continue;
					}
					if($nextCeilingStoneY >= $y){
						$nextCeilingStoneY = $minY;
						for($ly = $y - 1; $ly >= $minY; --$ly){
							$below = $materials[$base + $ly];
							if($below === Aquifer::MATERIAL_AIR || $below === Aquifer::MATERIAL_WATER || $below === Aquifer::MATERIAL_LAVA){
								$nextCeilingStoneY = $ly + 1;
								break;
							}
						}
					}
					//inlined MaterialContext::updateY()
					++$c->blockStamp;
					$c->stoneDepthAbove = ++$stoneDepthAbove;
					$c->stoneDepthBelow = $y - $nextCeilingStoneY + 1;
					$c->waterHeight = $waterHeight;
					$c->blockY = $y;
					$result = $rule->apply($c);
					if($result !== MaterialRule::NONE){
						$materials[$base + $y] = $result;
						if($result === Aquifer::MATERIAL_AIR && $y === $c->heights[$column]){
							$c->heights[$column] = $this->topBlock($materials, $base, $y - 1, $minY);
						}
					}
				}

				if($biome === $this->frozenOcean || $biome === $this->deepFrozenOcean){
					$this->frozenOceanExtension($materials, $c, $column, $base, $blockX, $blockZ, $startingHeight, $biome);
				}
			}
		}
		$terrain->materials = $materials;
		$this->baseX = $baseX;
		$this->baseZ = $baseZ;
	}

	/**
	 * Highest non-air block per column ((x << 4) | z) of the chunk the rules were last applied to.
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function getHeights() : array{
		return $this->context->heights;
	}

	/**
	 * SurfaceSystem.topMaterial(): the material rule result for a block of the chunk the rules were last applied to,
	 * as a floor block with nothing solid around (stone depth 1 above and below), optionally under a fluid. Used by
	 * the carvers on dirt uncovered under grass. Returns a palette id or MaterialRule::NONE.
	 *
	 * @param int[] $heights current column heights (see getHeights())
	 * @phpstan-param array<int, int> $heights
	 */
	public function topMaterial(array $heights, int $localX, int $y, int $localZ, bool $fluid) : int{
		$c = $this->context;
		$c->heights = $heights;
		$c->updateXZ($localX, $localZ, $this->baseX + $localX, $this->baseZ + $localZ);
		$c->updateY(1, 1, $fluid ? $y + 1 : PHP_INT_MIN, $y);
		return $this->rule->apply($c);
	}

	/**
	 * @param int[] $materials
	 * @phpstan-param array<int, int> $materials
	 */
	private function topBlock(array $materials, int $base, int $fromY, int $minY) : int{
		for($y = $fromY; $y >= $minY; --$y){
			if($materials[$base + $y] !== Aquifer::MATERIAL_AIR){
				return $y;
			}
		}
		return $minY - 1;
	}

	/**
	 * Stone pillars ("hoodoos") of eroded badlands, turned into terracotta bands by the rules afterwards.
	 *
	 * @param int[] $materials
	 * @phpstan-param array<int, int> $materials
	 */
	private function erodedBadlandsExtension(array &$materials, MaterialContext $c, int $column, int $base, int $blockX, int $blockZ, int $height) : void{
		$pillarFloor = min(
			abs($this->badlandsSurface->get3D($blockX, 0.0, $blockZ) * 8.25),
			$this->badlandsPillar->get3D($blockX * 0.2, 0.0, $blockZ * 0.2) * 15.0
		);
		if($pillarFloor <= 0.0){
			return;
		}
		$pillarRoof = abs($this->badlandsPillarRoof->get3D($blockX * 0.75, 0.0, $blockZ * 0.75) * 1.5);
		$pillarHeight = 64.0 + min($pillarFloor * $pillarFloor * 2.5, ceil($pillarRoof * 50.0) + 24.0);
		$startY = (int) floor($pillarHeight);
		if($height > $startY){
			return;
		}
		$minY = $c->minY;
		$maxY = $minY + $c->height - 1;
		for($y = $startY; $y >= $minY; --$y){
			$material = $y > $maxY ? Aquifer::MATERIAL_AIR : $materials[$base + $y];
			if($material === Aquifer::MATERIAL_STONE){
				break;
			}
			if($material === Aquifer::MATERIAL_WATER){
				return;
			}
		}
		for($y = $startY; $y >= $minY && ($y > $maxY || $materials[$base + $y] === Aquifer::MATERIAL_AIR); --$y){
			if($y <= $maxY){
				$materials[$base + $y] = Aquifer::MATERIAL_STONE;
				$c->heights[$column] = max($c->heights[$column], $y);
			}
		}
	}

	/**
	 * Icebergs of frozen oceans: packed ice (and snow on top) rising from the sea, shaped by noise.
	 *
	 * @param int[] $materials
	 * @phpstan-param array<int, int> $materials
	 */
	private function frozenOceanExtension(array &$materials, MaterialContext $c, int $column, int $base, int $blockX, int $blockZ, int $height, int $biome) : void{
		$seaLevel = $c->seaLevel;
		$noise = min(
			abs($this->icebergSurface->get3D($blockX, 0.0, $blockZ) * 8.25),
			$this->icebergPillar->get3D($blockX * 1.28, 0.0, $blockZ * 1.28) * 15.0
		);
		if($noise <= 1.8){
			return;
		}
		$roof = abs($this->icebergPillarRoof->get3D($blockX * 1.17, 0.0, $blockZ * 1.17) * 1.5);
		$icebergHeight = min($noise * $noise * 1.2, ceil($roof * 40.0) + 14.0);
		if($this->shouldMeltFrozenOceanIcebergSlightly($biome, $blockX, $blockZ)){
			$icebergHeight -= 2.0;
		}
		if($icebergHeight > 2.0){
			$icebergTop = $seaLevel - $icebergHeight - 7.0;
			$icebergHeight += $seaLevel;
		}else{
			$icebergHeight = 0.0;
			$icebergTop = 0.0;
		}
		$maxIcebergHeight = (int) $icebergHeight;
		$icebergTopY = (int) $icebergTop;
		$random = $this->random->at($blockX, 0, $blockZ);
		$maxSnowBlocks = 2 + $random->nextBoundedInt(4);
		$snowBottom = $seaLevel + 18 + $random->nextBoundedInt(10);
		$snowBlocks = 0;
		$minSurfaceLevel = $c->minSurfaceLevel();
		$minY = $c->minY;
		$maxY = $minY + $c->height - 1;
		for($y = max($height, $maxIcebergHeight + 1); $y >= $minSurfaceLevel; --$y){
			if($y < $minY){
				break;
			}
			$material = $y > $maxY ? Aquifer::MATERIAL_AIR : $materials[$base + $y];
			if(
				($material === Aquifer::MATERIAL_AIR && $y < $maxIcebergHeight && $random->nextDouble() > 0.01) ||
				($material === Aquifer::MATERIAL_WATER && $y > $icebergTopY && $y < $seaLevel && $icebergTop !== 0.0 && $random->nextDouble() > 0.15)
			){
				if($y > $maxY){
					continue;
				}
				if($snowBlocks <= $maxSnowBlocks && $y > $snowBottom){
					$materials[$base + $y] = $this->snowBlock;
					++$snowBlocks;
				}else{
					$materials[$base + $y] = $this->packedIce;
				}
				$c->heights[$column] = max($c->heights[$column], $y);
			}
		}
	}

	/**
	 * Biome.shouldMeltFrozenOceanIcebergSlightly(): temperature at sea level above 0.1, with the "frozen" temperature
	 * modifier (warmer 0.2 patches) applied.
	 */
	private function shouldMeltFrozenOceanIcebergSlightly(int $biome, int $blockX, int $blockZ) : bool{
		[$temperature, $frozenModifier] = $this->frozenOceanClimate[$biome] ?? [0.0, false];
		if($frozenModifier){
			$this->frozenTemperatureNoise ??= new PerlinSimplexNoise(new LegacyRandom(3456), [-2, -1, 0]);
			$this->biomeInfoNoise ??= new PerlinSimplexNoise(new LegacyRandom(2345), [0]);
			$value = $this->frozenTemperatureNoise->getValue($blockX * 0.05, $blockZ * 0.05) * 7.0 + $this->biomeInfoNoise->getValue($blockX * 0.2, $blockZ * 0.2);
			if($value < 0.3 && $this->biomeInfoNoise->getValue($blockX * 0.09, $blockZ * 0.09) < 0.8){
				$temperature = 0.2;
			}
		}
		//compared as Java floats: 0.2F > 0.1F
		return $temperature > 0.1;
	}
}
