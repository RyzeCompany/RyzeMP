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
use pocketmine\world\generator\vanilla\carver\CarverMath;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\carver\ValueProvider;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use function array_keys;
use function array_map;
use function getenv;
use function implode;
use function is_dir;
use function json_decode;
use function md5;
use function pack;
use function sort;
use const JSON_THROW_ON_ERROR;
use const M_PI;

/**
 * Carvers (caves and canyons). The carving masks are compared with an independent implementation of vanilla's carver
 * geometry (see carver-fixtures.json); the whole chunk after the carvers is a regression check.
 */
class CarverTest extends TestCase{

	public function testFloatRounding() : void{
		//Math.fround() / Java (float) values
		self::assertSame(0.699999988079071, CarverMath::f(0.7));
		self::assertSame(3.1415927410125732, CarverMath::f(M_PI));
		self::assertSame(CarverMath::TWO_PI, CarverMath::f(M_PI * 2));
		self::assertSame(CarverMath::HALF_PI, CarverMath::f(M_PI / 2));
		//ties to even
		self::assertSame(1.0, CarverMath::f(1.0 + 2 ** -24));
		self::assertSame(1.0 + 2 ** -22, CarverMath::f(1.0 + 3 * 2 ** -24));
	}

	public function testSinTable() : void{
		//same table as the native core (native/vanillagen/worldgen/carver_test.go)
		self::assertSame("2dd3e1880ba74b5308ca5e78179b3852", md5(pack("e*", ...CarverMath::sinTable())));
		self::assertSame(1.0, CarverMath::sin(CarverMath::HALF_PI));
		self::assertSame(CarverMath::sinTable()[65535 & (int) (-1.0 * 10430.378350470453)], CarverMath::sin(-1.0));
	}

	public function testProviders() : void{
		$random = new LegacyRandom(42);
		$check = new LegacyRandom(42);
		$uniform = ValueProvider::float(["type" => "minecraft:uniform", "min_inclusive" => 0.7, "max_exclusive" => 1.4]);
		$f = CarverMath::f(...);
		self::assertSame($f($f($check->nextFloat() * $f($f(1.4) - $f(0.7))) + $f(0.7)), $uniform->sampleFloat($random));

		$count = ValueProvider::int(["type" => "minecraft:very_biased_to_bottom", "min_inclusive" => 0, "max_inclusive" => 14]);
		$random = new LegacyRandom(7);
		$check = new LegacyRandom(7);
		self::assertSame($check->nextBoundedInt($check->nextBoundedInt($check->nextBoundedInt(15) + 1) + 1), $count->sampleInt($random));

		self::assertSame(-56, (int) ValueProvider::height(["type" => "minecraft:uniform", "min_inclusive" => ["above_bottom" => 8], "max_inclusive" => ["absolute" => 180]], -64, 384)->params[0]);
		self::assertSame(318, ValueProvider::anchor(["below_top" => 1], -64, 384));
	}

	public function testMatchesReference() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		$data = new WorldgenData($dir);
		if(!$data->hasCarvers()){
			self::markTestSkipped("The worldgen data has no carver definitions");
		}
		/** @phpstan-var array{seeds: array<string, list<array{cx: int, cz: int, maskSize: int, mask: string, blocks: string}>>} $json */
		$json = json_decode(Filesystem::fileGetContents(__DIR__ . "/carver-fixtures.json"), true, 512, JSON_THROW_ON_ERROR);
		foreach(Utils::stringifyKeys($json["seeds"]) as $seed => $chunks){
			$seed = (int) $seed;
			$router = new NoiseRouter($data, $seed);
			$tree = ClimateBiomeTree::fromData($data);
			$filler = new InterpretedTerrainFiller($router, $tree);
			$materials = new MaterialSystem($data, $router, $tree, $seed);
			$carvers = CarverSystem::create($data, $router, $tree, $seed, $materials, null);
			self::assertNotNull($carvers);
			$names = $materials->palette->getNames();
			foreach($chunks as $chunk){
				$terrain = $filler->fill($chunk["cx"], $chunk["cz"]);
				$materials->apply($terrain);
				$carvers->apply($terrain);
				$mask = array_keys($terrain->carvingMask);
				sort($mask);
				$where = "chunk {$chunk["cx"]},{$chunk["cz"]} (seed $seed)";
				self::assertCount($chunk["maskSize"], $mask, "carving mask size of $where");
				self::assertSame($chunk["mask"], md5(implode(",", $mask)), "carving mask of $where");
				self::assertSame($chunk["blocks"], md5(implode(",", array_map(fn(int $m) : string => $names[$m], $terrain->materials))), "blocks of $where");
			}
		}
	}
}
