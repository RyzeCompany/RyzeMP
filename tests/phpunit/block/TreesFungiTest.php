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

use pocketmine\block\tile\Beehive as TileBeehive;
use pocketmine\block\utils\VanillaTreeGrower;
use pocketmine\item\VanillaItems;
use pocketmine\loot\LootTables;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\LightArray;
use pocketmine\world\generator\vanilla\feature\WorldFeatureGrower;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;
use function file_exists;
use function getenv;
use function iterator_to_array;
use function mt_srand;

/**
 * Saplings, propagules, fungi, mushrooms, leaves and nylium (Java 26.3 SaplingBlock, TreeGrower, MushroomBlock,
 * FungusBlock, NyliumBlock; Bedrock leaf decay distance). The vanilla tree features need the vanilla data
 * (POCKETMINE_VANILLA_WORLDGEN_DATA); the other tests run without it.
 */
class TreesFungiTest extends RedstoneTestCase{

	protected function tearDown() : void{
		LootTables::setDataRoot(null);
		parent::tearDown();
	}

	private function useVanillaData() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !file_exists($dir . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		LootTables::setDataRoot($dir);
		//the test world is flat: make it grow the vanilla features like a vanilla world
		WorldFeatureGrower::useDataRoot($this->world, $dir);
		self::assertNotNull(WorldFeatureGrower::get($this->world));
	}

	private function groundFloor(Block $ground, int $radius = 12) : void{
		for($x = -$radius; $x <= $radius; ++$x){
			for($z = -$radius; $z <= $radius; ++$z){
				$this->world->setBlockAt($x, 0, $z, $ground, false);
			}
		}
	}

