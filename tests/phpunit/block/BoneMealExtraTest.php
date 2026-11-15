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

use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\UnderwaterBoneMeal;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;
use function abs;
use function max;
use function min;
use function mt_srand;

/**
 * Bone meal on sea pickles, under water, on shelf mushrooms, pale moss and nether vines.
 */
final class BoneMealExtraTest extends RedstoneTestCase{

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	private function boneMeal(int $count = 2) : Item{
		return VanillaItems::BONE_MEAL()->setCount($count);
	}

	private function click() : Vector3{
		return new Vector3(0.5, 0.5, 0.5);
	}

	/**
	 * Fills the given box with blocks, without block updates.
	 */
	private function fill(int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, Block $block) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($y = $minY; $y <= $maxY; ++$y){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isSurvival")->willReturn(true);
		$player->method("getId")->willReturn(424242);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * A live coral floor at y=0 with water above it (y=1..3) around the origin.
	 */
	private function coralReef() : void{
		$this->fill(-4, 0, -4, 4, 0, 4, VanillaBlocks::CORAL_BLOCK());
		$this->fill(-4, 1, -4, 4, 3, 4, VanillaBlocks::WATER());
	}

	public function testSeaPickleOnCoralGrowsColony() : void{
		$neighbours = 0;
		for($seed = 1; $seed <= 20; ++$seed){
			mt_srand($seed);
			$this->coralReef();
			$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(true));
			$item = $this->boneMeal();
			self::assertTrue($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
			self::assertSame(1, $item->getCount(), "bone meal used");

			$pickle = $this->get(0, 1, 0);
			self::assertInstanceOf(SeaPickle::class, $pickle);
			self::assertSame(SeaPickle::MAX_COUNT, $pickle->getCount());

			for($x = -4; $x <= 4; ++$x){
				for($z = -4; $z <= 4; ++$z){
					if($x === 0 && $z === 0){
						continue;
					}
					$block = $this->get($x, 1, $z);
					if($block instanceof SeaPickle){
						self::assertLessThanOrEqual(2, abs($x) + abs($z), "pickle at $x $z outside the diamond");
						self::assertTrue($block->isUnderwater());
						++$neighbours;
					}
				}
			}
		}
		//12 spots around the pickle with a 1/6 chance each: about 2 per use
		self::assertGreaterThan(10, $neighbours);
		self::assertLessThan(80, $neighbours);
	}

	public function testSeaPickleOnlySpreadsOntoLiveCoral() : void{
		mt_srand(5);
		$this->fill(-4, 0, -4, 4, 0, 4, VanillaBlocks::SAND());
		$this->fill(-4, 1, -4, 4, 3, 4, VanillaBlocks::WATER());
		$this->set(0, 0, 0, VanillaBlocks::CORAL_BLOCK());
		$this->set(1, 0, 0, VanillaBlocks::CORAL_BLOCK()->setDead(true));
		for($i = 0; $i < 30; ++$i){
			$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(true));
			$this->get(0, 1, 0)->onInteract($this->boneMeal(), Facing::UP, $this->click());
		}
		for($x = -4; $x <= 4; ++$x){
			for($z = -4; $z <= 4; ++$z){
				if($x !== 0 || $z !== 0){
					self::assertNotInstanceOf(SeaPickle::class, $this->get($x, 1, $z), "$x $z");
				}
			}
		}
	}

	public function testSeaPickleNotOnCoral() : void{
		$this->fill(-1, 0, -1, 1, 0, 1, VanillaBlocks::SAND());
		$this->fill(-1, 1, -1, 1, 2, 1, VanillaBlocks::WATER());
		$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(true));
		$item = $this->boneMeal();
		//Bedrock wastes the bone meal
		self::assertTrue($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
		self::assertSame(1, $item->getCount());
		$pickle = $this->get(0, 1, 0);
		self::assertInstanceOf(SeaPickle::class, $pickle);
		self::assertSame(1, $pickle->getCount());

		//a dry pickle on coral does nothing
		$this->set(0, 0, 0, VanillaBlocks::CORAL_BLOCK());
		$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(false));
		$item = $this->boneMeal();
		self::assertFalse($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
		self::assertSame(2, $item->getCount());
	}

