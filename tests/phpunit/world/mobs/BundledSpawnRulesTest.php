<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\world\mobs\spawn\NaturalSpawnPolicy;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use pocketmine\world\mobs\spawn\SpawnContext;
use pocketmine\world\mobs\spawn\SpawnSpot;

final class BundledSpawnRulesTest extends TestCase{
	public function testSupportedMobsUsePinnedBedrockRules() : void{
		$rules = BundledSpawnRules::get();
		self::assertNotNull($rules);
		self::assertGreaterThan(80, count($rules->getConditions()));
		$plains = $rules->forBiome(BiomeIds::PLAINS);
		$cows = array_values(array_filter($plains, static fn($rule) => $rule->identifier === "minecraft:cow"));
		self::assertCount(1, $cows);
		$day = new SpawnContext(2, 0, 0);
		$grass = new SpawnSpot(0, 64, 0, true, SpawnSpot::MEDIUM_AIR,
			"minecraft:grass_block", "minecraft:air", 0, 15, BiomeIds::PLAINS, 30.0);
		self::assertTrue($cows[0]->matchesSpot($grass, $day));
		self::assertSame([], array_values(array_filter($rules->forBiome(BiomeIds::DESERT),
			static fn($rule) => $rule->identifier === "minecraft:cow")));
	}

	public function testMonsterRulesRejectBlockLight() : void{
		$rules = BundledSpawnRules::get();
		self::assertNotNull($rules);
		$zombies = array_values(array_filter($rules->forBiome(BiomeIds::PLAINS),
			static fn($rule) => $rule->identifier === "minecraft:zombie"));
		self::assertNotEmpty($zombies);
		$night = new SpawnContext(2, 15, 15);
		$dark = new SpawnSpot(0, 64, 0, true, SpawnSpot::MEDIUM_AIR,
			"minecraft:grass_block", "minecraft:air", 0, 15, BiomeIds::PLAINS, 30.0);
		$lit = new SpawnSpot(0, 64, 0, true, SpawnSpot::MEDIUM_AIR,
			"minecraft:grass_block", "minecraft:air", 1, 15, BiomeIds::PLAINS, 30.0);
		self::assertTrue($zombies[0]->matchesSpot($dark, $night));
		self::assertFalse($zombies[0]->matchesSpot($lit, $night));
	}

	public function testSquidUsesAnimalPopulationAndCreeperDensity() : void{
		$rules = BundledSpawnRules::get();
		self::assertNotNull($rules);
		$squids = array_values(array_filter($rules->forBiome(BiomeIds::RIVER),
			static fn($rule) => $rule->identifier === "minecraft:squid"));
		self::assertNotEmpty($squids);
		self::assertSame("animal", $squids[0]->population);
		$riverWater = new SpawnSpot(0, 62, 0, true, SpawnSpot::MEDIUM_WATER,
			"minecraft:water", "minecraft:water", 0, 15, BiomeIds::RIVER, 30.0);
		self::assertTrue($squids[0]->matchesSpot($riverWater, new SpawnContext(2, 0, 0)));
		$creepers = array_values(array_filter($rules->forBiome(BiomeIds::PLAINS),
			static fn($rule) => $rule->identifier === "minecraft:creeper" && $rule->getDensityLimit(true) !== null));
		self::assertNotEmpty($creepers);
		$byType = ["0:0" => ["minecraft:creeper:surface" => 4], "4:0" => ["minecraft:creeper:surface" => 1],
			"5:0" => ["minecraft:creeper:surface" => 2]];
		self::assertSame(5, NaturalSpawnPolicy::populationInChunkRadiusForGroup($byType, 0, 0, "minecraft:creeper", true));
		$density = $creepers[0]->getDensityLimit(true);
		self::assertNotNull($density);
		self::assertFalse(NaturalSpawnPolicy::underCap(5, $density));
	}
}
