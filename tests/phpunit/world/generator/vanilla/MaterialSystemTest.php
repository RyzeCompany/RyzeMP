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
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use function array_map;
use function getenv;
use function implode;
use function is_dir;
use function json_decode;
use function md5;
use function sys_get_temp_dir;
use const JSON_THROW_ON_ERROR;

/**
 * Material rules (surface, bedrock, deepslate, ore veins, badlands bands, icebergs) on the real worldgen data,
 * compared with an independent implementation built on deepslate primitives (see material-fixtures.json).
 * Needs POCKETMINE_VANILLA_WORLDGEN_DATA, like VanillaWorldgenTest.
 */
class MaterialSystemTest extends TestCase{

	private static function data() : WorldgenData{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		return new WorldgenData($dir);
	}

	/**
	 * @phpstan-return array<string, list<array{cx: int, cz: int, md5: string}>>
	 */
	private static function fixtures() : array{
		/** @phpstan-var array{seeds: array<string, list<array{cx: int, cz: int, md5: string}>>} $json */
		$json = json_decode(Filesystem::fileGetContents(__DIR__ . "/material-fixtures.json"), true, 512, JSON_THROW_ON_ERROR);
		return $json["seeds"];
	}

	private static function hash(TerrainChunk $terrain, MaterialSystem $materials) : string{
		$names = $materials->palette->getNames();
		return md5(implode(",", array_map(fn(int $m) : string => $names[$m], $terrain->materials)));
	}

	public function testMatchesReference() : void{
		$data = self::data();
		$kernels = sys_get_temp_dir() . "/pmmp-vanilla-kernels";
		foreach(Utils::stringifyKeys(self::fixtures()) as $seed => $chunks){
			$seed = (int) $seed;
			$router = new NoiseRouter($data, $seed);
			$tree = ClimateBiomeTree::fromData($data);
			$filler = new CompiledTerrainFiller($router, $tree, $kernels);
			$compiled = new MaterialSystem($data, $router, $tree, $seed, $kernels);
			foreach($chunks as $chunk){
				$terrain = $filler->fill($chunk["cx"], $chunk["cz"]);
				$compiled->apply($terrain);
				self::assertSame($chunk["md5"], self::hash($terrain, $compiled), "chunk {$chunk["cx"]},{$chunk["cz"]} (seed $seed)");
			}
		}
	}

	public function testCompiledMatchesInterpreted() : void{
		$data = self::data();
		$kernels = sys_get_temp_dir() . "/pmmp-vanilla-kernels";
		$seed = 13579;
		$router = new NoiseRouter($data, $seed);
		$tree = ClimateBiomeTree::fromData($data);
		$filler = new CompiledTerrainFiller($router, $tree, $kernels);
		$interpreted = new MaterialSystem($data, $router, $tree, $seed);
		$compiled = new MaterialSystem($data, $router, $tree, $seed, $kernels);
		foreach([[0, 0], [-234, 135]] as [$cx, $cz]){
			$a = $filler->fill($cx, $cz);
			$b = clone $a;
			$interpreted->apply($a);
			$compiled->apply($b);
			self::assertSame(self::hash($a, $interpreted), self::hash($b, $compiled), "chunk $cx,$cz");
		}
	}
}