	/**
	 * Sand at y=0, water from y=1 up to $topY, air above.
	 */
	private function seabed(int $topY) : void{
		$this->fill(-8, 0, -8, 8, 0, 8, VanillaBlocks::SAND());
		$this->fill(-8, 1, -8, 8, $topY, 8, VanillaBlocks::WATER());
	}

	/**
	 * @return int[] seagrass, corals and coral fans (floor and wall) found around the origin
	 * @phpstan-return array{int, int, int}
	 */
	private function countPlants() : array{
		$seagrass = $corals = $fans = 0;
		for($x = -8; $x <= 8; ++$x){
			for($y = 1; $y <= 3; ++$y){
				for($z = -8; $z <= 8; ++$z){
					$block = $this->get($x, $y, $z);
					if($block instanceof Seagrass || $block instanceof TallSeagrass){
						++$seagrass;
					}elseif($block instanceof Coral){
						++$corals;
					}elseif($block instanceof FloorCoralFan || $block instanceof WallCoralFan){
						++$fans;
					}
				}
			}
		}
		return [$seagrass, $corals, $fans];
	}

	public function testUnderwaterGroundGrowsSeagrass() : void{
		mt_srand(42);
		$this->seabed(3);
		$item = $this->boneMeal();
		$returnedItems = [];
		self::assertSame(ItemUseResult::SUCCESS, $item->onInteractBlock($this->player(), $this->get(0, 1, 0), $this->get(0, 0, 0), Facing::UP, $this->click(), $returnedItems));
		self::assertSame(1, $item->getCount());
		[$seagrass, $corals, $fans] = $this->countPlants();
		self::assertGreaterThan(10, $seagrass);
		self::assertGreaterThan(0, $corals + $fans, "corals grow in any biome on Bedrock");

		//the plants hold water and stand on the sand
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				$block = $this->get($x, 1, $z);
				if($block instanceof Seagrass){
					self::assertTrue(LiquidLayer::hasWaterAt($this->world, $x, 1, $z));
				}
			}
		}
	}

	public function testUnderwaterBoneMealNeedsTwoWaterBlocks() : void{
		//only one water block above the sand
		$this->seabed(1);
		$item = $this->boneMeal();
		$returnedItems = [];
		self::assertSame(ItemUseResult::NONE, $item->onInteractBlock($this->player(), $this->get(0, 1, 0), $this->get(0, 0, 0), Facing::UP, $this->click(), $returnedItems));
		self::assertSame(2, $item->getCount());
		self::assertSame([0, 0, 0], $this->countPlants());

		//flowing water in front of the face
		$this->seabed(3);
		$this->set(0, 1, 0, VanillaBlocks::WATER()->setDecay(2));
		self::assertFalse(UnderwaterBoneMeal::grow($this->world, 0, 1, 0, Facing::UP, $item, null));
		self::assertSame(2, $item->getCount());

		//air in front of the face
		$this->fill(-8, 1, -8, 8, 3, 8, VanillaBlocks::AIR());
		self::assertFalse(UnderwaterBoneMeal::grow($this->world, 0, 1, 0, Facing::UP, $item, null));
	}

	public function testSideFaceGrowsWallCoralFanFirst() : void{
		mt_srand(7);
		$this->seabed(3);
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		$item = $this->boneMeal();
		self::assertTrue(UnderwaterBoneMeal::grow($this->world, 1, 1, 0, Facing::EAST, $item, null));
		$fan = $this->get(1, 1, 0);
		self::assertInstanceOf(WallCoralFan::class, $fan);
		self::assertSame(Facing::EAST, $fan->getFacing());
	}

	public function testShelfMushroomBoneMealAndDrops() : void{
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		$this->set(1, 1, 0, VanillaBlocks::SHELF_MUSHROOM()->setFacing(Facing::EAST));
		$shelf = $this->get(1, 1, 0);
		self::assertInstanceOf(ShelfMushroom::class, $shelf);
		self::assertSame(1, $shelf->getDropsForCompatibleTool(VanillaItems::AIR())[0]->getCount());

		$item = $this->boneMeal();
		self::assertTrue($shelf->onInteract($item, Facing::EAST, $this->click()));
		self::assertSame(1, $item->getCount());
		$grown = $this->get(1, 1, 0);
		self::assertInstanceOf(ShelfMushroom::class, $grown);
		self::assertSame(1, $grown->getGrowth());
		self::assertSame(2, $grown->getDropsForCompatibleTool(VanillaItems::AIR())[0]->getCount());

		//fully grown: no more bone meal
		self::assertFalse($grown->onInteract($item, Facing::EAST, $this->click()));
		self::assertSame(1, $item->getCount());
	}

	public function testPaleMossVegetationWeights() : void{
		mt_srand(1234);
		$moss = VanillaBlocks::PALE_MOSS_BLOCK();
		$this->set(0, 0, 0, $moss);
		$moss = $this->get(0, 0, 0);
		$generate = new \ReflectionMethod(PaleMoss::class, "generateVegetation");
		$carpet = $short = $tall = 0;
		$total = 3000;
		for($i = 0; $i < $total; ++$i){
			$generate->invoke($moss, 0, 1, 0, null);
			$block = $this->get(0, 1, 0);
			if($block instanceof PaleMossCarpet){
				++$carpet;
			}elseif($block instanceof DoubleTallGrass){
				++$tall;
			}elseif($block instanceof TallGrass){
				++$short;
			}
			$this->world->setBlockAt(0, 1, 0, VanillaBlocks::AIR(), false);
			$this->world->setBlockAt(0, 2, 0, VanillaBlocks::AIR(), false);
		}
		self::assertSame($total, $carpet + $short + $tall);
		//25 : 25 : 10
		self::assertEqualsWithDelta(25 / 60, $carpet / $total, 0.03);
		self::assertEqualsWithDelta(25 / 60, $short / $total, 0.03);
		self::assertEqualsWithDelta(10 / 60, $tall / $total, 0.03);
	}

	public function testNetherVinesBoneMealLength() : void{
		mt_srand(99);
		$roll = new \ReflectionMethod(NetherVines::class, "getBlocksToGrowWhenBonemealed");
		$total = 0;
		$min = 100;
		$max = 0;
		$samples = 20000;
		for($i = 0; $i < $samples; ++$i){
			$n = $roll->invoke(null);
			self::assertIsInt($n);
			$total += $n;
			$min = min($min, $n);
			$max = max($max, $n);
		}
		//1 + 0.826 + 0.826^3 + 0.826^6 + ... = 2.93
		self::assertSame(1, $min);
		self::assertGreaterThan(5, $max);
		self::assertEqualsWithDelta(2.93, $total / $samples, 0.05);
	}

	public function testNetherVinesGrowthLimitedBySpace() : void{
		mt_srand(3);
		$this->set(0, 10, 0, VanillaBlocks::STONE());
		$this->set(0, 9, 0, VanillaBlocks::WEEPING_VINES());
		$this->set(0, 7, 0, VanillaBlocks::STONE());
		$item = $this->boneMeal();
		self::assertTrue($this->get(0, 9, 0)->onInteract($item, Facing::DOWN, $this->click()));
		self::assertSame(1, $item->getCount());
		self::assertInstanceOf(NetherVines::class, $this->get(0, 8, 0));
		self::assertInstanceOf(Opaque::class, $this->get(0, 7, 0));
	}
}
