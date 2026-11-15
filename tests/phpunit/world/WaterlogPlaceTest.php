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

namespace pocketmine\world;

use pocketmine\block\Block;
use pocketmine\block\Campfire;
use pocketmine\block\Dispenser;
use pocketmine\block\Fence;
use pocketmine\block\Lava;
use pocketmine\block\Slab;
use pocketmine\block\Stair;
use pocketmine\block\tile\Container;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\aquatic\AbstractFish;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;

/**
 * Players and dispensers waterlog and drain waterloggable blocks: placing into water, water and empty buckets,
 * dispensers, campfires.
 */
class WaterlogPlaceTest extends RedstoneTestCase{

	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("getId")->willReturn(424242);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * Stone walls around (0, 1, 0) and (0, 2, 0), so that water there can't flow away.
	 */
	private function pit() : void{
		$this->floor(-2, -2, 2, 2);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				if($x !== 0 || $z !== 0){
					for($y = 1; $y <= 2; ++$y){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
					}
				}
			}
		}
	}

	private function holdsWater(int $x, int $y, int $z) : bool{
		return LiquidLayer::hasLayerWaterAt($this->world, $x, $y, $z);
	}

	/**
	 * Uses the item on the face of the block at the given position, like a player would.
	 *
	 * @param Item[] $returnedItems
	 */
	private function useBucket(Item $item, int $x, int $y, int $z, int $face, array &$returnedItems = []) : ItemUseResult{
		$clicked = $this->get($x, $y, $z);
		return $item->onInteractBlock($this->player(), $clicked->getSide($face), $clicked, $face, new Vector3(0.5, 0.5, 0.5), $returnedItems);
	}

	public function testStairPlacedIntoSourceKeepsWater() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$item = VanillaBlocks::OAK_STAIRS()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		self::assertInstanceOf(Stair::class, $this->get(0, 1, 0));
		self::assertTrue($this->holdsWater(0, 1, 0));

		//the client is sent the water in the liquid layer
		$packets = $this->world->createBlockUpdatePackets([new Vector3(0, 1, 0)]);
		$liquid = array_filter($packets, fn($p) => $p instanceof UpdateBlockPacket && $p->dataLayerId === UpdateBlockPacket::DATA_LAYER_LIQUID);
		self::assertCount(1, $liquid);

		//breaking it gives the source back
		$this->breakBlock(0, 1, 0);
		$water = $this->get(0, 1, 0);
		self::assertInstanceOf(Water::class, $water);
		self::assertTrue($water->isSource());
	}

	public function testSetBlockIntoWaterDoesNotWaterlog() : void{
		//plugins setting a block over water replace the water, like Java's Level.setBlock()
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertFalse($this->holdsWater(0, 1, 0));
	}

	public function testFlowingWaterDoesNotWaterlogFence() : void{
		$this->pit();
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER()->setDecay(2), false);
		$item = VanillaBlocks::OAK_FENCE()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		self::assertInstanceOf(Fence::class, $this->get(0, 1, 0));
		self::assertFalse($this->holdsWater(0, 1, 0));

		//falling water isn't a source either
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER()->setFalling(true), false);
		$item = VanillaBlocks::OAK_FENCE()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		self::assertFalse($this->holdsWater(0, 1, 0));
	}

	public function testBlockPlacedOutOfWaterIsDry() : void{
		$this->floor(-2, -2, 2, 2);
		$item = VanillaBlocks::OAK_FENCE()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		self::assertFalse($this->holdsWater(0, 1, 0));
	}

	public function testWaterBucketWaterlogsSlabAndEmptyBucketDrainsIt() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());

		$returned = [];
		$bucket = VanillaItems::WATER_BUCKET();
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket($bucket, 0, 1, 0, Facing::UP, $returned));
		self::assertInstanceOf(Slab::class, $this->get(0, 1, 0));
		self::assertTrue($this->holdsWater(0, 1, 0));
		self::assertSame(0, $bucket->getCount());
		self::assertCount(1, $returned);
		self::assertTrue($returned[0]->equals(VanillaItems::BUCKET()));
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 2, 0)->getTypeId(), "the water went into the slab, not above it");

		$returned = [];
		$bucket = VanillaItems::BUCKET();
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket($bucket, 0, 1, 0, Facing::UP, $returned));
		self::assertInstanceOf(Slab::class, $this->get(0, 1, 0));
		self::assertFalse($this->holdsWater(0, 1, 0));
		self::assertCount(1, $returned);
		self::assertTrue($returned[0]->equals(VanillaItems::WATER_BUCKET()));

		//a dry block gives nothing to an empty bucket
		self::assertSame(ItemUseResult::NONE, $this->useBucket(VanillaItems::BUCKET(), 0, 1, 0, Facing::UP));
	}

	public function testWaterBucketOnWaterloggedBlockPlacesOnTheFace() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		self::assertInstanceOf(Water::class, $this->get(0, 2, 0));
	}

	public function testWaterBucketOnFloorNextToFenceWaterlogsTheFace() : void{
		//the clicked block can't hold water: the block on the clicked face takes it if it can
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 0, 0, Facing::UP));
		self::assertInstanceOf(Fence::class, $this->get(0, 1, 0));
		self::assertTrue($this->holdsWater(0, 1, 0));
	}

	public function testLavaNeverWaterlogs() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::LAVA_BUCKET(), 0, 1, 0, Facing::UP));
		self::assertFalse($this->holdsWater(0, 1, 0));
		self::assertInstanceOf(Lava::class, $this->get(0, 2, 0));
	}

	public function testWaterloggedSlabLetsItsWaterFlow() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		$this->runTicks(30);
		$side = $this->get(1, 1, 0);
		self::assertInstanceOf(Water::class, $side);
		self::assertSame(1, $side->getDecay());

		//drained: the water around it dries up
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::BUCKET(), 0, 1, 0, Facing::UP));
		$this->runTicks(100);
		self::assertNotInstanceOf(Water::class, $this->get(1, 1, 0));
	}

	public function testFishBucketOnSlabReleasesFishAndWaterlogs() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::COD_BUCKET(), 0, 1, 0, Facing::UP, $returned));
		self::assertTrue($this->holdsWater(0, 1, 0));
		self::assertTrue($returned[0]->equals(VanillaItems::BUCKET()));
		$fish = array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof AbstractFish);
		self::assertCount(1, $fish);
		foreach($fish as $entity){
			self::assertSame(0, $entity->getPosition()->getFloorX());
			self::assertSame(1, $entity->getPosition()->getFloorY());
			self::assertSame(0, $entity->getPosition()->getFloorZ());
		}
	}

	private function dispenser(Item $item) : Container{
		$this->set(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::EAST));
		$this->runTicks(1);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(Container::class, $tile);
		$tile->getInventory()->setItem(0, $item);
		return $tile;
	}

	private function pulse() : void{
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE_TORCH());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(2);
	}

	public function testDispenserWaterlogsAndDrains() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_FENCE());
		$tile = $this->dispenser(VanillaItems::WATER_BUCKET());

		$this->pulse();
		self::assertInstanceOf(Fence::class, $this->get(1, 1, 0));
		self::assertTrue($this->holdsWater(1, 1, 0));
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));

		$this->pulse();
		self::assertInstanceOf(Fence::class, $this->get(1, 1, 0));
		self::assertFalse($this->holdsWater(1, 1, 0));
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::WATER_BUCKET()));
	}

	public function testDispenserFishBucketWaterlogsAndReleasesFish() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_SLAB());
		$tile = $this->dispenser(VanillaItems::COD_BUCKET());
		$this->pulse();
		self::assertTrue($this->holdsWater(1, 1, 0));
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::BUCKET()));
		self::assertCount(1, array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof AbstractFish));
	}

	public function testWaterBucketPutsOutCampfire() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::CAMPFIRE()->setLit(true));
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		$this->runTicks(1);
		$campfire = $this->get(0, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertFalse($campfire->isLit());
		self::assertTrue($this->holdsWater(0, 1, 0));

		//it can't be lit again while waterlogged
		$flintAndSteel = VanillaItems::FLINT_AND_STEEL();
		self::assertFalse($campfire->onInteract($flintAndSteel, Facing::UP, new Vector3(0.5, 0.5, 0.5)));
		$fireCharge = VanillaItems::FIRE_CHARGE();
		self::assertFalse($campfire->onInteract($fireCharge, Facing::UP, new Vector3(0.5, 0.5, 0.5)));
		self::assertSame(1, $fireCharge->getCount());
		$campfire = $this->get(0, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertFalse($campfire->isLit());

		//drained, it can
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::BUCKET(), 0, 1, 0, Facing::UP));
		$campfire = $this->get(0, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertTrue($campfire->onInteract($flintAndSteel, Facing::UP, new Vector3(0.5, 0.5, 0.5)));
		$campfire = $this->get(0, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertTrue($campfire->isLit());
	}

	public function testWaterloggedCampfireBreaksIntoSource() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::CAMPFIRE());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		$this->breakBlock(0, 1, 0);
		$water = $this->get(0, 1, 0);
		self::assertInstanceOf(Water::class, $water);
		self::assertTrue($water->isSource());
	}

	public function testCampfirePlacedIntoWaterIsOut() : void{
		$this->pit();
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$item = VanillaBlocks::CAMPFIRE()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		$campfire = $this->get(0, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertFalse($campfire->isLit());
		self::assertTrue($this->holdsWater(0, 1, 0));
	}

	public function testDispenserDoesNotLightWaterloggedCampfire() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(1, 1, 0, VanillaBlocks::CAMPFIRE()->setLit(false));
		$tile = $this->dispenser(VanillaItems::WATER_BUCKET());
		$this->pulse();
		self::assertTrue($this->holdsWater(1, 1, 0));

		$tile->getInventory()->setItem(0, VanillaItems::FLINT_AND_STEEL());
		$this->pulse();
		$campfire = $this->get(1, 1, 0);
		self::assertInstanceOf(Campfire::class, $campfire);
		self::assertFalse($campfire->isLit());
	}

	public function testWaterloggedBlocksOfTheSameStateStayUnchanged() : void{
		//waterlogging and draining keep the block itself
		$this->pit();
		$stair = VanillaBlocks::OAK_STAIRS()->setFacing(Facing::EAST)->setUpsideDown(true);
		$this->set(0, 1, 0, $stair);
		$stateId = $this->get(0, 1, 0)->getStateId();
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::WATER_BUCKET(), 0, 1, 0, Facing::UP));
		self::assertSame($stateId, $this->get(0, 1, 0)->getStateId());
		self::assertSame(ItemUseResult::SUCCESS, $this->useBucket(VanillaItems::BUCKET(), 0, 1, 0, Facing::UP));
		self::assertSame($stateId, $this->get(0, 1, 0)->getStateId());
		self::assertNotSame(Block::EMPTY_STATE_ID, $stateId);
	}
}
