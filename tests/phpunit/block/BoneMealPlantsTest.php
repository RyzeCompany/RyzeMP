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

use pocketmine\block\utils\BoneMealSpread;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\world\WorldParticleEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Random;
use pocketmine\world\particle\BoneMealUseParticle;
use pocketmine\world\particle\CopperWaxParticle;
use pocketmine\world\particle\ElectricSparkParticle;
use pocketmine\world\particle\Particle;
use pocketmine\world\particle\TurtleEggCrackParticle;
use pocketmine\world\redstone\RedstoneTestCase;
use function abs;
use function array_diff;
use function array_keys;
use function mt_srand;

/**
 * Bone meal on flowers, bushes, dry grass and grass blocks, the red shrub, and the feedback particles of bone meal,
 * copper waxing, turtle eggs and lightning rods.
 */
final class BoneMealPlantsTest extends RedstoneTestCase{
	private const R = 8;

	/** @var Particle[] */
	private array $particles = [];
	/** @phpstan-var RegisteredListener<WorldParticleEvent>|null */
	private ?RegisteredListener $listener = null;

	protected function setUp() : void{
		parent::setUp();
		//particles are recorded and cancelled (nothing is encoded or sent)
		$this->listener = new RegisteredListener(function(WorldParticleEvent $ev) : void{
			$this->particles[] = $ev->getParticle();
			$ev->cancel();
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(WorldParticleEvent::class)->register($this->listener);
	}

	protected function tearDown() : void{
		if($this->listener !== null){
			HandlerListManager::global()->getListFor(WorldParticleEvent::class)->unregister($this->listener);
		}
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
	 * A grass floor at y=0 with air above it, in the given biome.
	 */
	private function meadow(int $biome = BiomeIds::PLAINS) : void{
		for($x = -self::R; $x <= self::R; ++$x){
			for($z = -self::R; $z <= self::R; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::GRASS(), false);
				for($y = 1; $y <= 3; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::AIR(), false);
					$this->world->setBiomeId($x, $y, $z, $biome);
				}
			}
		}
	}

	/**
	 * @return int[] type ID => count of the blocks at y=1..2 around the origin (the origin column excluded)
	 * @phpstan-return array<int, int>
	 */
	private function plantsAround() : array{
		$counts = [];
		for($x = -self::R; $x <= self::R; ++$x){
			for($z = -self::R; $z <= self::R; ++$z){
				if($x === 0 && $z === 0){
					continue;
				}
				$id = $this->get($x, 1, $z)->getTypeId();
				if($id !== BlockTypeIds::AIR){
					$counts[$id] = ($counts[$id] ?? 0) + 1;
				}
			}
		}
		return $counts;
	}

	/**
	 * @phpstan-param class-string<Particle> $class
	 */
	private function particleCount(string $class) : int{
		$n = 0;
		foreach($this->particles as $particle){
			if($particle instanceof $class){
				++$n;
			}
		}
		return $n;
	}

	public function testTallFlowersDropACopy() : void{
		foreach([VanillaBlocks::SUNFLOWER(), VanillaBlocks::LILAC(), VanillaBlocks::ROSE_BUSH(), VanillaBlocks::PEONY()] as $flower){
			$this->meadow();
			$this->set(0, 1, 0, (clone $flower)->setTop(false));
			$this->set(0, 2, 0, (clone $flower)->setTop(true));
			foreach($this->world->getEntities() as $entity){
				$entity->flagForDespawn();
			}
			$item = $this->boneMeal();
			self::assertTrue($this->get(0, 2, 0)->onInteract($item, Facing::UP, $this->click()), $flower->getName());
			self::assertSame(1, $item->getCount());
			$drops = [];
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn()){
					$drops[] = $entity->getItem();
				}
			}
			self::assertCount(1, $drops, $flower->getName());
			self::assertTrue($drops[0]->equals($flower->asItem(), true, false), $flower->getName());
			self::assertSame(1, $drops[0]->getCount());
			//the flower stays
			self::assertTrue($this->get(0, 1, 0)->hasSameTypeId($flower));
		}
	}

	public function testDoubleTallGrassIsNotATallFlower() : void{
		$this->meadow();
		$this->set(0, 1, 0, VanillaBlocks::DOUBLE_TALLGRASS()->setTop(false));
		$this->set(0, 2, 0, VanillaBlocks::DOUBLE_TALLGRASS()->setTop(true));
		$item = $this->boneMeal();
		self::assertFalse($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
		self::assertSame(2, $item->getCount());
	}

	public function testSmallFlowerSpreadsOnNearbyGrass() : void{
		$total = 0;
		for($seed = 1; $seed <= 20; ++$seed){
			mt_srand($seed);
			$this->meadow();
			$this->set(0, 1, 0, VanillaBlocks::POPPY());
			$item = $this->boneMeal();
			self::assertTrue($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
			self::assertSame(1, $item->getCount());
			for($x = -self::R; $x <= self::R; ++$x){
				for($z = -self::R; $z <= self::R; ++$z){
					$block = $this->get($x, 1, $z);
					if(($x !== 0 || $z !== 0) && $block->getTypeId() !== BlockTypeIds::AIR){
						self::assertSame(BlockTypeIds::POPPY, $block->getTypeId(), "only poppies at $x $z");
						self::assertLessThanOrEqual(3, abs($x), "x $x");
						self::assertLessThanOrEqual(3, abs($z), "z $z");
						++$total;
					}
				}
			}
		}
		//BDS: 1-7 new flowers per use
		self::assertGreaterThan(20, $total);
		self::assertLessThan(160, $total);
	}

	public function testFlowersOnlySpreadOntoGrass() : void{
		mt_srand(3);
		$this->meadow();
		for($x = -self::R; $x <= self::R; ++$x){
			for($z = -self::R; $z <= self::R; ++$z){
				if($x !== 0 || $z !== 0){
					$this->world->setBlockAt($x, 0, $z, VanillaBlocks::DIRT(), false);
				}
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::DANDELION());
		for($i = 0; $i < 10; ++$i){
			$this->get(0, 1, 0)->onInteract($this->boneMeal(), Facing::UP, $this->click());
		}
		self::assertSame([], $this->plantsAround());
	}

	public function testExcludedFlowersDoNotUseBoneMeal() : void{
		foreach([VanillaBlocks::TORCHFLOWER(), VanillaBlocks::GOLDEN_DANDELION(), VanillaBlocks::WITHER_ROSE()] as $flower){
			$this->meadow();
			$this->set(0, 1, 0, $flower);
			$item = $this->boneMeal();
			self::assertFalse($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()), $flower->getName());
			self::assertSame(2, $item->getCount());
			self::assertSame([], $this->plantsAround());
		}
	}

	public function testEyeblossomSpreadsItself() : void{
		mt_srand(11);
		$this->meadow();
		$this->set(0, 1, 0, VanillaBlocks::OPEN_EYEBLOSSOM());
		for($i = 0; $i < 3; ++$i){
			$this->get(0, 1, 0)->onInteract($this->boneMeal(), Facing::UP, $this->click());
		}
		$plants = $this->plantsAround();
		self::assertSame([BlockTypeIds::OPEN_EYEBLOSSOM], array_keys($plants));
	}

	public function testBushesPlaceACopyOnANeighbour() : void{
		foreach([VanillaBlocks::BUSH(), VanillaBlocks::FIREFLY_BUSH(), VanillaBlocks::RED_SHRUB()] as $bush){
			mt_srand(7);
			$this->meadow();
			$this->set(0, 1, 0, $bush);
			$item = $this->boneMeal();
			self::assertTrue($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()), $bush->getName());
			self::assertSame(1, $item->getCount());
			self::assertSame([$bush->getTypeId() => 1], $this->plantsAround(), $bush->getName());
			$found = false;
			foreach(Facing::HORIZONTAL as $face){
				$side = Vector3::zero()->up()->getSide($face);
				$found = $found || $this->get($side->getFloorX(), 1, $side->getFloorZ())->hasSameTypeId($bush);
			}
			self::assertTrue($found, "copy next to the " . $bush->getName());
		}
	}

	public function testBushWithoutFreeNeighbourKeepsTheBoneMeal() : void{
		$this->meadow();
		$this->set(0, 1, 0, VanillaBlocks::BUSH());
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(-1, 1, 0, VanillaBlocks::STONE());
		$this->set(0, 1, 1, VanillaBlocks::STONE());
		//no grass under the last free spot
		$this->world->setBlockAt(0, 0, -1, VanillaBlocks::STONE(), false);
		$item = $this->boneMeal();
		self::assertFalse($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
		self::assertSame(2, $item->getCount());
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, -1)->getTypeId());
	}

	public function testTallDryGrassPlacesShortDryGrass() : void{
		mt_srand(2);
		$this->meadow();
		$this->set(0, 1, 0, VanillaBlocks::TALL_DRY_GRASS());
		$item = $this->boneMeal();
		self::assertTrue($this->get(0, 1, 0)->onInteract($item, Facing::UP, $this->click()));
		self::assertSame(1, $item->getCount());
		self::assertSame([BlockTypeIds::SHORT_DRY_GRASS => 1], $this->plantsAround());
		self::assertSame(BlockTypeIds::TALL_DRY_GRASS, $this->get(0, 1, 0)->getTypeId());

		//short dry grass still grows into tall dry grass
		$this->set(5, 1, 5, VanillaBlocks::SHORT_DRY_GRASS());
		self::assertTrue($this->get(5, 1, 5)->onInteract($this->boneMeal(), Facing::UP, $this->click()));
		self::assertSame(BlockTypeIds::TALL_DRY_GRASS, $this->get(5, 1, 5)->getTypeId());
	}

	public function testRedShrubIsAReplaceableBushThatDropsItself() : void{
		$shrub = VanillaBlocks::RED_SHRUB();
		self::assertTrue($shrub->canBeReplaced());
		foreach([VanillaItems::AIR(), VanillaItems::DIAMOND_AXE(), VanillaItems::SHEARS()] as $tool){
			$drops = $shrub->getDrops($tool);
			self::assertCount(1, $drops, $tool->getName());
			self::assertTrue($drops[0]->equals($shrub->asItem(), true, false));
		}
		//bush soil, not the dead bush's sand
		$this->meadow();
		self::assertTrue($shrub->canBePlacedAt($this->get(0, 1, 0), $this->click(), Facing::UP, false));
		$this->world->setBlockAt(2, 0, 2, VanillaBlocks::SAND(), false);
		self::assertFalse($shrub->canBePlacedAt($this->get(2, 1, 2), $this->click(), Facing::UP, false));
	}

	/**
	 * @return int[] type ID => count of the plants grown by bone meal on the grass block at the origin, over seeds
	 * @phpstan-return array<int, int>
	 */
	private function grassBoneMeal(int $biome, int $seeds) : array{
		$counts = [];
		for($seed = 1; $seed <= $seeds; ++$seed){
			$this->meadow($biome);
			BoneMealSpread::growGrass($this->world, 0, 0, 0, new Random($seed));
			for($x = -self::R; $x <= self::R; ++$x){
				for($z = -self::R; $z <= self::R; ++$z){
					$id = $this->get($x, 1, $z)->getTypeId();
					if($id === BlockTypeIds::AIR){
						continue;
					}
					//7x7 area around the grass block
					self::assertLessThanOrEqual(3, abs($x), "x $x");
					self::assertLessThanOrEqual(3, abs($z), "z $z");
					$counts[$id] = ($counts[$id] ?? 0) + 1;
				}
			}
		}
		return $counts;
	}

	public function testGrassBoneMealPlantsGrassAndBiomeFlowers() : void{
		$counts = $this->grassBoneMeal(BiomeIds::FLOWER_FOREST, 40);
		$grass = ($counts[BlockTypeIds::TALL_GRASS] ?? 0) + ($counts[BlockTypeIds::DOUBLE_TALLGRASS] ?? 0);
		$flowers = 0;
		$kinds = 0;
		foreach($counts as $id => $count){
			if($id !== BlockTypeIds::TALL_GRASS && $id !== BlockTypeIds::DOUBLE_TALLGRASS){
				self::assertInstanceOf(Flower::class, $this->typeToBlock($id));
				$flowers += $count;
				++$kinds;
			}
		}
		//BDS: about 10 plants per use, mostly grass
		self::assertGreaterThan(40 * 5, $grass + $flowers);
		self::assertLessThan(40 * 20, $grass + $flowers);
		self::assertGreaterThan($flowers, $grass);
		self::assertGreaterThan(0, $flowers);
		//the flower forest has 11 flowers in patches: more than the default dandelion and poppy
		self::assertGreaterThan(0, $counts[BlockTypeIds::DOUBLE_TALLGRASS] ?? 0, "some short grass grows tall");
		self::assertGreaterThanOrEqual(1, $kinds);
		$other = 0;
		foreach($counts as $id => $count){
			if($id !== BlockTypeIds::TALL_GRASS && $id !== BlockTypeIds::DOUBLE_TALLGRASS && $id !== BlockTypeIds::DANDELION && $id !== BlockTypeIds::POPPY){
				$other += $count;
			}
		}
		self::assertGreaterThan(0, $other, "flower forest flowers");
	}

	public function testGrassBoneMealSwampBlueOrchidsAndBadlandsNoFlowers() : void{
		$swamp = $this->grassBoneMeal(BiomeIds::SWAMPLAND, 20);
		self::assertGreaterThan(0, $swamp[BlockTypeIds::BLUE_ORCHID] ?? 0);
		self::assertSame([], array_diff(array_keys($swamp), [BlockTypeIds::TALL_GRASS, BlockTypeIds::DOUBLE_TALLGRASS, BlockTypeIds::BLUE_ORCHID]));

		$badlands = $this->grassBoneMeal(BiomeIds::MESA, 20);
		self::assertSame([], array_diff(array_keys($badlands), [BlockTypeIds::TALL_GRASS, BlockTypeIds::DOUBLE_TALLGRASS]));

		$cherry = $this->grassBoneMeal(BiomeIds::CHERRY_GROVE, 20);
		self::assertGreaterThan(0, $cherry[BlockTypeIds::PINK_PETALS] ?? 0);

		$taiga = $this->grassBoneMeal(BiomeIds::TAIGA, 10);
		self::assertGreaterThan($taiga[BlockTypeIds::TALL_GRASS] ?? 0, $taiga[BlockTypeIds::FERN] ?? 0, "taigas grow mostly ferns");
	}

	private function typeToBlock(int $typeId) : Block{
		foreach(VanillaBlocks::getAll() as $block){
			if($block->getTypeId() === $typeId){
				return $block;
			}
		}
		self::fail("unknown type $typeId");
	}

	public function testOneBoneMealParticlePerUse() : void{
		mt_srand(1);
		$this->meadow();
		$this->set(0, 1, 0, VanillaBlocks::POPPY());
		$item = $this->boneMeal(3);
		self::assertTrue($this->world->useItemOn(new Vector3(0, 1, 0), $item, Facing::UP, $this->click()));
		self::assertSame(2, $item->getCount());
		self::assertSame(1, $this->particleCount(BoneMealUseParticle::class));

		$this->particles = [];
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 3), $item, Facing::UP, $this->click()), "grass block");
		self::assertSame(1, $item->getCount());
		self::assertSame(1, $this->particleCount(BoneMealUseParticle::class));

		//not used: no particles
		$this->particles = [];
		$this->set(5, 1, 5, VanillaBlocks::TORCHFLOWER());
		$this->world->useItemOn(new Vector3(5, 1, 5), $item, Facing::UP, $this->click());
		self::assertSame(1, $item->getCount());
		self::assertSame(0, $this->particleCount(BoneMealUseParticle::class));
	}

	public function testCopperWaxingAndScrapingParticles() : void{
		$this->set(0, 1, 0, VanillaBlocks::COPPER());
		self::assertTrue($this->get(0, 1, 0)->onInteract(VanillaItems::HONEYCOMB(), Facing::UP, $this->click()));
		self::assertSame(1, $this->particleCount(CopperWaxParticle::class));
		self::assertTrue($this->get(0, 1, 0)->onInteract(VanillaItems::DIAMOND_AXE(), Facing::UP, $this->click()), "wax off");
		$copper = $this->get(0, 1, 0);
		self::assertInstanceOf(Copper::class, $copper);
		$this->set(0, 1, 0, $copper->setOxidation(CopperOxidation::EXPOSED));
		self::assertTrue($this->get(0, 1, 0)->onInteract(VanillaItems::DIAMOND_AXE(), Facing::UP, $this->click()), "scrape");
		self::assertSame(3, $this->particleCount(CopperWaxParticle::class));
	}

	public function testTurtleEggCrackParticle() : void{
		$this->world->setBlockAt(0, 0, 0, VanillaBlocks::SAND(), false);
		$this->set(0, 1, 0, VanillaBlocks::TURTLE_EGG());
		$this->world->setTime(21500);
		$egg = $this->get(0, 1, 0);
		self::assertInstanceOf(TurtleEgg::class, $egg);
		$egg->onRandomTick();
		$cracked = $this->get(0, 1, 0);
		self::assertInstanceOf(TurtleEgg::class, $cracked);
		self::assertSame(1, $cracked->getCracks());
		self::assertSame(1, $this->particleCount(TurtleEggCrackParticle::class));
	}

	public function testLightningRodSparks() : void{
		$this->set(0, 1, 0, VanillaBlocks::LIGHTNING_ROD());
		$rod = $this->get(0, 1, 0);
		self::assertInstanceOf(LightningRod::class, $rod);
		$rod->onLightningStrike();
		self::assertSame(1, $this->particleCount(ElectricSparkParticle::class));
		self::assertCount(1, $this->particles);
	}
}
