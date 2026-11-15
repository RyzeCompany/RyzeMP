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

use PHPUnit\Framework\TestCase;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\density\CubicSpline;
use function getenv;
use function implode;
use function is_dir;
use function md5;
use function sys_get_temp_dir;

/**
 * Compares the generator with deepslate on the real worldgen data. The data is Mojang's and is not part of the
 * repository: set POCKETMINE_VANILLA_WORLDGEN_DATA to a directory filled by tools/fetch-vanilla-worldgen-data.php.
 */
class VanillaWorldgenTest extends TestCase{
	private const CHARS = [".", "X", "~", "L"];

	private static function data() : WorldgenData{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return new WorldgenData($dir);
	}

	protected function tearDown() : void{
		CubicSpline::$vanillaFloats = true;
	}

	public function testRouterAndBiomes() : void{
		$data = self::data();
		$tree = ClimateBiomeTree::fromData($data);
		$names = $tree->getBiomeNames();
		//deepslate keeps spline constants and coordinates as doubles; use the same convention to compare exactly
		CubicSpline::$vanillaFloats = false;
		foreach(Utils::stringifyKeys(FixtureLoader::worldgen()) as $seed => $fixture){
			$router = new NoiseRouter($data, (int) $seed);
			foreach($fixture["router"] as $row){
				[$x, $y, $z] = $row;
				$values = [$router->temperature, $router->vegetation, $router->continents, $router->erosion, $router->depth, $router->ridges];
				foreach($values as $i => $f){
					self::assertSame((float) $row[3 + $i], $f->compute($x, $y, $z), "router value $i at $x $y $z (seed $seed)");
				}
				//final density: same corners, but vanilla interpolates y first (deepslate x first)
				self::assertEqualsWithDelta((float) $row[9], $router->finalDensity->compute($x, $y, $z), 1e-12);
			}
			$mismatches = 0;
			foreach($fixture["biomes"] as [$qx, $qy, $qz, $biome]){
				$x = $qx << 2;
				$y = $qy << 2;
				$z = $qz << 2;
				$q = fn(density\DensityFunction $f) : int => ClimateBiomeTree::quantize($f->compute($x, $y, $z));
				$found = $names[$tree->search($q($router->temperature), $q($router->vegetation), $q($router->continents), $q($router->erosion), $q($router->depth), $q($router->ridges))];
				if($found !== $biome){
					$mismatches++; //exact ties in vanilla's integer metric are resolved differently by deepslate (floats)
				}
			}
			self::assertLessThanOrEqual(1, $mismatches, "biome mismatches for seed $seed");
		}
	}

	public function testTerrainMatchesDeepslate() : void{
		$data = self::data();
		$tree = ClimateBiomeTree::fromData($data);
		CubicSpline::$vanillaFloats = false;
		foreach(Utils::stringifyKeys(FixtureLoader::worldgen()) as $seed => $fixture){
			$router = new NoiseRouter($data, (int) $seed);
			$fillers = [
				"interpreted" => new InterpretedTerrainFiller($router, $tree, false),
				"compiled" => new CompiledTerrainFiller($router, $tree, sys_get_temp_dir() . "/pmmp-vanilla-kernels", false)
			];
			foreach($fixture["chunksNoAquifer"] as $chunk){
				foreach($fillers as $name => $filler){
					$materials = $filler->fill($chunk["cx"], $chunk["cz"])->materials;
					$chars = [];
					foreach($materials as $m){
						$chars[] = self::CHARS[$m];
					}
					self::assertSame($chunk["md5"], md5(implode("", $chars)), "$name terrain of chunk {$chunk["cx"]},{$chunk["cz"]} (seed $seed)");
				}
			}
		}
	}

	public function testCompiledMatchesInterpretedWithAquifers() : void{
		$data = self::data();
		$tree = ClimateBiomeTree::fromData($data);
		$router = new NoiseRouter($data, 13579);
		$a = (new InterpretedTerrainFiller($router, $tree))->fill(-2, 15);
		$b = (new CompiledTerrainFiller($router, $tree, sys_get_temp_dir() . "/pmmp-vanilla-kernels"))->fill(-2, 15);
		self::assertSame($a->materials, $b->materials);
		self::assertSame($a->biomes, $b->biomes);
	}
}
