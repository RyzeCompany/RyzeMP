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

/**
 * Checks and benchmarks the native terrain core (native/vanillagen) against the PHP implementation of the vanilla
 * generator.
 *
 * compare: generates the same chunks with the PHP implementation and with vanillagen and requires identical blocks,
 *          biomes and carving masks (several seeds, a square around the origin plus scattered far chunks). --stage
 *          selects how far: 0 terrain (stone/water/lava/air), 1 material rules, 2 carvers (default). Chunks around
 *          structures with a terrain adaptation (villages, ancient cities, trial chambers, trail ruins, strongholds,
 *          outposts, Nether fossils; Java positions) are compared with their Beardifier too, unless --no-structures.
 * bench:   ms per chunk of the whole VanillaGenerator::generateChunk() (terrain + surface + chunk writing) with
 *          backend=compiled and with the native core.
 *
 * Usage:
 *   php tools/compare-vanilla-native.php --data=<worldgen data dir> --binary=<vanillagen program>
 *       [--mode=compare|bench|bench-native|bench-php] [--seeds=1,2,3] [--radius=4] [--far=16] [--chunks=100]
 *       [--php=interpreted|compiled] [--flags=0] [--stage=2] [--dimension=overworld|nether|end]
 */

namespace pocketmine\tools\compare_vanilla_native;

use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\CompiledTerrainFiller;
use pocketmine\world\generator\vanilla\InterpretedTerrainFiller;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\native\NativeTerrainFiller;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use pocketmine\world\generator\vanilla\TerrainFiller;
use pocketmine\world\generator\vanilla\VanillaEndGenerator;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\VanillaNetherGenerator;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function array_map;
use function count;
use function dirname;
use function explode;
use function fwrite;
use function getopt;
use function hrtime;
use function intdiv;
use function is_string;
use function ksort;
use function mt_rand;
use function mt_srand;
use function printf;
use function putenv;
use function sprintf;
use function sys_get_temp_dir;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @phpstan-var array<string, string> $opts */
$opts = getopt("", ["data:", "binary:", "mode:", "seeds:", "radius:", "far:", "chunks:", "php:", "flags:", "endpoint:", "stage:", "dimension:", "no-structures"]);
$data = $opts["data"] ?? null;
if(!is_string($data)){
	fwrite(STDERR, "Usage: php tools/compare-vanilla-native.php --data=<dir> --binary=<vanillagen> [--mode=compare|bench]" . PHP_EOL);
	exit(1);
}
$mode = $opts["mode"] ?? "compare";
$seeds = array_map(fn(string $s) => (int) $s, explode(",", $opts["seeds"] ?? "0,12345,-4172144997902289642", 64));
$flags = (int) ($opts["flags"] ?? 0);
$stage = (int) ($opts["stage"] ?? 2);
$dimension = $opts["dimension"] ?? "overworld";
if($stage >= 1){
	$flags |= NativeTerrainClient::FLAG_MATERIALS;
}
if($stage >= 2){
	$flags |= NativeTerrainClient::FLAG_CARVERS;
}

if(isset($opts["endpoint"])){
	//reuse a running helper (e.g. one started by another benchmark process): host:port:token
	[$host, $port, $token] = explode(":", $opts["endpoint"], 3);
	putenv(VanillaNativeBackend::ENV_ENDPOINT . "=$host:$port");
	putenv(VanillaNativeBackend::ENV_TOKEN . "=$token");
	$backend = null;
}else{
	$binary = $opts["binary"] ?? null;
	if(!is_string($binary)){
		fwrite(STDERR, "--binary or --endpoint is required" . PHP_EOL);
		exit(1);
	}
	$backend = VanillaNativeBackend::start($binary, sys_get_temp_dir() . "/vanillagen-compare.log");
	$backend->publish();
}

function ms(int $ns) : string{
	return sprintf("%.2f", $ns / 1e6);
}

