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

use pocketmine\block\tile\Container;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\entity\aquatic\AbstractFish;
use pocketmine\entity\Entity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\GameRules;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;
use function abs;
use function array_filter;
use function array_values;
use function max;
use function mt_srand;
use function str_contains;

/**
 * Lava and water in ultra-warm dimensions (tests named "...Nether..." run in a Nether world): lava flow speed and reach,
 * lava setting fire to flammable blocks, water evaporating, ice and sponges.
 */
class NetherLiquidsTest extends RedstoneTestCase{

	protected function getDimension() : Dimension{
		return str_contains($this->name(), "Nether") ? Dimension::NETHER : Dimension::OVERWORLD;
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
	 * @param Item[] $returnedItems
	 */
	private function useBucket(Item $item, int $x, int $y, int $z, int $face, array &$returnedItems = []) : ItemUseResult{
		$clicked = $this->get($x, $y, $z);
		return $item->onInteractBlock($this->player(), $clicked->getSide($face), $clicked, $face, new Vector3(0.5, 0.5, 0.5), $returnedItems);
	}

	private function isLava(int $x, int $y, int $z) : bool{
		return $this->get($x, $y, $z) instanceof Lava;
	}

	/**
	 * A 1-wide stone channel along +X from the origin, with a lava source at its start.
	 */
	private function lavaChannel() : void{
		$this->floor(-1, -1, 12, 1);
		for($x = -1; $x <= 12; ++$x){
			$this->world->setBlockAt($x, 1, -1, VanillaBlocks::STONE(), false);
			$this->world->setBlockAt($x, 1, 1, VanillaBlocks::STONE(), false);
		}
		$this->world->setBlockAt(-1, 1, 0, VanillaBlocks::STONE(), false);
		$this->set(0, 1, 0, VanillaBlocks::LAVA());
	}

	public function testOverworldLavaFlowsThreeBlocksEvery30Ticks() : void{
		$this->lavaChannel();
		$this->runTicks(20);
		self::assertFalse($this->isLava(1, 1, 0));
		$this->runTicks(15);
		self::assertTrue($this->isLava(1, 1, 0));
		self::assertFalse($this->isLava(2, 1, 0));
		$this->runTicks(300);
		self::assertTrue($this->isLava(3, 1, 0));
		self::assertFalse($this->isLava(4, 1, 0));
	}

	public function testNetherLavaFlowsSevenBlocksEvery10Ticks() : void{
		$this->lavaChannel();
		$this->runTicks(13);
		self::assertTrue($this->isLava(1, 1, 0));
		self::assertFalse($this->isLava(2, 1, 0));
		$this->runTicks(200);
		self::assertTrue($this->isLava(7, 1, 0));
		self::assertFalse($this->isLava(8, 1, 0));
	}

	private function hasFireAround() : bool{
		for($x = -2; $x <= 2; ++$x){
			for($y = 1; $y <= 4; ++$y){
				for($z = -2; $z <= 2; ++$z){
					if($this->get($x, $y, $z) instanceof Fire){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Random ticks of a lava source (with oak planks next to it if $planks); returns whether any fire was started.
	 */
	private function lavaStartsFire(int $randomTicks, bool $planks = true) : bool{
		$this->floor(-3, -3, 3, 3);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::LAVA(), false);
		if($planks){
			$this->world->setBlockAt(1, 1, 0, VanillaBlocks::OAK_PLANKS(), false);
		}
		mt_srand(1234);
		for($i = 0; $i < $randomTicks; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		return $this->hasFireAround();
	}

	public function testLavaSetsFireToFlammableBlocks() : void{
		self::assertTrue(VanillaBlocks::LAVA()->ticksRandomly());
		self::assertFalse(VanillaBlocks::WATER()->ticksRandomly());
		self::assertTrue($this->lavaStartsFire(200));
	}

	public function testLavaStartsNoFireWithoutFireTick() : void{
		$this->world->setGameRule(GameRules::DO_FIRE_TICK, false);
		self::assertFalse($this->lavaStartsFire(500));
	}

	public function testLavaAloneStartsNoFire() : void{
		self::assertFalse($this->lavaStartsFire(300, false));
	}

	public function testLavaStartsSoulFireOnSoulSoil() : void{
		//lava in a ring of soul soil under a ceiling of planks: air between them is next to something flammable
		$this->floor(-3, -3, 3, 3);
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				$this->world->setBlockAt($x, 1, $z, $x === 0 && $z === 0 ? VanillaBlocks::LAVA() : VanillaBlocks::SOUL_SOIL(), false);
				$this->world->setBlockAt($x, 3, $z, VanillaBlocks::OAK_PLANKS(), false);
			}
		}
		mt_srand(1234);
		$soulFire = false;
		for($i = 0; $i < 300 && !$soulFire; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
			for($x = -1; $x <= 1 && !$soulFire; ++$x){
				for($z = -1; $z <= 1 && !$soulFire; ++$z){
					$soulFire = $this->get($x, 2, $z) instanceof SoulFire;
				}
			}
		}
		self::assertTrue($soulFire);
	}

	public function testLavaFireCanBeCancelled() : void{
		$events = 0;
		$listener = new RegisteredListener(function(BlockSpreadEvent $ev) use (&$events) : void{
			if($ev->getSource() instanceof Lava){
				++$events;
				$ev->cancel();
			}
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(BlockSpreadEvent::class)->register($listener);
		try{
			self::assertFalse($this->lavaStartsFire(300));
		}finally{
			HandlerListManager::global()->getListFor(BlockSpreadEvent::class)->unregister($listener);
		}
		self::assertGreaterThan(0, $events);
	}

	public function testLavaAtTheEdgeOfLoadedChunksLeavesUnloadedChunksAlone() : void{
		//chunks -2..2 are loaded: x=47 is the last column of chunk 2, the columns after it are not loaded
		$this->floor(44, -2, 47, 2);
		$this->world->setBlockAt(47, 1, 0, VanillaBlocks::LAVA(), false);
		$this->world->setBlockAt(46, 1, 0, VanillaBlocks::OAK_PLANKS(), false);
		mt_srand(1234);
		for($i = 0; $i < 300; ++$i){
			$this->get(47, 1, 0)->onRandomTick();
		}
		self::assertNull($this->world->getChunk(3, 0));
	}

	/**
	 * Lava random ticks made by World::tickChunk() (which skips lava with nothing flammable in reach): a lava lake
	 * starts no fire until planks are put in it, then does.
	 */
	public function testChunkRandomTicksOfLavaStartFires() : void{
		$this->world->setGameRule(GameRules::RANDOM_TICK_SPEED, 200);
		$this->floor(0, 0, 15, 15);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$this->world->setBlockAt($x, 1, $z, VanillaBlocks::LAVA(), false);
			}
		}
		$tickChunk = \Closure::bind(function() : void{
			$this->tickChunk(0, 0);
		}, $this->world, World::class);
		$fireAround = function() : bool{
			for($x = 6; $x <= 10; ++$x){
				for($z = 6; $z <= 10; ++$z){
					if($this->get($x, 2, $z) instanceof Fire){
						return true;
					}
				}
			}
			return false;
		};
		mt_srand(1234);
		for($i = 0; $i < 20; ++$i){
			$tickChunk();
			$this->runTicks(1);
		}
		self::assertFalse($fireAround());

		$this->world->setBlockAt(8, 1, 8, VanillaBlocks::OAK_PLANKS(), false);
		$fire = false;
		for($i = 0; $i < 200 && !$fire; ++$i){
			$tickChunk();
			$this->runTicks(1);
			$fire = $fireAround();
		}
		self::assertTrue($fire);
	}

	public function testWaterBucketEvaporatesInTheNether() : void{
		$this->floor(-2, -2, 2, 2);
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 0, 0, Facing::UP, $returned));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
		self::assertCount(1, $returned);
		self::assertTrue($returned[0]->equals(VanillaItems::BUCKET()));

		//a waterloggable block takes no water either
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		self::assertFalse(LiquidLayer::hasLayerWaterAt($this->world, 0, 1, 0));

		//lava is placed as usual
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::LAVA_BUCKET(), 1, 0, 0, Facing::UP));
		self::assertTrue($this->isLava(1, 1, 0));
	}