	private function boneMeal(int $x, int $y, int $z) : bool{
		return $this->get($x, $y, $z)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5));
	}

	/**
	 * Bone meal until the block at the position is no longer of the given class (at most 200 uses).
	 *
	 * @phpstan-param class-string<Block> $class
	 */
	private function boneMealUntilGone(int $x, int $y, int $z, string $class) : int{
		$uses = 0;
		for(; $uses < 200 && $this->get($x, $y, $z) instanceof $class; ++$uses){
			if(!$this->boneMeal($x, $y, $z)){
				break;
			}
		}
		return $uses;
	}

	private function countBlocks(Block $type, int $radius, int $minY, int $maxY) : int{
		$count = 0;
		for($x = -$radius; $x <= $radius; ++$x){
			for($z = -$radius; $z <= $radius; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					if($this->get($x, $y, $z)->hasSameTypeId($type)){
						++$count;
					}
				}
			}
		}
		return $count;
	}

	public function testSaplingBoneMealAdvancesOneStageAtATime() : void{
		$this->groundFloor(VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::OAK_SAPLING());
		mt_srand(42);
		$uses = 0;
		while($uses < 100){
			$sapling = $this->get(0, 1, 0);
			self::assertInstanceOf(Sapling::class, $sapling);
			if($sapling->isReady()){
				break;
			}
			self::assertTrue($this->boneMeal(0, 1, 0), "bone meal is used even when the sapling doesn't advance");
			++$uses;
		}
		$sapling = $this->get(0, 1, 0);
		self::assertInstanceOf(Sapling::class, $sapling, "the first successful use only makes the sapling ready");
		self::assertTrue($sapling->isReady());
		$this->boneMealUntilGone(0, 1, 0, Sapling::class);
		self::assertTrue($this->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::OAK_LOG()));
	}

	public function testSaplingBoneMealSucceedsAboutFortyFivePercent() : void{
		$this->groundFloor(VanillaBlocks::GRASS());
		mt_srand(7);
		$successes = 0;
		for($i = 0; $i < 2000; ++$i){
			$this->set(0, 1, 0, VanillaBlocks::OAK_SAPLING());
			$this->boneMeal(0, 1, 0);
			$block = $this->get(0, 1, 0);
			if($block instanceof Sapling && $block->isReady()){
				++$successes;
			}
		}
		self::assertGreaterThan(800, $successes);
		self::assertLessThan(1000, $successes);
	}

	public function testSaplingDoesNotGrowInLowLight() : void{
		$this->groundFloor(VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::OAK_SAPLING());
		if($this->world->getFullLightAt(0, 2, 0) >= 9){
			self::markTestSkipped("the test world is lit");
		}
		for($i = 0; $i < 500; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		$sapling = $this->get(0, 1, 0);
		self::assertInstanceOf(Sapling::class, $sapling);
		self::assertFalse($sapling->isReady());
	}

	public function testWeightedPickMatchesTreeGrowerWeights() : void{
		mt_srand(1);
		$fancy = 0;
		for($i = 0; $i < 10000; ++$i){
			if(VanillaTreeGrower::pick(VanillaTreeGrower::OAK[0]) === "fancy_oak"){
				++$fancy;
			}
		}
		self::assertGreaterThan(850, $fancy);
		self::assertLessThan(1150, $fancy);

		$tall = 0;
		for($i = 0; $i < 10000; ++$i){
			if(VanillaTreeGrower::pick(VanillaTreeGrower::MANGROVE[0]) === "tall_mangrove"){
				++$tall;
			}
		}
		self::assertGreaterThan(8200, $tall);
		self::assertLessThan(8800, $tall);
	}

	public function testLeavesSevenBlocksFromLogDecay() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_LOG());
		for($x = 1; $x <= 7; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::OAK_LEAVES()->setCheckDecay(true));
		}
		$this->get(6, 1, 0)->onRandomTick();
		self::assertInstanceOf(Leaves::class, $this->get(6, 1, 0), "6 blocks from the log");
		$this->get(7, 1, 0)->onRandomTick();
		self::assertNotInstanceOf(Leaves::class, $this->get(7, 1, 0), "7 blocks from the log");
	}

	public function testLeavesFindLogByShortestPath() : void{
		//a log 3 steps away straight down a leaf line, and a long leaf detour that reaches the same leaves first
		$this->set(0, 1, 0, VanillaBlocks::OAK_LOG());
		foreach([[1, 1, 0], [2, 1, 0], [3, 1, 0], [3, 2, 0], [2, 2, 0], [1, 2, 0], [0, 2, 0]] as [$x, $y, $z]){
			$this->set($x, $y, $z, VanillaBlocks::OAK_LEAVES()->setCheckDecay(true));
		}
		$this->get(3, 2, 0)->onRandomTick();
		self::assertInstanceOf(Leaves::class, $this->get(3, 2, 0));
	}

	public function testMangroveLeavesBoneMealHangsPropagule() : void{
		$this->set(0, 5, 0, VanillaBlocks::MANGROVE_LEAVES());
		self::assertTrue($this->boneMeal(0, 5, 0));
		$propagule = $this->get(0, 4, 0);
		self::assertInstanceOf(MangrovePropagule::class, $propagule);
		self::assertTrue($propagule->isHanging());
		self::assertSame(0, $propagule->getStage());
		//no air below: nothing to do
		self::assertFalse($this->boneMeal(0, 5, 0));
	}

	public function testFungusOffItsNyliumKeepsBoneMeal() : void{
		//BDS 1.26.60 uses the bone meal on the other nylium without growing the fungus (Java keeps it)
		$this->set(0, 0, 0, VanillaBlocks::WARPED_NYLIUM());
		$this->set(0, 1, 0, VanillaBlocks::CRIMSON_FUNGUS());
		self::assertTrue($this->boneMeal(0, 1, 0));
		self::assertInstanceOf(NetherFungus::class, $this->get(0, 1, 0));
		$this->set(0, 0, 0, VanillaBlocks::DIRT());
		self::assertFalse($this->boneMeal(0, 1, 0));
		self::assertInstanceOf(NetherFungus::class, $this->get(0, 1, 0));
	}

	public function testNyliumBoneMealWeights() : void{
		mt_srand(3);
		$counts = ["roots" => 0, "crimson_fungus" => 0, "warped_fungus" => 0];
		for($i = 0; $i < 150; ++$i){
			$this->groundFloor(VanillaBlocks::CRIMSON_NYLIUM(), 3);
			for($x = -3; $x <= 3; ++$x){
				for($z = -3; $z <= 3; ++$z){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::AIR(), false);
				}
			}
			self::assertTrue($this->boneMeal(0, 0, 0));
			$counts["roots"] += $this->countBlocks(VanillaBlocks::CRIMSON_ROOTS(), 3, 1, 1);
			$counts["crimson_fungus"] += $this->countBlocks(VanillaBlocks::CRIMSON_FUNGUS(), 3, 1, 1);
			$counts["warped_fungus"] += $this->countBlocks(VanillaBlocks::WARPED_FUNGUS(), 3, 1, 1);
		}
		$total = $counts["roots"] + $counts["crimson_fungus"] + $counts["warped_fungus"];
		self::assertGreaterThan(300, $total);
		self::assertGreaterThan(0.8, $counts["roots"] / $total);
		self::assertGreaterThan(0.05, $counts["crimson_fungus"] / $total);
		self::assertLessThan(0.04, $counts["warped_fungus"] / $total);
		//only the 5x5 around the block
		self::assertSame(0, $this->countBlocks(VanillaBlocks::CRIMSON_ROOTS(), 3, 1, 1) - $this->countBlocks(VanillaBlocks::CRIMSON_ROOTS(), 2, 1, 1));
	}

	public function testMushroomSpreadStopsAtFive() : void{
		$this->groundFloor(VanillaBlocks::STONE(), 6);
		if($this->world->getPotentialLightAt(0, 1, 0) >= 13){
			self::markTestSkipped("the test world is lit");
		}
		$this->set(0, 1, 0, VanillaBlocks::RED_MUSHROOM());
		mt_srand(11);
		for($i = 0; $i < 3000; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertSame(5, $this->countBlocks(VanillaBlocks::RED_MUSHROOM(), 6, 0, 3));
	}

	/**
	 * Bedrock 26.60: mushrooms are placed (and stay) on any block with a solid top face or a central support, whatever
	 * the light; spreading still needs light below 13 unless the block below is mycelium, podzol or nylium.
	 */
	public function testMushroomPlacementIgnoresLight() : void{
		$this->groundFloor(VanillaBlocks::STONE(), 6);
		//full daylight in the first subchunk
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$chunk->getSubChunk(0)->setBlockSkyLightArray(LightArray::fill(15));
		$chunk->setLightPopulated();
		self::assertGreaterThanOrEqual(13, $this->world->getPotentialLightAt(0, 1, 0), "lit");

		foreach([VanillaBlocks::RED_MUSHROOM(), VanillaBlocks::BROWN_MUSHROOM()] as $i => $mushroom){
			$z = $i * 4;
			$item = $mushroom->asItem();
			$this->world->useItemOn(new Vector3(0, 0, $z), $item, Facing::UP, new Vector3(0.5, 1, 0.5));
			self::assertTrue($this->get(0, 1, $z)->hasSameTypeId($mushroom), $mushroom->getName() . " placed in bright light");
			//still there after a neighbour update
			$this->get(0, 1, $z)->onNearbyBlockChange();
			self::assertTrue($this->get(0, 1, $z)->hasSameTypeId($mushroom), $mushroom->getName() . " survives in bright light");
		}

		//central support (fence post), not on a bottom slab
		$this->set(3, 1, 0, VanillaBlocks::OAK_FENCE());
		$item = VanillaBlocks::RED_MUSHROOM()->asItem();
		$this->world->useItemOn(new Vector3(3, 1, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5));
		self::assertTrue($this->get(3, 2, 0)->hasSameTypeId(VanillaBlocks::RED_MUSHROOM()), "on a fence");
		$this->set(-3, 1, 0, VanillaBlocks::OAK_SLAB());
		$item = VanillaBlocks::RED_MUSHROOM()->asItem();
		$this->world->useItemOn(new Vector3(-3, 1, 0), $item, Facing::UP, new Vector3(0.5, 0.5, 0.5));
		self::assertFalse($this->get(-3, 2, 0)->hasSameTypeId(VanillaBlocks::RED_MUSHROOM()), "not on a bottom slab");

		//losing the support breaks it
		$this->set(3, 1, 0, VanillaBlocks::AIR());
		$this->get(3, 2, 0)->onNearbyBlockChange();
		self::assertFalse($this->get(3, 2, 0)->hasSameTypeId(VanillaBlocks::RED_MUSHROOM()), "no support");

		//spreading keeps the light rule
		$canSpreadAt = new \ReflectionMethod(RedMushroom::class, "canSpreadAt");
		self::assertFalse($canSpreadAt->invoke(null, $this->world, 0, 1, 0), "no spreading in bright light");
		$this->set(0, 0, 0, VanillaBlocks::MYCELIUM());
		self::assertTrue($canSpreadAt->invoke(null, $this->world, 0, 1, 0), "mycelium overrides the light");
	}

	public function testVanillaOakTree() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::OAK_SAPLING());
		mt_srand(5);
		$this->boneMealUntilGone(0, 1, 0, Sapling::class);
		self::assertTrue($this->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::OAK_LOG()));
		self::assertTrue($this->get(0, 0, 0)->hasSameTypeId(VanillaBlocks::DIRT()), "soil beneath the tree");
		self::assertGreaterThan(20, $this->countBlocks(VanillaBlocks::OAK_LEAVES(), 4, 2, 10));
	}

	public function testFancyOakAboutOneInTen() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS(), 32);
		mt_srand(9);
		$fancy = 0;
		$trees = 0;
		for($x = -24; $x <= 24; $x += 16){
			for($z = -24; $z <= 24; $z += 16){
				//each tree in a fresh spot
				for($n = 0; $n < 4; ++$n){
					$ox = $x + ($n % 2) * 8;
					$oz = $z + ($n >> 1) * 8;
					$this->set($ox, 1, $oz, VanillaBlocks::OAK_SAPLING()->setReady(true));
					$this->boneMealUntilGone($ox, 1, $oz, Sapling::class);
					if(!$this->get($ox, 1, $oz)->hasSameTypeId(VanillaBlocks::OAK_LOG())){
						continue;
					}
					++$trees;
					for($y = 2; $y < 20; ++$y){
						for($dx = -3; $dx <= 3; ++$dx){
							for($dz = -3; $dz <= 3; ++$dz){
								$block = $this->get($ox + $dx, $y, $oz + $dz);
								if($block instanceof Wood && $block->getAxis() !== Axis::Y){
									++$fancy;
									continue 4;
								}
							}
						}
					}
				}
			}
		}
		self::assertGreaterThan(10, $trees);
		self::assertGreaterThanOrEqual(1, $fancy);
		self::assertLessThan($trees / 2, $fancy);
	}

	public function testTwoByTwoSpruceGrowsMegaTreeWithPodzol() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS());
		foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $z]){
			$this->set($x, 1, $z, VanillaBlocks::SPRUCE_SAPLING());
		}
		mt_srand(2);
		$this->boneMealUntilGone(1, 1, 1, Sapling::class);
		foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $z]){
			self::assertTrue($this->get($x, 1, $z)->hasSameTypeId(VanillaBlocks::SPRUCE_LOG()), "trunk at $x $z");
			self::assertTrue($this->get($x, 12, $z)->hasSameTypeId(VanillaBlocks::SPRUCE_LOG()), "mega trunk height at $x $z");
		}
		self::assertGreaterThan(0, $this->countBlocks(VanillaBlocks::PODZOL(), 6, 0, 0));
	}

	public function testPlantedPropaguleGrowsMangrove() : void{
		$this->useVanillaData();
		//mangrove roots grow through mud and air and must end on something else (MangroveRootPlacer gives up otherwise)
		for($y = -6; $y <= 0; ++$y){
			for($x = -8; $x <= 8; ++$x){
				for($z = -8; $z <= 8; ++$z){
					$this->world->setBlockAt($x, $y, $z, $y >= -1 ? VanillaBlocks::MUD() : VanillaBlocks::STONE(), false);
				}
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::MANGROVE_PROPAGULE());
		mt_srand(4);
		$this->boneMealUntilGone(0, 1, 0, MangrovePropagule::class);
		self::assertGreaterThan(0, $this->countBlocks(VanillaBlocks::MANGROVE_LOG(), 3, 1, 15));
		self::assertGreaterThan(10, $this->countBlocks(VanillaBlocks::MANGROVE_LEAVES(), 6, 1, 20));
	}

	public function testPlantedPropaguleGrowsInTheDark() : void{
		$this->useVanillaData();
		for($y = -6; $y <= 0; ++$y){
			for($x = -8; $x <= 8; ++$x){
				for($z = -8; $z <= 8; ++$z){
					$this->world->setBlockAt($x, $y, $z, $y >= -1 ? VanillaBlocks::MUD() : VanillaBlocks::STONE(), false);
				}
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::MANGROVE_PROPAGULE());
		self::assertLessThan(9, $this->world->getFullLightAt(0, 2, 0), "the test world is dark");
		mt_srand(4);
		for($i = 0; $i < 300 && $this->get(0, 1, 0) instanceof MangrovePropagule; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertGreaterThan(0, $this->countBlocks(VanillaBlocks::MANGROVE_LOG(), 3, 1, 15));
	}

	/**
	 * Java-shaped crowns (cherry, jungle, fancy oak, acacia) have leaves up to 6 blocks from a log: none of them may
	 * decay once their decay check is triggered.
	 */
	public function testGrownTreesKeepTheirLeaves() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS(), 32);
		$trees = 0;
		$leaves = 0;
		foreach([VanillaBlocks::CHERRY_SAPLING(), VanillaBlocks::JUNGLE_SAPLING(), VanillaBlocks::ACACIA_SAPLING(), VanillaBlocks::OAK_SAPLING()] as $i => $sapling){
			for($n = 0; $n < 3; ++$n){
				//the chunks around each tree must be loaded (-32..47)
				$ox = -16 + $i * 11;
				$oz = -16 + $n * 16;
				mt_srand(100 + $i * 10 + $n);
				$this->set($ox, 1, $oz, (clone $sapling)->setReady(true));
				$this->boneMealUntilGone($ox, 1, $oz, Sapling::class);
				if($this->get($ox, 1, $oz) instanceof Sapling){
					continue;
				}
				++$trees;
				for($x = $ox - 9; $x <= $ox + 9; ++$x){
					for($z = $oz - 9; $z <= $oz + 9; ++$z){
						for($y = 2; $y <= 30; ++$y){
							$block = $this->get($x, $y, $z);
							if($block instanceof Leaves){
								++$leaves;
								$block->onNearbyBlockChange();
								$this->get($x, $y, $z)->onRandomTick();
								self::assertInstanceOf(Leaves::class, $this->get($x, $y, $z), "leaves at $x $y $z of a grown " . $sapling->getName());
							}
						}
					}
				}
			}
		}
		self::assertGreaterThan(8, $trees);
		self::assertGreaterThan(500, $leaves);
	}

	public function testTwistingVinesNeedTheBlockAboveFree() : void{
		mt_srand(21);
		$withVines = 0;
		for($i = 0; $i < 400; ++$i){
			$this->groundFloor(VanillaBlocks::WARPED_NYLIUM(), 5);
			for($x = -5; $x <= 5; ++$x){
				for($z = -5; $z <= 5; ++$z){
					for($y = 1; $y <= 4; ++$y){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::AIR(), false);
					}
				}
			}
			self::assertTrue($this->boneMeal(0, 0, 0));
			if($this->countBlocks(VanillaBlocks::TWISTING_VINES(), 5, 1, 4) > 0){
				++$withVines;
			}
		}
		//vanilla: 1 in 8, only when the roots and sprouts left the block above empty (about 12%): about 1.5%
		self::assertLessThan(20, $withVines);
	}

	public function testHugeFungusOnNylium() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::CRIMSON_NYLIUM());
		$this->set(0, 1, 0, VanillaBlocks::CRIMSON_FUNGUS());
		mt_srand(6);
		$this->boneMealUntilGone(0, 1, 0, NetherFungus::class);
		self::assertTrue($this->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::CRIMSON_STEM()));
		self::assertGreaterThan(0, $this->countBlocks(VanillaBlocks::NETHER_WART_BLOCK(), 4, 3, 30));
	}

	public function testHugeMushroomFromBoneMeal() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::MYCELIUM());
		$this->set(0, 1, 0, VanillaBlocks::RED_MUSHROOM());
		mt_srand(8);
		$this->boneMealUntilGone(0, 1, 0, RedMushroom::class);
		self::assertTrue($this->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::MUSHROOM_STEM()));
		self::assertGreaterThan(5, $this->countBlocks(VanillaBlocks::RED_MUSHROOM_BLOCK(), 3, 3, 15));
	}

	public function testPlacingDoesNotTouchTheWorld() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::OAK_SAPLING());
		$grower = WorldFeatureGrower::get($this->world);
		self::assertNotNull($grower);
		$growth = $grower->place($this->world, "minecraft:oak", 0, 1, 0, [[0, 1, 0, false]], 7);
		self::assertNotNull($growth);
		self::assertGreaterThan(20, count(iterator_to_array($growth->transaction->getBlocks(), false)));
		//the region copied the subchunks it wrote: the world still has its sapling and no tree
		self::assertInstanceOf(Sapling::class, $this->get(0, 1, 0));
		self::assertSame(0, $this->countBlocks(VanillaBlocks::OAK_LEAVES(), 4, 2, 10));
		self::assertSame(0, $this->countBlocks(VanillaBlocks::OAK_LOG(), 4, 1, 10));
		//a blocked spot places nothing
		$this->set(0, 3, 0, VanillaBlocks::STONE());
		self::assertNull($grower->place($this->world, "minecraft:oak", 0, 1, 0, [[0, 1, 0, false]], 7));
		self::assertInstanceOf(Sapling::class, $this->get(0, 1, 0));
	}

	public function testOnlyVanillaWorldsGrowVanillaFeatures() : void{
		//the test world uses the flat generator
		self::assertNull(WorldFeatureGrower::get($this->world));
	}

	public function testBeeNestTreeHasBees() : void{
		$this->useVanillaData();
		$this->groundFloor(VanillaBlocks::GRASS());
		$grower = WorldFeatureGrower::get($this->world);
		self::assertNotNull($grower);
		self::assertFalse($grower->hasFlowers($this->world, 0, 1, 0));
		$this->set(2, 1, 1, VanillaBlocks::POPPY());
		self::assertTrue($grower->hasFlowers($this->world, 0, 1, 0));
		//a bee nest comes with 5% of the trees: try seeds until one has it
		for($seed = 0; $seed < 400; ++$seed){
			$growth = $grower->place($this->world, "minecraft:oak_bees_005", 0, 1, 0, [], $seed);
			self::assertNotNull($growth);
			$nest = null;
			foreach($growth->transaction->getBlocks() as [$x, $y, $z, $block]){
				if($block->getTypeId() === BlockTypeIds::BEE_NEST){
					$nest = [$x, $y, $z];
				}
			}
			if($nest === null){
				continue;
			}
			self::assertTrue($growth->apply($this->world));
			$tile = $this->world->getTileAt($nest[0], $nest[1], $nest[2]);
			self::assertInstanceOf(TileBeehive::class, $tile);
			self::assertGreaterThanOrEqual(2, $tile->getOccupantCount());
			self::assertLessThanOrEqual(3, $tile->getOccupantCount());
			return;
		}
		self::fail("no bee nest in 400 trees");
	}
}
