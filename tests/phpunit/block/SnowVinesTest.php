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

namespace pocketmine\block;

use pocketmine\block\utils\SupportType;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\math\Facing;
use pocketmine\world\World;
use function array_filter;
use function count;

/**
 * Snow accumulation and melting, vine growth, eyeblossom patches and frogspawn hatching, in an in-memory world (stone
 * floor at y=0).
 */
final class SnowVinesTest extends MobTestCase{

	private function setBiome(int $x, int $z, int $biome) : void{
		for($y = -1; $y <= 12; ++$y){
			$this->world->setBiomeId($x, $y, $z, $biome);
		}
	}

	private function setBlockLight(int $x, int $y, int $z, int $level) : void{
		$chunk = $this->world->getChunk($x >> 4, $z >> 4);
		self::assertNotNull($chunk);
		$chunk->setLightPopulated(true);
		$subChunk = $chunk->getSubChunk($y >> 4);
		$light = $subChunk->getBlockLightArray();
		$light->set($x & 15, $y & 15, $z & 15, $level);
		$subChunk->setBlockLightArray($light);
	}

	private function snowLayers(int $x, int $y, int $z) : int{
		$block = $this->get($x, $y, $z);
		return $block instanceof SnowLayer ? $block->getLayers() : 0;
	}

	private function snowfall(int $biome, int $x) : void{
		$this->setBiome($x, 0, $biome);
		$this->world->getWeather()->setRain(100000);
		for($i = 0; $i < 30; ++$i){
			$this->world->getWeather()->tickPrecipitation($x, 0);
		}
	}

	public function testSnowStacksUpToTheBiomeAccumulation() : void{
		$this->snowfall(BiomeIds::COLD_TAIGA, 0);
		self::assertSame(4, $this->snowLayers(0, 1, 0), "cold taiga: snow_accumulation max 0.5");

		$this->snowfall(BiomeIds::ICE_PLAINS, 2);
		self::assertSame(8, $this->snowLayers(2, 1, 0), "ice plains: 1.0");
		self::assertSame(0, $this->snowLayers(2, 2, 0));

		$this->snowfall(BiomeIds::ICE_PLAINS_SPIKES, 4);
		self::assertSame(8, $this->snowLayers(4, 1, 0), "ice spikes: 1.5, a full layer...");
		self::assertSame(4, $this->snowLayers(4, 2, 0), "...and 4 layers on it");
	}