	public function testFishBucketInTheNetherReleasesFishWithoutWater() : void{
		$this->floor(-2, -2, 2, 2);
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::COD_BUCKET(), 0, 0, 0, Facing::UP, $returned));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
		self::assertTrue($returned[0]->equals(VanillaItems::BUCKET()));
		self::assertCount(1, array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof AbstractFish));
	}

	/**
	 * Dispenses the bucket (a water bucket by default) from a dispenser facing east, with $front (if given) in front
	 * of it.
	 */
	private function dispenseWaterBucket(?Item $bucket = null, ?Block $front = null) : Container{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::EAST));
		if($front !== null){
			$this->world->setBlockAt(1, 1, 0, $front, false);
		}
		$this->runTicks(1);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(Container::class, $tile);
		$tile->getInventory()->setItem(0, $bucket ?? VanillaItems::WATER_BUCKET());

		$this->set(0, 1, 1, VanillaBlocks::REDSTONE_TORCH());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);
		return $tile;
	}

	public function testDispensedWaterEvaporatesInTheNether() : void{
		$tile = $this->dispenseWaterBucket();
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId());
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));
	}

	public function testDispensedWaterEvaporatesOverLavaSourceInTheNether() : void{
		$tile = $this->dispenseWaterBucket(null, VanillaBlocks::LAVA());
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()), "the water fizzes, the bucket stays in the dispenser");
		self::assertCount(0, array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity));
		self::assertTrue($this->isLava(1, 1, 0));
	}

	public function testDispensedFishBucketInTheNetherReleasesFishWithoutWater() : void{
		$tile = $this->dispenseWaterBucket(VanillaItems::TROPICAL_FISH_BUCKET());
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId());
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));
		self::assertCount(1, array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof AbstractFish));
	}

	public function testDispensedWaterIsPlacedInTheOverworld() : void{
		$tile = $this->dispenseWaterBucket();
		self::assertInstanceOf(Water::class, $this->get(1, 1, 0));
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));
	}

	public function testIceBrokenInTheNetherLeavesNothing() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, VanillaBlocks::ICE());
		$this->breakBlock(0, 1, 0);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
	}

	public function testIceMeltsAwayInTheNether() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, VanillaBlocks::ICE());
		self::assertSame(BlockTypeIds::AIR, UltraWarmHelper::getMeltedIceState($this->get(0, 1, 0))->getTypeId());
		$this->set(1, 1, 0, VanillaBlocks::FROSTED_ICE());
		self::assertSame(BlockTypeIds::AIR, UltraWarmHelper::getMeltedIceState($this->get(1, 1, 0))->getTypeId());
	}

	public function testIceMeltsIntoWater() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, VanillaBlocks::ICE());
		self::assertInstanceOf(Water::class, UltraWarmHelper::getMeltedIceState($this->get(0, 1, 0)));
	}

	public function testBrokenIceLeavesWaterOnlyAboveSolidBlockOrLiquid() : void{
		$this->floor(-1, -1, 3, 1);
		//on stone: water
		$this->set(0, 1, 0, VanillaBlocks::ICE());
		$this->breakBlock(0, 1, 0);
		self::assertInstanceOf(Water::class, $this->get(0, 1, 0));

		//over air: nothing
		$this->set(2, 5, 0, VanillaBlocks::ICE());
		$this->breakBlock(2, 5, 0);
		self::assertSame(BlockTypeIds::AIR, $this->get(2, 5, 0)->getTypeId());

		//over water: water
		$this->world->setBlockAt(2, 1, 0, VanillaBlocks::WATER(), false);
		$this->set(2, 2, 0, VanillaBlocks::ICE());
		$this->breakBlock(2, 2, 0);
		self::assertInstanceOf(Water::class, $this->get(2, 2, 0));
	}

	/**
	 * A pool of water sources (1 deep) inside stone walls, from -2 to 2 on X and Z, on a stone floor.
	 */
	private function pool() : void{
		$this->floor(-3, -3, 3, 3);
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$inside = $x >= -2 && $x <= 2 && $z >= -2 && $z <= 2;
				$this->world->setBlockAt($x, 1, $z, $inside ? VanillaBlocks::WATER() : VanillaBlocks::STONE(), false);
			}
		}
	}

	/**
	 * Places a sponge on top of the floor at the origin, like a player would.
	 */
	private function placeSponge(bool $wet) : void{
		$item = VanillaBlocks::SPONGE()->setWet($wet)->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
	}

	public function testSpongeAbsorbsPool() : void{
		$this->pool();
		$this->world->setBlockAt(1, 1, 1, VanillaBlocks::SEAGRASS(), false);
		$this->world->setBlockAt(-1, 1, -1, VanillaBlocks::OAK_SLAB(), false);
		WaterloggedHelper::waterlog($this->get(-1, 1, -1));
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, -1, 1, -1));

		$this->placeSponge(false);
		$sponge = $this->get(0, 1, 0);
		self::assertInstanceOf(Sponge::class, $sponge);
		self::assertTrue($sponge->isWet());
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				self::assertFalse(LiquidLayer::isWater($this->get($x, 1, $z)), "no water left at $x 1 $z");
			}
		}
		self::assertInstanceOf(Slab::class, $this->get(-1, 1, -1), "the waterlogged slab is drained, not removed");
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 1)->getTypeId(), "the seagrass is broken");
		$this->runTicks(40);
		self::assertSame(BlockTypeIds::AIR, $this->get(2, 1, 2)->getTypeId(), "no water flows back");
	}

	public function testSpongeDrainsPicklesAndBubbleColumnsAndBreaksKelp() : void{
		$this->pool();
		$this->world->setBlockAt(1, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(true), false);
		$this->world->setBlockAt(-1, 1, 0, VanillaBlocks::KELP(), false);
		$this->world->setBlockAt(0, 0, 1, VanillaBlocks::SOUL_SAND(), false);
		$this->world->setBlockAt(0, 1, 1, VanillaBlocks::BUBBLE_COLUMN(), false);

		$this->placeSponge(false);
		$pickle = $this->get(1, 1, 0);
		self::assertInstanceOf(SeaPickle::class, $pickle, "the sea pickle stays");
		self::assertFalse($pickle->isUnderwater(), "the sea pickle is drained");
		self::assertSame(BlockTypeIds::AIR, $this->get(-1, 1, 0)->getTypeId(), "the kelp is broken");
		$drops = array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity);
		self::assertCount(1, $drops, "the kelp drops itself");
		$drop = array_values($drops)[0];
		self::assertInstanceOf(ItemEntity::class, $drop);
		self::assertTrue($drop->getItem()->equals(VanillaBlocks::KELP()->asItem(), false, false));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 1)->getTypeId(), "the bubble column becomes air");
	}

	public function testSpongeAbsorbsAtMost64WatersWithin6Blocks() : void{
		//a layer of water sources, 17x17 around the sponge
		$this->floor(-8, -8, 8, 8);
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				$this->world->setBlockAt($x, 1, $z, VanillaBlocks::WATER(), false);
			}
		}
		$this->placeSponge(false);
		$water = 0;
		$farthest = 0;
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				if(LiquidLayer::isWater($this->get($x, 1, $z))){
					++$water;
				}elseif($x !== 0 || $z !== 0){
					$farthest = max($farthest, abs($x) + abs($z));
				}
			}
		}
		self::assertSame(17 * 17 - 1 - 64, $water, "64 waters are absorbed");
		self::assertLessThanOrEqual(6, $farthest);

		//a straight channel: only the first 6 blocks
		$this->floor(-1, 10, 12, 12);
		for($x = 1; $x <= 10; ++$x){
			$this->world->setBlockAt($x, 1, 11, VanillaBlocks::WATER(), false);
		}
		$item = VanillaBlocks::SPONGE()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 11), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		for($x = 1; $x <= 10; ++$x){
			self::assertSame($x > 6, LiquidLayer::isWater($this->get($x, 1, 11)), "water at $x 1 11");
		}
	}

	public function testSpongeAbsorbsWaterFlowingNextToIt() : void{
		$this->floor(-3, -3, 3, 3);
		$this->set(0, 1, 0, VanillaBlocks::SPONGE());
		$this->set(1, 1, 0, VanillaBlocks::WATER());
		$this->runTicks(2);
		$sponge = $this->get(0, 1, 0);
		self::assertInstanceOf(Sponge::class, $sponge);
		self::assertTrue($sponge->isWet());
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId());
	}

	public function testSpongeWithoutWaterStaysDry() : void{
		$this->floor(-1, -1, 1, 1);
		$this->placeSponge(false);
		$sponge = $this->get(0, 1, 0);
		self::assertInstanceOf(Sponge::class, $sponge);
		self::assertFalse($sponge->isWet());
	}

	public function testWetSpongeDriesInTheNether() : void{
		$this->floor(-1, -1, 1, 1);
		$this->placeSponge(true);
		$sponge = $this->get(0, 1, 0);
		self::assertInstanceOf(Sponge::class, $sponge);
		self::assertFalse($sponge->isWet());
	}

	public function testWetSpongeStaysWetInTheOverworld() : void{
		$this->floor(-1, -1, 1, 1);
		$this->placeSponge(true);
		$sponge = $this->get(0, 1, 0);
		self::assertInstanceOf(Sponge::class, $sponge);
		self::assertTrue($sponge->isWet());
	}
}
