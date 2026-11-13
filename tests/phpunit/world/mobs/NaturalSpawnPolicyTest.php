<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use PHPUnit\Framework\TestCase;
use pocketmine\world\mobs\spawn\NaturalSpawnPolicy as Policy;
use pocketmine\world\World;

final class NaturalSpawnPolicyTest extends TestCase{
	public function testPlayerDistanceBoundaries() : void{
		self::assertFalse(Policy::withinPlayerRange(23.99**2));
		self::assertTrue(Policy::withinPlayerRange(24**2));
		self::assertTrue(Policy::withinPlayerRange(48**2));
		self::assertFalse(Policy::withinPlayerRange(48.01**2));
	}
	public function testCapsRejectFullPopulations() : void{
		self::assertTrue(Policy::underCap(0,1));
		self::assertFalse(Policy::underCap(1,1));
		self::assertFalse(Policy::underCap(0,0));
		self::assertFalse(Policy::underCap(0,-2));
	}
	public function testThreeDimensionalDistanceRules() : void{
		self::assertFalse(Policy::withinPlayerRange(24**2 + 48**2));
		self::assertTrue(Policy::withinPlayerRange(30**2 + 20**2));
	}
	public function testBiomeHabitatRules() : void{
		self::assertTrue(Policy::canSpawnSquidBiome("ocean"));
		self::assertTrue(Policy::canSpawnSquidBiome("deep_cold_ocean"));
		self::assertTrue(Policy::canSpawnSquidBiome("frozen_river"));
		self::assertFalse(Policy::canSpawnSquidBiome("plains"));
		self::assertFalse(Policy::canSpawnSquidBiome(null));
		self::assertTrue(Policy::canSpawnFarmAnimalBiome("plains"));
		self::assertTrue(Policy::canSpawnFarmAnimalBiome("birch_forest"));
		self::assertTrue(Policy::canSpawnFarmAnimalBiome("savanna"));
		self::assertFalse(Policy::canSpawnFarmAnimalBiome("desert"));
		self::assertFalse(Policy::canSpawnFarmAnimalBiome("mushroom_island"));
		self::assertFalse(Policy::canSpawnFarmAnimalBiome("deep_ocean"));
		self::assertFalse(Policy::canSpawnFarmAnimalBiome(null));
	}
	public function testNineByNineChunkDensityCapAccounting() : void{
		$counts = ["0:0"=>3, "4:0"=>5, "-4:0"=>7, "5:0"=>11, "0:5"=>13];
		self::assertSame(15, Policy::populationInChunkRadius($counts, 0, 0));
		self::assertSame(3, Policy::populationInChunkRadius($counts, 0, 0, 0));
		self::assertSame(16, Policy::populationInChunkRadius($counts, 4, 0, 1));
	}
	public function testVanillaChunkSamplingHonorsExactProbabilityLimits() : void{
		self::assertSame([], Policy::selectTickingChunks(200, 0, 30));
		self::assertSame([], Policy::selectTickingChunks(0, 2000, 30));
		self::assertSame([0, 1, 2], Policy::selectTickingChunks(100, 2000, 3));
		self::assertSame([0, 1], Policy::selectTickingChunks(2, 2000, 30));
		self::assertSame([], Policy::selectTickingChunks(200, 2000, 0));
	}
	public function testConfigurablePlayerDistance() : void{
		self::assertFalse(Policy::withinPlayerRange(64 ** 2));
		self::assertTrue(Policy::withinPlayerRange(64 ** 2, 96));
		self::assertFalse(Policy::withinPlayerRange(97 ** 2, 96));
	}
	public function testSurfaceAndCaveCategoryCapsRemainIndependent() : void{
		$counts = [
			"0:0" => ["animal:surface" => 3, "monster:surface" => 5, "monster:cave" => 9],
			"4:0" => ["animal:surface" => 1],
			"5:0" => ["animal:surface" => 7]
		];
		self::assertSame(4, Policy::populationInChunkRadiusForGroup($counts, 0, 0, "animal", true));
		self::assertSame(5, Policy::populationInChunkRadiusForGroup($counts, 0, 0, "monster", true));
		self::assertSame(9, Policy::populationInChunkRadiusForGroup($counts, 0, 0, "monster", false));
		self::assertSame(0, Policy::populationInChunkRadiusForGroup($counts, 0, 0, "animal", false));
	}
	public function testPeacefulAndLightConstraints() : void{
		self::assertFalse(Policy::canSpawnZombie(World::DIFFICULTY_PEACEFUL,0));
		self::assertFalse(Policy::canSpawnZombie(World::DIFFICULTY_NORMAL,8));
		self::assertTrue(Policy::canSpawnZombie(World::DIFFICULTY_NORMAL,7));
		self::assertFalse(Policy::canSpawnAnimal(8));
		self::assertTrue(Policy::canSpawnAnimal(9));
	}
}