	public function testSnowSettlesOnLeaves() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_LEAVES()->setNoDecay(true));
		$this->snowfall(BiomeIds::COLD_TAIGA, 0);
		self::assertSame(4, $this->snowLayers(0, 2, 0));
		self::assertSame(SupportType::NONE, VanillaBlocks::OAK_LEAVES()->getSupportType(Facing::UP), "leaves still hold nothing else");
	}

	public function testSnowMeltsOneLayerAtLight12() : void{
		$this->setBiome(0, 0, BiomeIds::ICE_PLAINS);
		$this->set(0, 1, 0, VanillaBlocks::SNOW_LAYER()->setLayers(3));
		$this->setBlockLight(0, 1, 0, 11);
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(3, $this->snowLayers(0, 1, 0), "no melting below light 12 in a snowy biome");
		$this->setBlockLight(0, 1, 0, 12);
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(2, $this->snowLayers(0, 1, 0));
		$this->get(0, 1, 0)->onRandomTick();
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
	}

	public function testSnowMeltsInWarmBiomesInTheDark() : void{
		$this->setBiome(0, 0, BiomeIds::PLAINS);
		$this->set(0, 1, 0, VanillaBlocks::SNOW_LAYER()->setLayers(2));
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(1, $this->snowLayers(0, 1, 0));
	}

	/**
	 * Gives the vine at the position up to the given number of random ticks, until the condition holds.
	 *
	 * @phpstan-param \Closure() : bool $done
	 */
	private function tickVine(int $x, int $y, int $z, int $ticks, \Closure $done) : bool{
		for($i = 0; $i < $ticks; ++$i){
			$vine = $this->get($x, $y, $z);
			self::assertInstanceOf(Vine::class, $vine);
			$vine->onRandomTick();
			if($done()){
				return true;
			}
		}
		return false;
	}

	/**
	 * Stone wall at x=1 (z -4..4, y 1..6) and a vine on it at (0, 1, 0).
	 */
	private function vineOnWall() : void{
		for($z = -4; $z <= 4; ++$z){
			for($y = 1; $y <= 6; ++$y){
				$this->set(1, $y, $z, VanillaBlocks::STONE());
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::VINES()->setFace(Facing::EAST, true));
	}

	public function testVineGrowsUpAndSideways() : void{
		$this->vineOnWall();
		self::assertTrue($this->tickVine(0, 1, 0, 2000, fn() => $this->get(0, 2, 0) instanceof Vine), "grows up");
		$above = $this->get(0, 2, 0);
		self::assertInstanceOf(Vine::class, $above);
		self::assertSame([Facing::EAST => Facing::EAST], $above->getFaces());
		self::assertTrue($this->tickVine(0, 1, 0, 2000, fn() => $this->get(0, 1, 1) instanceof Vine || $this->get(0, 1, -1) instanceof Vine), "spreads along the wall");
	}

	public function testVineHangsDown() : void{
		$this->vineOnWall();
		$this->set(0, 6, 2, VanillaBlocks::VINES()->setFace(Facing::EAST, true));
		self::assertTrue($this->tickVine(0, 6, 2, 2000, fn() => $this->get(0, 5, 2) instanceof Vine));
		$below = $this->get(0, 5, 2);
		self::assertInstanceOf(Vine::class, $below);
		self::assertSame([Facing::EAST => Facing::EAST], $below->getFaces());
	}

	public function testVineStopsSpreadingWithFiveVinesAround() : void{
		$this->vineOnWall();
		foreach([-4, -3, 3, 4] as $z){
			$this->set(0, 1, $z, VanillaBlocks::VINES()->setFace(Facing::EAST, true));
		}
		//stone floor below: only sideways or upward growth could happen
		$this->tickVine(0, 1, 0, 2000, fn() => false);
		foreach([[0, 2, 0], [0, 1, 1], [0, 1, -1], [-1, 1, 0]] as [$x, $y, $z]){
			self::assertSame(BlockTypeIds::AIR, $this->get($x, $y, $z)->getTypeId());
		}
		$vine = $this->get(0, 1, 0);
		self::assertInstanceOf(Vine::class, $vine);
		self::assertCount(1, $vine->getFaces());

		$this->set(0, 1, 4, VanillaBlocks::AIR());
		self::assertTrue($this->tickVine(0, 1, 0, 2000, fn() => $this->get(0, 2, 0) instanceof Vine || $this->get(0, 1, 1) instanceof Vine || $this->get(0, 1, -1) instanceof Vine), "4 vines leave room");
	}

	public function testEyeblossomPatchFollows() : void{
		foreach([0, 2, 6] as $x){
			$this->set($x, 0, 0, VanillaBlocks::GRASS());
			$this->set($x, 1, 0, VanillaBlocks::CLOSED_EYEBLOSSOM());
		}
		$this->world->setTime(World::TIME_NIGHT + 1000);
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(BlockTypeIds::OPEN_EYEBLOSSOM, $this->get(0, 1, 0)->getTypeId());
		self::assertTrue($this->world->isBlockUpdateScheduled(2, 1, 0), "within 3 blocks");
		self::assertFalse($this->world->isBlockUpdateScheduled(6, 1, 0));
		$this->runTicks(21);
		self::assertSame(BlockTypeIds::OPEN_EYEBLOSSOM, $this->get(2, 1, 0)->getTypeId(), "2 blocks away: 10-20 ticks");
		self::assertSame(BlockTypeIds::CLOSED_EYEBLOSSOM, $this->get(6, 1, 0)->getTypeId());

		$this->get(6, 1, 0)->onRandomTick();
		self::assertSame(BlockTypeIds::OPEN_EYEBLOSSOM, $this->get(6, 1, 0)->getTypeId());
		$this->world->setTime(World::TIME_NOON);
		$this->get(6, 1, 0)->onRandomTick();
		self::assertSame(BlockTypeIds::CLOSED_EYEBLOSSOM, $this->get(6, 1, 0)->getTypeId(), "closes in the day");
	}

	public function testFrogspawnHatchesOnSchedule() : void{
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::STONE());
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(0, 2, 0, VanillaBlocks::FROG_SPAWN());
		$this->get(0, 2, 0)->onPostPlace();
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 2, 0));

		$start = $this->tick;
		$this->tick = $start + 3598;
		$this->runTicks(1);
		self::assertSame(BlockTypeIds::FROG_SPAWN, $this->get(0, 2, 0)->getTypeId(), "not before 3600 ticks");
		$this->tick = $start + 12000;
		$this->runTicks(1);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 2, 0)->getTypeId(), "hatched by 12000 ticks");
		$tadpoles = count(array_filter($this->world->getEntities(), fn($e) => $e instanceof Tadpole));
		self::assertGreaterThanOrEqual(2, $tadpoles);
		self::assertLessThanOrEqual(6, $tadpoles);
	}

	public function testFrogspawnWithoutScheduleSchedulesOnRandomTick() : void{
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(0, 2, 0, VanillaBlocks::FROG_SPAWN());
		self::assertFalse($this->world->isBlockUpdateScheduled(0, 2, 0));
		$this->get(0, 2, 0)->onRandomTick();
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 2, 0));
		self::assertSame(BlockTypeIds::FROG_SPAWN, $this->get(0, 2, 0)->getTypeId(), "no random-tick hatching");
	}
}
