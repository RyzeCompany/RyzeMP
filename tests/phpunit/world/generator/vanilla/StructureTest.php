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
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\structure\Beardifier;
use pocketmine\world\generator\vanilla\structure\piece\PoolElementPiece;
use pocketmine\world\generator\vanilla\structure\placement\BedrockSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\placement\MersenneTwister;
use pocketmine\world\generator\vanilla\structure\placement\RandomSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\StructurePlacement;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\SimpleChunkManager;
use function array_reverse;
use function count;
use function get_class;
use function getenv;
use function is_dir;
use function pack;
use function sha1;
use function sort;
use function str_contains;

class StructureTest extends TestCase{
	private const SEED = 12345;

	/**
	 * Structure positions reported by a Java Edition 26.3 server (/execute positioned 0 0 0 run locate structure ...)
	 * for seed 12345: structure => [locate x, locate z].
	 */
	private const JAVA_LOCATE = [
		"minecraft:village_plains" => [160, 880],
		"minecraft:pillager_outpost" => [272, -960],
		"minecraft:igloo" => [576, 656],
		"minecraft:shipwreck" => [176, 160],
		"minecraft:ocean_ruin_cold" => [112, 80],
		"minecraft:ancient_city" => [-288, -288],
		"minecraft:trial_chambers" => [0, 224],
		"minecraft:jungle_pyramid" => [-1520, 32],
		"minecraft:ruined_portal" => [16, 0],
	];

	/**
	 * Starts of the code-built structures saved by the Java Edition 26.3 server (seed 12345): set => [dimension, start
	 * chunk x, z, structure, piece count, first piece box].
	 */
	private const JAVA_STARTS = [
		"minecraft:mineshafts" => ["overworld", -4, 1, "minecraft:mineshaft", 142, [-62, 40, 18, -52, 48, 30]],
		"minecraft:strongholds" => ["overworld", -35, -103, "minecraft:stronghold", 225, [-558, 12, -1646, -554, 22, -1642]],
		"minecraft:ocean_monuments" => ["overworld", 110, -85, "minecraft:monument", 1, [1731, 39, -1389, 1788, 61, -1332]],
		"minecraft:woodland_mansions" => ["overworld", 291, 441, "minecraft:mansion", 487, [4652, 70, 7048, 4672, 88, 7063]],
		"minecraft:nether_complexes" => ["nether", 7, 42, "minecraft:fortress", 142, [114, 55, 674, 132, 64, 692]],
		"minecraft:nether_fossils" => ["nether", -22, 6, "minecraft:nether_fossil", 1, [-352, 33, 100, -352, 36, 102]],
		//phase 3: an End city with a ship (all 129 piece boxes match the saved start)
		"minecraft:end_cities" => ["end", -16, 83, "minecraft:end_city", 129, [-249, 62, 1335, -240, 65, 1344]],
	];

	public function testMersenneTwister() : void{
		$mt = new MersenneTwister(5489);
		self::assertSame(3499211612, $mt->nextUnsignedInt());
		self::assertSame(581869302, $mt->nextUnsignedInt());
		self::assertSame(1791095845, (new MersenneTwister(1))->nextUnsignedInt());
	}

	/**
	 * Placement chunks of Java Edition structures for seed 12345 (start chunks of the structures the Java server
	 * located): the random spread placement must find them.
	 */
	public function testJavaRandomSpreadPlacement() : void{
		$villages = new RandomSpreadPlacement(10387312, 34, 8, false);
		self::assertTrue($villages->isPlacementChunk(self::SEED, 10, 55));
		self::assertTrue($villages->isPlacementChunk(self::SEED, -146, 37)); //village_desert at -2336, 592
		self::assertFalse($villages->isPlacementChunk(self::SEED, 10, 56));
		$outposts = new RandomSpreadPlacement(165745296, 32, 8, false, 0.2, StructurePlacement::REDUCTION_LEGACY_1, "minecraft:villages", 10);
		self::assertTrue($outposts->isPlacementChunk(self::SEED, 17, -60));
		self::assertTrue($outposts->passesFrequency(self::SEED, 17, -60));
		$igloos = new RandomSpreadPlacement(14357618, 32, 8, false);
		self::assertTrue($igloos->isPlacementChunk(self::SEED, 36, 41));
		$treasure = new RandomSpreadPlacement(0, 1, 0, false, 0.01, StructurePlacement::REDUCTION_LEGACY_2);
		self::assertTrue($treasure->passesFrequency(self::SEED, 47, 25)); //buried_treasure at 761, 409

		//placementChunksIn() agrees with isPlacementChunk()
		$found = $villages->placementChunksIn(self::SEED, -100, -100, 100, 100);
		self::assertGreaterThan(20, count($found));
		foreach($found as [$x, $z]){
			self::assertTrue($villages->isPlacementChunk(self::SEED, $x, $z));
		}
	}

