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

namespace pocketmine\world\mobs;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\mobs\spawn\BiomeFilter;
use pocketmine\world\mobs\spawn\PopulationCaps;
use pocketmine\world\mobs\spawn\SlimeChunk;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\SpawnContext;
use pocketmine\world\mobs\spawn\SpawnHerd;
use pocketmine\world\mobs\spawn\SpawnRuleParser;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\mobs\spawn\SpawnSpot;
use pocketmine\world\mobs\spawn\VanillaSpawnRules;
use pocketmine\world\World;
use function count;
use function file_put_contents;
use function mkdir;
use function mt_srand;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Spawn rule parsing and evaluation. The rules here are written for the tests in the format of Mojang's spawn rule
 * files (Mojang's own files are not part of PocketMine-MP).
 */
final class SpawnRulesTest extends TestCase{

	private const MONSTER_RULE = <<<'JSON'
{
  "format_version": "1.8.0",
  // a comment, like in Mojang's files
  "minecraft:spawn_rules": {
    "description": { "identifier": "minecraft:zombie", "population_control": "monster" },
    "conditions": [
      {
        "minecraft:spawns_on_surface": {},
        "minecraft:spawns_underground": {},
        "minecraft:brightness_filter": { "min": 0, "max": 7, "adjust_for_weather": true },
        "minecraft:difficulty_filter": { "min": "easy", "max": "hard" },
        "minecraft:weight": { "default": 100 },
        "minecraft:herd": { "min_size": 2, "max_size": 4 },
        "minecraft:density_limit": { "surface": 5 },
        "minecraft:spawns_on_block_prevented_filter": [ "minecraft:nether_wart_block", { "name": "minecraft:shroomlight" } ],
        "minecraft:permute_type": [ { "weight": 95 }, { "weight": 5, "entity_type": "minecraft:zombie_villager_v2" } ],
        /* a block comment with "quotes" */
        "minecraft:biome_filter": {
          "all_of": [
            { "test": "has_biome_tag", "value": "monster" },
            { "test": "has_biome_tag", "operator": "!=", "value": "mushroom" },
          ]
        }
      }
    ]
  }
}
JSON;

	private const ANIMAL_RULE = <<<'JSON'
{
  "minecraft:spawn_rules": {
    "description": { "identifier": "minecraft:fox", "population_control": "animal" },
    "conditions": [
      {
        "minecraft:spawns_on_surface": {},
        "minecraft:spawns_on_block_filter": [ "minecraft:grass", "minecraft:snow_layer" ],
        "minecraft:brightness_filter": { "min": 7, "max": 15, "adjust_for_weather": false },
        "minecraft:weight": { "default": 8 },
        "minecraft:height_filter": { "min": 60, "max": 80 },
        "minecraft:herd": { "min_size": 2, "max_size": 4, "event": "minecraft:entity_born", "event_skip_count": 2 },
        "minecraft:biome_filter": [ { "test": "has_biome_tag", "value": "taiga" } ]
      }
    ]
  }
}
JSON;

	private static function spot(
		int $y = 64,
		bool $surface = true,
		int $medium = SpawnSpot::MEDIUM_AIR,
		string $below = "minecraft:stone",
		string $feet = "minecraft:air",
		int $blockLight = 0,
		int $skyLight = 15,
		float $distance = 30.0
	) : SpawnSpot{
		return new SpawnSpot(0, $y, 0, $surface, $medium, $below, $feet, $blockLight, $skyLight, BiomeIds::PLAINS, $distance);
	}

	private static function night(int $difficulty = World::DIFFICULTY_NORMAL, Dimension $dimension = Dimension::OVERWORLD) : SpawnContext{
		return new SpawnContext($difficulty, 11, 11, $dimension);
	}

	private static function day(int $difficulty = World::DIFFICULTY_NORMAL) : SpawnContext{
		return new SpawnContext($difficulty, 0, 0);
	}

	private static function monster() : SpawnCondition{
		$conditions = SpawnRuleParser::parse(self::MONSTER_RULE);
		self::assertCount(1, $conditions);
		return $conditions[0];
	}

	private static function animal() : SpawnCondition{
		return SpawnRuleParser::parse(self::ANIMAL_RULE)[0];
	}

	public function testParseMonster() : void{
		$rule = self::monster();
		self::assertSame("minecraft:zombie", $rule->identifier);
		self::assertSame(SpawnCondition::POPULATION_MONSTER, $rule->population);
		self::assertTrue($rule->onSurface);
		self::assertTrue($rule->underground);
		self::assertFalse($rule->underwater);
		self::assertSame(100, $rule->weight);
		self::assertSame(0, $rule->brightnessMin);
		self::assertSame(7, $rule->brightnessMax);
		self::assertTrue($rule->adjustForWeather);
		self::assertSame(1, $rule->difficultyMin);
		self::assertSame(3, $rule->difficultyMax);
		self::assertSame(5, $rule->getDensityLimit(true));
		self::assertNull($rule->getDensityLimit(false));
		self::assertSame(["minecraft:nether_wart_block" => true, "minecraft:shroomlight" => true], $rule->preventedBlocks);
		self::assertSame([[null, 95], ["minecraft:zombie_villager_v2", 5]], $rule->permuteTypes);
		self::assertFalse($rule->timed);
		self::assertSame(["minecraft:zombie", "monster"], SpawnRuleParser::parseDescription(self::MONSTER_RULE));
	}

	public function testParseAnimalAliasesAndHerdEvents() : void{
		$rule = self::animal();
		//"minecraft:grass" is the legacy name of grass blocks
		self::assertSame(["minecraft:grass_block" => true, "minecraft:snow_layer" => true], $rule->onBlocks);
		self::assertSame(60, $rule->heightMin);
		self::assertSame(80, $rule->heightMax);
		$herd = $rule->pickHerd();
		self::assertSame(2, $herd->minSize);
		self::assertSame(4, $herd->maxSize);
		self::assertNull($herd->getEventFor(0));
		self::assertNull($herd->getEventFor(1));
		self::assertSame("minecraft:entity_born", $herd->getEventFor(2));
		self::assertSame("minecraft:entity_born", $herd->getEventFor(3));
	}

	public function testParseErrors() : void{
		$this->expectException(\InvalidArgumentException::class);
		SpawnRuleParser::parse("{\"minecraft:spawn_rules\": {\"description\": {}}}");
	}

	public function testRuleWithoutConditions() : void{
		$json = "{\"minecraft:spawn_rules\": {\"description\": {\"identifier\": \"minecraft:cat\", \"population_control\": \"cat\"}}}";
		self::assertSame([], SpawnRuleParser::parse($json));
	}

	public function testTimedConditionsAreNotClusterSpawns() : void{
		$json = <<<'JSON'
{ "minecraft:spawn_rules": { "description": { "identifier": "minecraft:pillager_patrol", "population_control": "pillager" },
  "conditions": [ { "minecraft:spawns_on_surface": {}, "minecraft:weight": { "default": 100 },
    "minecraft:delay_filter": { "min": 600, "max": 660, "identifier": "x", "spawn_chance": 20 },
    "minecraft:permute_type": [ { "weight": 100, "entity_type": "minecraft:pillager<minecraft:spawn_as_patrol_follower>" } ] } ] } }
JSON;
		$rule = SpawnRuleParser::parse($json)[0];
		self::assertTrue($rule->timed);
		self::assertSame([["minecraft:pillager", 100]], $rule->permuteTypes);
		self::assertFalse($rule->matchesSpot(self::spot(skyLight: 0), self::night()));
		self::assertSame([], (new SpawnRules([$rule], [], "test"))->forBiomeTags([], false));
	}

	public function testBiomeFilter() : void{
		$tags = ["monster" => true, "taiga" => true, "cold" => true];
		self::assertTrue(BiomeFilter::test(null, $tags, false));
		self::assertTrue(BiomeFilter::test(["test" => "has_biome_tag", "value" => "taiga"], $tags, false));
		self::assertFalse(BiomeFilter::test(["test" => "has_biome_tag", "value" => "desert"], $tags, false));
		self::assertTrue(BiomeFilter::test(["test" => "has_biome_tag", "operator" => "not", "value" => "desert"], $tags, false));
		self::assertFalse(BiomeFilter::test(["test" => "has_biome_tag", "operator" => "!=", "value" => "cold"], $tags, false));
		self::assertTrue(BiomeFilter::test(["test" => "has_biome_tag", "operator" => "equals", "value" => "cold"], $tags, false));
		//a list means all of them
		self::assertTrue(BiomeFilter::test([["test" => "has_biome_tag", "value" => "taiga"], ["test" => "has_biome_tag", "value" => "cold"]], $tags, false));
		self::assertFalse(BiomeFilter::test([["test" => "has_biome_tag", "value" => "taiga"], ["test" => "has_biome_tag", "value" => "ocean"]], $tags, false));
		self::assertTrue(BiomeFilter::test(["any_of" => [["test" => "has_biome_tag", "value" => "ocean"], ["test" => "has_biome_tag", "value" => "cold"]]], $tags, false));
		self::assertFalse(BiomeFilter::test(["any_of" => [["test" => "has_biome_tag", "value" => "ocean"]]], $tags, false));
		self::assertFalse(BiomeFilter::test(["none_of" => [["test" => "has_biome_tag", "value" => "cold"]]], $tags, false));
		//nested: wolf-like "taiga but not cold"
		self::assertFalse(BiomeFilter::test(["all_of" => [["test" => "has_biome_tag", "value" => "taiga"], ["test" => "has_biome_tag", "operator" => "not", "value" => "cold"]]], $tags, false));
		self::assertTrue(BiomeFilter::test(["test" => "is_snow_covered"], [], true));
		self::assertFalse(BiomeFilter::test(["test" => "is_snow_covered"], [], false));
		//unknown tests and operators never match
		self::assertFalse(BiomeFilter::test(["test" => "is_daytime"], $tags, false));
		self::assertFalse(BiomeFilter::test(["test" => "has_biome_tag", "operator" => "<", "value" => "taiga"], $tags, false));
	}

	public function testBrightness() : void{
		$rule = self::monster();
		//surface at night (sky 15 - 11 = 4) yes, by day no
		self::assertTrue($rule->matchesSpot(self::spot(), self::night()));
		self::assertFalse($rule->matchesSpot(self::spot(), self::day()));
		//a cave without light during the day
		self::assertTrue($rule->matchesSpot(self::spot(surface: false, skyLight: 0), self::day()));
		//Overworld monsters need block light 0 (Bedrock 1.18+), even when the brightness is low enough
		self::assertFalse($rule->matchesSpot(self::spot(surface: false, blockLight: 1, skyLight: 0), self::day()));
		//adjust_for_weather: the sky reduction including rain is used
		$rain = new SpawnContext(World::DIFFICULTY_NORMAL, 8, 3);
		self::assertTrue($rule->matchesSpot(self::spot(), $rain));

		$animal = self::animal();
		$grass = self::spot(below: "minecraft:grass_block");
		self::assertTrue($animal->matchesSpot($grass, self::day()));
		self::assertFalse($animal->matchesSpot($grass, self::night()));
		//animals ignore the weather (adjust_for_weather false): only the time reduction counts
		self::assertTrue($animal->matchesSpot($grass, $rain));
		//torchlight is enough for animals at night
		self::assertTrue($animal->matchesSpot(self::spot(below: "minecraft:grass_block", blockLight: 12), self::night()));
	}

	public function testNetherBlockLightRuleOnlyInOverworld() : void{
		$rule = self::monster();
		$spot = self::spot(surface: false, blockLight: 5, skyLight: 0);
		self::assertFalse($rule->matchesSpot($spot, self::night(World::DIFFICULTY_NORMAL, Dimension::OVERWORLD)));
		self::assertTrue($rule->matchesSpot($spot, self::night(World::DIFFICULTY_NORMAL, Dimension::NETHER)));
	}

	public function testDifficulty() : void{
		$rule = self::monster();
		self::assertFalse($rule->matchesSpot(self::spot(), self::night(World::DIFFICULTY_PEACEFUL)));
		self::assertTrue($rule->matchesSpot(self::spot(), self::night(World::DIFFICULTY_EASY)));
		self::assertTrue($rule->matchesSpot(self::spot(), self::night(World::DIFFICULTY_HARD)));
		//no difficulty filter: any difficulty
		self::assertTrue(self::animal()->matchesSpot(self::spot(below: "minecraft:grass_block"), new SpawnContext(World::DIFFICULTY_PEACEFUL, 0, 0)));
	}

	public function testHeightAndDistance() : void{
		$animal = self::animal();
		self::assertFalse($animal->matchesSpot(self::spot(y: 59, below: "minecraft:grass_block"), self::day()));
		self::assertTrue($animal->matchesSpot(self::spot(y: 60, below: "minecraft:grass_block"), self::day()));
		self::assertTrue($animal->matchesSpot(self::spot(y: 80, below: "minecraft:grass_block"), self::day()));
		self::assertFalse($animal->matchesSpot(self::spot(y: 81, below: "minecraft:grass_block"), self::day()));

		$rule = self::monster();
		//default ring: 24 to 44 blocks from the nearest player
		self::assertFalse($rule->matchesSpot(self::spot(distance: 23.9), self::night()));
		self::assertTrue($rule->matchesSpot(self::spot(distance: 24.0), self::night()));
		self::assertTrue($rule->matchesSpot(self::spot(distance: 44.0), self::night()));
		self::assertFalse($rule->matchesSpot(self::spot(distance: 44.5), self::night()));
		//a larger simulation distance moves the outer edge
		self::assertTrue($rule->matchesSpot(self::spot(distance: 100.0), new SpawnContext(World::DIFFICULTY_NORMAL, 11, 11, Dimension::OVERWORLD, 24, 128)));

		//distance_filter overrides the ring (fish: 12 to 32)
		$fish = new SpawnCondition("minecraft:cod", SpawnCondition::POPULATION_WATER_ANIMAL, true, false, true, false, 10, distanceMin: 12, distanceMax: 32);
		$water = self::spot(medium: SpawnSpot::MEDIUM_WATER, distance: 15.0);
		self::assertTrue($fish->matchesSpot($water, self::day()));
		self::assertFalse($fish->matchesSpot(self::spot(medium: SpawnSpot::MEDIUM_WATER, distance: 35.0), self::day()));
	}

	public function testBlockFilters() : void{
		$animal = self::animal();
		self::assertFalse($animal->matchesSpot(self::spot(below: "minecraft:stone"), self::day()));
		self::assertTrue($animal->matchesSpot(self::spot(below: "minecraft:grass_block"), self::day()));
		//a snow layer at the feet counts as the block the mob spawns on
		self::assertTrue($animal->matchesSpot(self::spot(below: "minecraft:dirt", feet: "minecraft:snow_layer"), self::day()));

		$rule = self::monster();
		self::assertFalse($rule->matchesSpot(self::spot(below: "minecraft:shroomlight"), self::night()));
		self::assertTrue($rule->matchesSpot(self::spot(below: "minecraft:netherrack"), self::night()));
	}

	public function testPlacement() : void{
		$rule = self::monster();
		self::assertTrue($rule->matchesPlacement(self::spot(surface: true)));
		self::assertTrue($rule->matchesPlacement(self::spot(surface: false)));
		self::assertFalse($rule->matchesPlacement(self::spot(medium: SpawnSpot::MEDIUM_WATER)));
		self::assertFalse($rule->matchesPlacement(self::spot(medium: SpawnSpot::MEDIUM_LAVA)));

		$animal = self::animal();
		self::assertFalse($animal->matchesPlacement(self::spot(surface: false)), "surface-only rule underground");

		$squid = new SpawnCondition("minecraft:squid", SpawnCondition::POPULATION_ANIMAL, true, false, true, false, 8);
		self::assertTrue($squid->matchesPlacement(self::spot(medium: SpawnSpot::MEDIUM_WATER)));
		self::assertFalse($squid->matchesPlacement(self::spot(medium: SpawnSpot::MEDIUM_AIR)), "aquatic rules need water");

		$strider = new SpawnCondition("minecraft:strider", SpawnCondition::POPULATION_ANIMAL, false, true, false, true, 20);
		self::assertTrue($strider->matchesPlacement(self::spot(surface: false, medium: SpawnSpot::MEDIUM_LAVA)));
		self::assertFalse($strider->matchesPlacement(self::spot(surface: false)));

		$bubble = new SpawnCondition("minecraft:axolotl", SpawnCondition::POPULATION_WATER_ANIMAL, false, true, true, false, 10, disallowBubble: true);
		self::assertFalse($bubble->matchesSpot(self::spot(surface: false, medium: SpawnSpot::MEDIUM_WATER, feet: "minecraft:bubble_column"), self::day()));
		self::assertTrue($bubble->matchesSpot(self::spot(surface: false, medium: SpawnSpot::MEDIUM_WATER, feet: "minecraft:water"), self::day()));
	}

	public function testDensityLimitZeroForbidsPlacement() : void{
		//ghast: "density_limit": { "surface": 0, "underground": 2 }
		$ghast = new SpawnCondition("minecraft:ghast", SpawnCondition::POPULATION_MONSTER, true, true, false, false, 40, densitySurface: 0, densityUnderground: 2);
		$nether = self::night(World::DIFFICULTY_NORMAL, Dimension::NETHER);
		self::assertFalse($ghast->matchesSpot(self::spot(surface: true, skyLight: 0), $nether));
		self::assertTrue($ghast->matchesSpot(self::spot(surface: false, skyLight: 0), $nether));
	}

	public function testHerdSizesAndPermute() : void{
		mt_srand(1234);
		$herd = new SpawnHerd(2, 4);
		$seen = [];
		for($i = 0; $i < 200; ++$i){
			$size = $herd->rollSize();
			self::assertGreaterThanOrEqual(2, $size);
			self::assertLessThanOrEqual(4, $size);
			$seen[$size] = true;
		}
		self::assertCount(3, $seen);
		self::assertSame(1, (new SpawnHerd(1, 1))->rollSize());
		//no herd: one mob
		self::assertSame(1, (new SpawnCondition("minecraft:x", "monster", true, true, false, false, 1))->pickHerd()->rollSize());
		//initial_event goes to the first members (drowned riders, patrol captains)
		$initial = new SpawnHerd(2, 5, "follower", 1, "captain", 1);
		self::assertSame("captain", $initial->getEventFor(0));
		self::assertSame("follower", $initial->getEventFor(1));

		$rule = self::monster();
		$villagers = 0;
		for($n = 0; $n < 4000; ++$n){
			if($rule->pickEntityType() === "minecraft:zombie_villager_v2"){
				++$villagers;
			}
		}
		//5% expected
		self::assertGreaterThan(120, $villagers);
		self::assertLessThan(290, $villagers);
	}

	public function testRulesIndexAndPopulations() : void{
		$rules = new SpawnRules([self::monster(), self::animal()], ["minecraft:cat" => "cat"], "test");
		self::assertSame("monster", $rules->getPopulation("minecraft:zombie"));
		self::assertSame("monster", $rules->getPopulation("minecraft:zombie_villager_v2"), "permuted types count like their rule");
		self::assertSame("animal", $rules->getPopulation("minecraft:fox"));
		self::assertSame("cat", $rules->getPopulation("minecraft:cat"));
		self::assertNull($rules->getPopulation("minecraft:pig"));

		$taiga = $rules->forBiomeTags(["taiga" => true, "monster" => true], false);
		self::assertCount(2, $taiga);
		self::assertCount(1, $rules->forBiomeTags(["monster" => true], false));
		self::assertCount(0, $rules->forBiomeTags(["monster" => true, "mushroom" => true], false));
	}

	public function testBuiltinRules() : void{
		$rules = SpawnRules::builtin();
		self::assertSame("built-in", $rules->getSource());
		self::assertCount(8, $rules->getConditions());
		$plains = $rules->forBiomeTags(["animal" => true, "monster" => true, "overworld" => true], false);
		self::assertCount(8, $plains);
		self::assertCount(4, $rules->forBiomeTags(["monster" => true], false));
		self::assertCount(0, $rules->forBiomeTags(["nether" => true], false));
	}

	public function testPopulationCaps() : void{
		self::assertSame(8, PopulationCaps::get(Dimension::OVERWORLD, "monster", true));
		self::assertSame(16, PopulationCaps::get(Dimension::OVERWORLD, "monster", false));
		self::assertSame(4, PopulationCaps::get(Dimension::OVERWORLD, "animal", true));
		self::assertSame(2, PopulationCaps::get(Dimension::OVERWORLD, "ambient", false));
		self::assertSame(36, PopulationCaps::get(Dimension::OVERWORLD, "water_animal", true));
		self::assertSame(0, PopulationCaps::get(Dimension::NETHER, "monster", true));
		self::assertSame(16, PopulationCaps::get(Dimension::NETHER, "monster", false));
		self::assertSame(4, PopulationCaps::get(Dimension::NETHER, "animal", false));
		self::assertSame(10, PopulationCaps::get(Dimension::END, "monster", true));
		self::assertSame(0, PopulationCaps::get(Dimension::OVERWORLD, "cat", true));
	}

	public function testSlimeChunks() : void{
		//MT19937 reference outputs
		self::assertSame(3499211612, SlimeChunk::firstMt19937Output(5489));
		self::assertSame(2357136044, SlimeChunk::firstMt19937Output(0));
		//known Bedrock slime chunks (slime-finder-pe tests: blocks (-16, 0) and (1744, 48) are slime chunks)
		self::assertTrue(SlimeChunk::isSlimeChunk(-1, 0));
		self::assertTrue(SlimeChunk::isSlimeChunk(109, 3));
		self::assertFalse(SlimeChunk::isSlimeChunk(0, 0));
		self::assertFalse(SlimeChunk::isSlimeChunk(110, 3));
		$count = 0;
		for($x = -50; $x < 50; ++$x){
			for($z = -50; $z < 50; ++$z){
				if(SlimeChunk::isSlimeChunk($x, $z)){
					++$count;
				}
			}
		}
		//about 1 in 10
		self::assertGreaterThan(900, $count);
		self::assertLessThan(1100, $count);
	}

	public function testVanillaFilesAreChecked() : void{
		$dir = sys_get_temp_dir() . "/pm-spawn-rules-" . uniqid();
		mkdir($dir);
		try{
			self::assertFalse(VanillaSpawnRules::isComplete($dir));
			self::assertGreaterThan(50, count(VanillaSpawnRules::FILES));
			foreach(VanillaSpawnRules::FILES as $file => $_){
				file_put_contents($dir . "/" . $file, self::MONSTER_RULE);
			}
			self::assertTrue(VanillaSpawnRules::isComplete($dir));
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("Checksum mismatch");
			VanillaSpawnRules::loadFrom($dir);
		}finally{
			foreach(VanillaSpawnRules::FILES as $file => $_){
				@unlink($dir . "/" . $file);
			}
			@rmdir($dir);
		}
	}
}