if($mode === "compare"){
	$radius = (int) ($opts["radius"] ?? 4);
	$far = (int) ($opts["far"] ?? 16);
	$worldgen = new WorldgenData($data);
	$failed = false;
	foreach($seeds as $seed){
		$router = new NoiseRouter($worldgen, $seed, $dimension);
		$tree = $dimension === "end" ? ClimateBiomeTree::single("minecraft:the_end") : ClimateBiomeTree::fromData($worldgen, $dimension);
		$noAquifers = ($flags & NativeTerrainClient::FLAG_NO_AQUIFERS) !== 0;
		$php = ($opts["php"] ?? "compiled") === "interpreted" ?
			new InterpretedTerrainFiller($router, $tree, !$noAquifers) :
			new CompiledTerrainFiller($router, $tree, $data . "/compiled", !$noAquifers);
		$native = NativeTerrainFiller::connect($data, $seed, $tree->getBiomeNames(), $router->minY, $router->height,
			fn() : TerrainFiller => throw new \RuntimeException("native core failed"), $flags, $dimension);
		if($native === null){
			fwrite(STDERR, "cannot connect to vanillagen" . PHP_EOL);
			exit(1);
		}
		$kernel = $php instanceof CompiledTerrainFiller ? $php->getKernel() : null;
		$materials = $stage >= 1 ? new MaterialSystem($worldgen, $router, $tree, $seed, $kernel !== null ? $data . "/compiled" : null, $kernel, $dimension) : null;
		$carvers = $stage >= 2 && $materials !== null ? CarverSystem::create($worldgen, $router, $tree, $seed, $materials, $kernel) : null;
		$terrainNames = ["minecraft:air", $router->defaultBlock, "minecraft:water", "minecraft:lava"];
		$phpNames = $materials?->palette->getNames() ?? $terrainNames;
		$chunks = [];
		for($x = -$radius; $x < $radius; ++$x){
			for($z = -$radius; $z < $radius; ++$z){
				$chunks[] = [$x, $z];
			}
		}
		mt_srand($seed & 0x7fffffff);
		for($i = 0; $i < $far; ++$i){
			$chunks[] = [mt_rand(-100000, 100000), mt_rand(-100000, 100000)];
		}
		//chunks around structures with a terrain adaptation, filled with their Beardifier
		$structureManager = null;
		if(!isset($opts["no-structures"]) && $dimension !== "end"){
			$preset = "data=$data;backend=compiled;structure-positions=java";
			$structureManager = ($dimension === "nether" ? new VanillaNetherGenerator($seed, $preset) : new VanillaGenerator($seed, $preset))->getStructureManager();
			$wanted = $dimension === "nether" ? ["nether_fossil"] : ["village_*", "ancient_city", "trial_chambers", "trail_ruins", "stronghold", "pillager_outpost"];
			foreach($wanted as $structure){
				$found = $structureManager?->locate($structure, 0, 0, maxChunks: 150);
				if($found === null){
					continue;
				}
				[, $startX, $startZ] = $found;
				for($dx = -1; $dx <= 1; ++$dx){
					for($dz = -1; $dz <= 1; ++$dz){
						$chunks[] = [$startX + $dx, $startZ + $dz];
					}
				}
			}
		}
		$bearded = 0;
		$tPhp = 0;
		$tNative = 0;
		$badChunks = 0;
		$badBlocks = 0;
		$badBiomes = 0;
		foreach($chunks as [$cx, $cz]){
			$beardifier = $structureManager?->getBeardifier($cx, $cz);
			if($beardifier !== null){
				++$bearded;
			}
			$t = hrtime(true);
			$a = $php->fill($cx, $cz, $beardifier);
			$materials?->apply($a);
			$carvers?->apply($a);
			$tPhp += hrtime(true) - $t;
			$t = hrtime(true);
			$b = $native->fill($cx, $cz, $beardifier);
			$tNative += hrtime(true) - $t;
			$blocks = 0;
			$nativeNames = $b->palette ?? $terrainNames;
			foreach($a->materials as $i => $m){
				if(($nativeNames[$b->materials[$i] ?? -1] ?? null) !== $phpNames[$m]){
					$blocks++;
				}
			}
			$maskA = $a->carvingMask;
			$maskB = $b->carvingMask;
			ksort($maskA);
			ksort($maskB);
			if($maskA !== $maskB){
				$blocks++;
				printf("  seed %d chunk %d,%d: carving masks differ (%d / %d)" . PHP_EOL, $seed, $cx, $cz, count($a->carvingMask), count($b->carvingMask));
			}
			$biomes = 0;
			foreach($a->biomes as $i => $v){
				if($b->biomes[$i] !== $v){
					$biomes++;
				}
			}
			if($blocks > 0 || $biomes > 0 || count($a->materials) !== count($b->materials) || count($a->biomes) !== count($b->biomes)){
				$badChunks++;
				$badBlocks += $blocks;
				$badBiomes += $biomes;
				printf("  seed %d chunk %d,%d: %d blocks and %d biome cells differ" . PHP_EOL, $seed, $cx, $cz, $blocks, $biomes);
			}
		}
		$n = count($chunks);
		printf("seed %d: %d chunks (%d with terrain adaptation), %d differ (%d blocks, %d biome cells); fill ms/chunk: php %s, native %s" . PHP_EOL,
			$seed, $n, $bearded, $badChunks, $badBlocks, $badBiomes, ms(intdiv($tPhp, $n)), ms(intdiv($tNative, $n)));
		$failed = $failed || $badChunks > 0;
	}
	$backend?->stop();
	exit($failed ? 1 : 0);
}

//bench modes: the whole generateChunk() (terrain + surface + chunk writing) through VanillaGenerator
$count = (int) ($opts["chunks"] ?? 100);
$seed = $seeds[0];
$run = function(string $backendName) use ($data, $seed, $count, $dimension) : float{
	$preset = "data=$data;backend=$backendName";
	$generator = match($dimension){
		"nether" => new VanillaNetherGenerator($seed, $preset),
		"end" => new VanillaEndGenerator($seed, $preset),
		default => new VanillaGenerator($seed, $preset)
	};
	$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
	$side = 10;
	//warm-up (JIT, kernel load)
	for($i = 0; $i < 4; ++$i){
		$manager->setChunk(1000 + $i, 1000, new Chunk([], false));
		$generator->generateChunk($manager, 1000 + $i, 1000);
	}
	$t = hrtime(true);
	for($i = 0; $i < $count; ++$i){
		$x = $i % $side;
		$z = intdiv($i, $side);
		$manager->setChunk($x, $z, new Chunk([], false));
		$generator->generateChunk($manager, $x, $z);
	}
	return (hrtime(true) - $t) / 1e6 / $count;
};
if($mode === "bench" || $mode === "bench-php"){
	printf("generateChunk with backend=compiled: %.2f ms/chunk" . PHP_EOL, $run("compiled"));
}
if($mode === "bench" || $mode === "bench-native"){
	printf("generateChunk with the native core: %.2f ms/chunk" . PHP_EOL, $run("auto"));
}
$backend?->stop();