	public function testBedrockPlacementTable() : void{
		$java = new RandomSpreadPlacement(10387312, 34, 8, false);
		$bedrock = BedrockStructurePlacements::select("minecraft:villages", $java, BedrockStructurePlacements::MODE_BEDROCK);
		self::assertInstanceOf(BedrockSpreadPlacement::class, $bedrock);
		self::assertSame(10387312, $bedrock->salt);
		self::assertSame(34, $bedrock->spacing);
		self::assertSame(26, $bedrock->range);
		self::assertSame($java, BedrockStructurePlacements::select("minecraft:villages", $java, BedrockStructurePlacements::MODE_JAVA));
		self::assertSame($java, BedrockStructurePlacements::select("minecraft:shipwrecks", $java, BedrockStructurePlacements::MODE_BEDROCK));
		//one placement chunk per region, inside the region
		foreach([[0, 0], [-1, 3], [7, -12]] as [$rx, $rz]){
			$chunks = $bedrock->placementChunksIn(self::SEED, $rx * 34, $rz * 34, $rx * 34 + 33, $rz * 34 + 33);
			self::assertCount(1, $chunks);
			[$x, $z] = $chunks[0];
			self::assertTrue($bedrock->isPlacementChunk(self::SEED, $x, $z));
			self::assertLessThan(26, $x - $rx * 34);
			self::assertLessThan(26, $z - $rz * 34);
		}
	}

	public function testRotations() : void{
		//StructureTemplate.transform() and getBoundingBox() with a pivot
		self::assertSame([-3, 2], Rotations::transform(2, 3, Rotations::MIRROR_NONE, Rotations::CLOCKWISE_90, 0, 0));
		self::assertSame([3, -2], Rotations::transform(2, 3, Rotations::MIRROR_NONE, Rotations::COUNTERCLOCKWISE_90, 0, 0));
		self::assertSame([-2, -3], Rotations::transform(2, 3, Rotations::MIRROR_NONE, Rotations::CLOCKWISE_180, 0, 0));
		self::assertSame([2, -3], Rotations::transform(2, 3, Rotations::MIRROR_LEFT_RIGHT, Rotations::NONE, 0, 0));
		//the pivot does not move
		self::assertSame([3, 5], Rotations::transform(3, 5, Rotations::MIRROR_NONE, Rotations::CLOCKWISE_90, 3, 5));
		self::assertSame([8, 2], Rotations::transform(0, 0, Rotations::MIRROR_NONE, Rotations::CLOCKWISE_90, 3, 5));
		self::assertSame([-6, 0, 0, 0, 4, 9], Rotations::boundingBox(0, 0, 0, 10, 5, 7, Rotations::CLOCKWISE_90));
	}

