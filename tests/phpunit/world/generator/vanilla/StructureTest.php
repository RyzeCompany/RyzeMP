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
use pocketmine\world\generator\vanilla\structure\LocateVanillaStructureTask;
use pocketmine\world\generator\vanilla\structure\piece\PoolElementPiece;
use pocketmine\world\generator\vanilla\structure\placement\BedrockSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\placement\MersenneTwister;
use pocketmine\world\generator\vanilla\structure\placement\RandomSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\StructurePlacement;
use pocketmine\world\generator\vanilla\structure\StructureManager;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\VanillaStructureFinder;
use pocketmine\world\locate\LocateTimeoutException;
use pocketmine\world\SimpleChunkManager;
use function array_reverse;
use function count;
use function get_class;
use function getenv;
use function hrtime;
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

	private static function manager(string $options = "") : StructureManager{
		$generator = new VanillaGenerator(self::SEED, "data=" . self::dataDir() . ";backend=compiled;structure-positions=java" . $options);
		$manager = $generator->getStructureManager();
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
	 * Populates the chunks around a center chunk in both orders and checks that blocks, tiles and entities are the
	 * same; returns the tiles and entities of the first run.
	 *
	 * @phpstan-param \Closure() : VanillaGenerator $newGenerator
	 * @phpstan-return array{list<string>, list<string>}
	 */
	private static function assertSeamless(\Closure $newGenerator, int $minY, int $maxY, int $centerX, int $centerZ, int $radius) : array{
		$results = [];
		foreach([false, true] as $reverse){
			$generator = $newGenerator();
			$world = new SimpleChunkManager($minY, $maxY);
			for($x = $centerX - $radius - 1; $x <= $centerX + $radius + 1; ++$x){
				for($z = $centerZ - $radius - 1; $z <= $centerZ + $radius + 1; ++$z){
					$world->setChunk($x, $z, new Chunk([], false));
					$generator->generateChunk($world, $x, $z);
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
				$generator->populateChunk($world, $x, $z);
			}
			$blocks = [];
			$tiles = [];
			$entities = [];
			for($x = $centerX - $radius; $x <= $centerX + $radius; ++$x){
				for($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z){
					$chunk = $world->getChunk($x, $z);
					self::assertNotNull($chunk);
					foreach($chunk->getSubChunks() as $y => $subChunk){
						$blocks["$x:$z:$y"] = $subChunk->getBlockLayers()[0] ?? null;
					}
					foreach($chunk->getPendingTileNBT() as $nbt){
						$tiles[] = $nbt->getString("id") . ":" . $nbt->getString("LootTable", $nbt->getString("EntityIdentifier", "")) . ":" . $nbt->getInt("x") . "," . $nbt->getInt("y") . "," . $nbt->getInt("z");
					}
					foreach($chunk->getPendingEntityNBT() as $nbt){
						$entities[] = $nbt->getString(Chunk::PENDING_ENTITY_IDENTIFIER);
					}
				}
			}
			sort($tiles);
			sort($entities);
			$results[] = [$blocks, $tiles, $entities];
		}
		self::assertSame($results[0][1], $results[1][1]);
		self::assertSame($results[0][2], $results[1][2]);
		foreach(Utils::stringifyKeys($results[0][0]) as $key => $layer){
			$other = $results[1][0][$key];
			self::assertSame($layer === null, $other === null, $key);
			if($layer !== null && $other !== null){
				for($bx = 0; $bx < 16; ++$bx){
					for($bz = 0; $bz < 16; ++$bz){
						for($by = 0; $by < 16; ++$by){
							if($layer->get($bx, $by, $bz) !== $other->get($bx, $by, $bz)){
								self::fail("Block $bx $by $bz of $key differs between population orders");
							}
						}
					}
				}
			}
		}
		return [$results[0][1], $results[0][2]];
	}

	/**
	 * A structure spanning several chunks gives the same blocks whatever the order its chunks are populated in (each
	 * chunk only places the part of the pieces inside it, with position-based randoms).
	 */
	public function testChunkBordersAreSeamless() : void{
		$dir = self::dataDir();
		//pillager outpost at chunk 17, -60 (seed 12345); features off, so only structures change the blocks
		[$tiles] = self::assertSeamless(
			fn() => new VanillaGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java;features=false"),
			-64, 320, 17, -60, 1
		);
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
		$dir = self::dataDir();
		[$tiles] = self::assertSeamless(
			fn() => new VanillaNetherGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java;features=false"),
			0, 128, 8, 43, 1
		);
		self::assertNotSame([], $tiles);
	}

	/**
	 * Pieces of the code-built structures are the ones a Java Edition 26.3 server saved for seed 12345 (piece count and
	 * box of the first piece, from the "structures" data of the start chunk), positions included.
	 */
	public function testCodeStructuresMatchJava() : void{
		$dir = self::dataDir();
		$overworld = self::manager();
		$nether = (new VanillaNetherGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java"))->getStructureManager();
		self::assertNotNull($nether);
		$end = (new VanillaEndGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java"))->getStructureManager();
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
		$dir = self::dataDir();
		$end = (new VanillaEndGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java"))->getStructureManager();
		self::assertNotNull($end);
		$found = $end->locate("end_city", 0, 0);
		self::assertNotNull($found);
		self::assertSame(["minecraft:end_city", 62, 24, 992, 0, 384], $found);
		//a radius in chunks instead of rings: 60 chunks is not enough for this one (1063 blocks away)
		self::assertNull($end->locate("end_city", 0, 0, maxChunks: 30));
	}

	/**
	 * /locate structure names: Bedrock names, old spellings and Java ids map to the structures searched.
	 */
	public function testStructureFinderNames() : void{
		$overworld = new VanillaStructureFinder(VanillaStructureFinder::DIMENSION_OVERWORLD);
		$ids = $overworld->getStructureIds();
		foreach(["village", "temple", "ruins", "monument", "mansion", "stronghold", "mineshaft", "pillager_outpost", "shipwreck", "buried_treasure", "ruined_portal", "ancient_city", "trail_ruins", "trial_chambers", "village_plains", "jungle_pyramid", "swamp_hut", "igloo"] as $id){
			self::assertContains($id, $ids);
		}
		self::assertNotContains("fortress", $ids);
		self::assertSame(["desert_pyramid", "jungle_pyramid", "swamp_hut", "igloo"], $overworld->getPatterns("temple"));
		self::assertSame(["village_*"], $overworld->getPatterns("village"));
		self::assertSame(["village_plains"], $overworld->getPatterns("village_plains"));
		$nether = new VanillaStructureFinder(VanillaStructureFinder::DIMENSION_NETHER);
		self::assertContains("fortress", $nether->getStructureIds());
		self::assertContains("bastion_remnant", $nether->getStructureIds());
		self::assertSame(["ruined_portal_nether"], $nether->getPatterns("ruined_portal"));
		$end = new VanillaStructureFinder(VanillaStructureFinder::DIMENSION_END);
		self::assertSame(["end_city"], $end->getPatterns("endcity"));
		self::assertContains("end_city", $end->getStructureIds());
		self::assertSame(["mansion"], $overworld->getPatterns("woodland_mansion"));
		self::assertSame(["monument"], $overworld->getPatterns("ocean_monument"));
		self::assertSame(["fortress"], $nether->getPatterns("nether_fortress"));
		self::assertSame(["bastion_remnant"], $nether->getPatterns("bastion"));
	}

	/**
	 * Runs an end city search task on this thread and returns what it passes to its callback.
	 *
	 * @param string[] $excluded
	 * @phpstan-param list<string> $excluded
	 */
	private static function runEndCityTask(string $options, array $excluded) : mixed{
		$result = "not called";
		$task = new LocateVanillaStructureTask(VanillaEndGenerator::class, self::SEED, $options, ["end_city"], 0, 0, 400, $excluded, 60, function(array|string|null $found, bool $failed) use (&$result) : void{
			$result = $failed ? "failed" : $found;
		});
		$task->onRun();
		$task->onCompletion();
		return $result;
	}

	/**
	 * The /locate worker task builds its own structure manager and finds the same end city; useNewChunksOnly skips
	 * starts the main thread reports as generated; a search past its deadline gives up.
	 */
	public function testLocateStructureTask() : void{
		$dir = self::dataDir();
		$options = "data=$dir;backend=compiled;structure-positions=java";
		self::assertSame([992, 0, 384, 62, 24], self::runEndCityTask($options, []));
		$next = self::runEndCityTask($options, ["62:24"]);
		self::assertIsArray($next);
		self::assertNotSame([62, 24], [$next[3], $next[4]], "the excluded start is skipped");

		$end = (new VanillaEndGenerator(self::SEED, $options))->getStructureManager();
		self::assertNotNull($end);
		$this->expectException(LocateTimeoutException::class);
		VanillaStructureFinder::searchPatterns($end, ["end_city"], 5000, 5000, 400, null, hrtime(true) - 1);
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