	private static function dataDir() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir . "/data/minecraft/worldgen/template_pool")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no structure data (data format 5+)");
		}
		return $dir;
	}

	private static function preset() : string{
		$directory = self::dataDir();
		return "core-v5;data=$directory;sha256=" . VanillaDataIdentity::fingerprint($directory);
	}

	private static function manager() : StructureManager{
		$manager = (new RyzeOverworldGenerator(self::SEED, self::preset()))->getStructureManager();
		self::assertNotNull($manager);
		return $manager;
	}

	/**
	 * In Java mode, structures are where Java Edition's /locate finds them (placement, biome checks and start
	 * generation all have to agree).
	 */
	public function testLocateMatchesJava() : void{
		$manager = self::manager();
		foreach(self::JAVA_LOCATE as $structure => [$x, $z]){
			$found = $manager->locate($structure, 0, 0);
			self::assertNotNull($found, $structure);
			self::assertSame($structure, $found[0]);
			self::assertSame([$x, $z], [$found[3], $found[5]], $structure);
		}
	}

	/**
	 * @phpstan-return list<string>
	 */
	private static function describe(StructureStart $start) : array{
		$out = [];
		foreach($start->pieces as $piece){
			$out[] = ($piece instanceof PoolElementPiece ? $piece->element->describe() . "@" . $piece->rotation : get_class($piece)) . " " . $piece->box;
		}
		return $out;
	}

	/**
	 * Starts are pure functions of the seed and the chunk: independent generators (other worker threads) build the
	 * same pieces.
	 */
	public function testStartsAreDeterministic() : void{
		$a = self::manager()->getStart("minecraft:villages", 10, 55);
		$b = self::manager()->getStart("minecraft:villages", 10, 55);
		self::assertNotNull($a);
		self::assertNotNull($b);
		self::assertSame("minecraft:village_plains", $a->structure->id);
		self::assertGreaterThan(20, count($a->pieces));
		self::assertSame(self::describe($a), self::describe($b));
	}

	/**
	 * Populates the chunks around a center chunk in both orders and checks that blocks and tiles are the
	 * same; returns the tiles of the first run.
	 *
	 * @phpstan-return list<string>
	 */
	private static function assertSeamless(string $dimension, int $minY, int $maxY, int $centerX, int $centerZ, int $radius, bool $decorate = false) : array{
		$results = [];
		$directory = self::dataDir();
		$hash = VanillaDataIdentity::fingerprint($directory);
		foreach([false, true] as $reverse){
			$writer = new VanillaDensityChunkWriter(self::SEED, $directory, $dimension, $hash, $decorate, useCompiledTerrain: true, enableStructures: true);
			$world = new SimpleChunkManager($minY, $maxY);
			for($x = $centerX - $radius - 1; $x <= $centerX + $radius + 1; ++$x){
				for($z = $centerZ - $radius - 1; $z <= $centerZ + $radius + 1; ++$z){
					$chunk = new Chunk([], false);
					$world->setChunk($x, $z, $chunk);
					$writer->fill($chunk, $x, $z);
				}
			}
			$order = [];
			for($x = $centerX - $radius; $x <= $centerX + $radius; ++$x){
				for($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z){
					$order[] = [$x, $z];
				}
			}
			if($reverse){
				$order = array_reverse($order);
			}
			foreach($order as [$x, $z]){
				$writer->populate($world, $x, $z);
			}
			$blocks = [];
			$tiles = [];
			for($x = $centerX - $radius; $x <= $centerX + $radius; ++$x){
				for($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z){
					$chunk = $world->getChunk($x, $z);
					self::assertNotNull($chunk);
					foreach($chunk->getSubChunks() as $y => $subChunk){
						$blocks["$x:$z:$y"] = [$subChunk->getBlockLayers(), $subChunk->getBiomeArray(), $subChunk->getEmptyBlockId()];
					}
					foreach($chunk->getPendingTileNBT() as $nbt){
						$tiles[] = $nbt->getString("id") . ":" . $nbt->getString("LootTable", $nbt->getString("EntityIdentifier", "")) . ":" . $nbt->getInt("x") . "," . $nbt->getInt("y") . "," . $nbt->getInt("z");
					}
				}
			}
			sort($tiles);
			$results[] = [$blocks, $tiles];
		}
		self::assertSame($results[0][1], $results[1][1]);
		foreach(Utils::stringifyKeys($results[0][0]) as $key => [$layers, $biomes, $air]){
			[$otherLayers, $otherBiomes] = $results[1][0][$key];
			for($bx = 0; $bx < 16; ++$bx){
				for($bz = 0; $bz < 16; ++$bz){
					for($by = 0; $by < 16; ++$by){
						for($i = 0, $count = max(count($layers), count($otherLayers)); $i < $count; ++$i){
							if((($layers[$i] ?? null)?->get($bx, $by, $bz) ?? $air) !== (($otherLayers[$i] ?? null)?->get($bx, $by, $bz) ?? $air)){
								self::fail("Block $bx $by $bz of $key layer $i differs between population orders");
							}
						}
						if($biomes->get($bx, $by, $bz) !== $otherBiomes->get($bx, $by, $bz)){
							self::fail("Biome $bx $by $bz of $key differs between population orders");
						}
					}
				}
			}
		}
		return $results[0][1];
	}

	/**
	 * A structure spanning several chunks gives the same blocks whatever the order its chunks are populated in (each
	 * chunk only places the part of the pieces inside it, with position-based randoms).
	 */
	public function testChunkBordersAreSeamless() : void{
		// Isolate structure writes at the outpost at chunk 17, -60.
		$tiles = self::assertSeamless("overworld", -64, 320, 17, -60, 1);
		//the watchtower's chest rolls the outpost loot table when opened
		$lootChests = 0;
		foreach($tiles as $tile){
			if(str_contains($tile, "Chest:loot_tables/chests/pillager_outpost.json")){
				++$lootChests;
			}
		}
		self::assertGreaterThan(0, $lootChests, "outpost loot chest");
	}

	/**
	 * Code-built structures (phase 2) are seamless too: the Nether fortress around its start (seed 12345, chunk 7, 42:
	 * blaze spawner, nether_bridge chests).
	 */
	public function testFortressIsSeamless() : void{
		$tiles = self::assertSeamless("nether", 0, 128, 8, 43, 1);
		self::assertNotSame([], $tiles);
	}

	public function testFullNetherDecorationIsSeamless() : void{
		$tiles = self::assertSeamless("nether", 0, 128, 8, 43, 1, true);
		self::assertNotSame([], $tiles);
	}

	public function testFullOverworldDecorationIsSeamless() : void{
		$tiles = self::assertSeamless("overworld", -64, 320, 17, -60, 1, true);
		self::assertNotSame([], $tiles);
	}

	/**
	 * Pieces of the code-built structures are the ones a Java Edition 26.3 server saved for seed 12345 (piece count and
	 * box of the first piece, from the "structures" data of the start chunk), positions included.
	 */
	public function testCodeStructuresMatchJava() : void{
		$overworld = self::manager();
		$nether = (new RyzeVanillaNetherCoreGenerator(self::SEED, self::preset()))->getStructureManager();
		self::assertNotNull($nether);
		$end = (new RyzeVanillaEndCoreGenerator(self::SEED, self::preset()))->getStructureManager();
		self::assertNotNull($end);
		foreach(self::JAVA_STARTS as $setId => [$dimension, $chunkX, $chunkZ, $structure, $count, $firstBox]){
			$manager = match($dimension){
				"nether" => $nether,
				"end" => $end,
				default => $overworld
			};
			$start = $manager->getStart($setId, $chunkX, $chunkZ);
			self::assertNotNull($start, $setId);
			self::assertSame($structure, $start->structure->id, $setId);
			self::assertCount($count, $start->pieces, $setId);
			self::assertSame($firstBox, $start->pieces[0]->box->toArray(), $setId);
		}
	}

	/**
	 * Stronghold ring positions (concentric rings placement + biome search) are where Java's /locate finds them.
	 */
	public function testStrongholdRingPosition() : void{
		$manager = self::manager();
		$placement = $manager->getPlacement("minecraft:strongholds");
		self::assertNotNull($placement);
		//only the positions near this area are resolved (one biome search)
		self::assertSame([[-35, -103]], $placement->placementChunksIn(self::SEED, -40, -110, -30, -95));
		self::assertSame([-560, 0, -1648], $placement->getLocatePos(-35, -103));
		//locate visits ring positions nearest first: only a few biome searches
		$found = $manager->locate("stronghold", 0, 0);
		self::assertNotNull($found);
		self::assertSame([-35, -103], [$found[1], $found[2]]);
	}

	/**
	 * End cities are where Java's /locate finds them (execute in the_end positioned 0 0 0: 992, 384).
	 */
	public function testEndCityLocate() : void{
		$end = (new RyzeVanillaEndCoreGenerator(self::SEED, self::preset()))->getStructureManager();
		self::assertNotNull($end);
		$found = $end->locate("end_city", 0, 0);
		self::assertNotNull($found);
		self::assertSame(["minecraft:end_city", 62, 24, 992, 0, 384], $found);
		//a radius in chunks instead of rings: 60 chunks is not enough for this one (1063 blocks away)
		self::assertNull($end->locate("end_city", 0, 0, maxChunks: 30));
	}

	/**
	 * The Beardifier's float32 values are the ones the native core computes (same sha1 as its Go test,
	 * native/vanillagen/worldgen/beard_test.go), and a village chunk gets one.
	 */
	public function testBeardifier() : void{
		self::assertSame("585c2c9c953e2bcab8ccaf2f7641283f413d8b8e", sha1(pack("e*", ...Beardifier::kernel())));
		$b = new Beardifier([0, 64, 0, 10, 70, 10, 2, 1, 5, 60, 5, 20, 75, 20, 3, 2, -8, 40, -8, 4, 50, 4, 1, 0, 2, 30, 2, 9, 45, 9, 4, 0], [3, 66, -4], [-40, 0, -40, 50, 120, 50]);
		$out = [];
		foreach([[-3, -2], [0, 0], [5, 5], [11, 3], [-6, 8], [14, 14]] as [$x, $z]){
			$column = $b->column($x, $z, -64, 319);
			if(count($column) > 0){
				$out[] = $column[0];
				foreach($column[1] as $value){
					$out[] = $value;
				}
			}
		}
		self::assertCount(732, $out);
		self::assertSame("52e52d79c7bbcd3f8b56caf6118ad03fee12873e", sha1(pack("e*", ...$out)));

		$manager = self::manager();
		//the plains village of seed 12345 starts in chunk 10, 55
		$beardifier = $manager->getBeardifier(10, 55);
		self::assertNotNull($beardifier);
		self::assertNotSame([], $beardifier->rigids);
		self::assertNull($manager->getBeardifier(-3000, -3000));
	}
}
