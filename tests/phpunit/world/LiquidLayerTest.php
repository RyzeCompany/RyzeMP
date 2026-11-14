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

use pocketmine\block\BaseCoral;
use pocketmine\block\Block;
use pocketmine\block\Concrete;
use pocketmine\block\Farmland;
use pocketmine\block\FloorCoralFan;
use pocketmine\block\Opaque;
use pocketmine\block\Stair;
use pocketmine\block\Sugarcane;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SlabType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\format\SubChunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_keys;
use function floor;

/**
 * Water held in the liquid layer (block layer 1) by waterloggable blocks, like the vanilla generator and worlds made by
 * vanilla store it.
 */
class LiquidLayerTest extends RedstoneTestCase{

	protected function setUp() : void{
		parent::setUp();
		PathBlockTypes::clearCache();
	}

	private function subChunk(int $y) : SubChunk{
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		return $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE);
	}

	private function liquidLayer(int $x, int $y, int $z) : int{
		return $this->subChunk($y)->getLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf);
	}

	/**
	 * Puts water into the liquid layer, like the vanilla generator does for waterlogged structure blocks.
	 */
	private function waterlog(int $x, int $y, int $z) : void{
		self::assertTrue(LiquidLayer::canContainWater($this->world->getBlockAt($x, $y, $z)->getStateId()));
		$this->subChunk($y)->setLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf, VanillaBlocks::WATER()->getStillForm()->getStateId());
	}

	public function testWaterloggableStates() : void{
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::OAK_STAIRS()->getStateId()));
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::OAK_SLAB()->getStateId()));
		self::assertFalse(LiquidLayer::canContainWater(VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::DOUBLE)->getStateId()), "double slab");
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::OAK_FENCE()->getStateId()));
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::CORAL_FAN()->getStateId()));
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::GLOW_LICHEN()->getStateId()));
		self::assertTrue(LiquidLayer::canContainWater(VanillaBlocks::OAK_DOOR()->getStateId()), "Bedrock only");
		self::assertFalse(LiquidLayer::canContainWater(VanillaBlocks::STONE()->getStateId()));
		self::assertFalse(LiquidLayer::canContainWater(VanillaBlocks::AIR()->getStateId()));
		self::assertFalse(LiquidLayer::canContainWater(VanillaBlocks::WATER()->getStateId()));
	}

	public function testHelperSeesLayerWater() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_STAIRS());
		self::assertFalse(LiquidLayer::hasWaterAt($this->world, 0, 1, 0));
		$this->waterlog(0, 1, 0);
		self::assertTrue(LiquidLayer::hasWaterAt($this->world, 0, 1, 0));
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 0, 1, 0));
		self::assertTrue(LiquidLayer::holdsWater($this->get(0, 1, 0)));
		self::assertTrue(LiquidLayer::isWater($this->get(0, 1, 0)));

		//water in the liquid layer of a block which can't hold water doesn't count
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->subChunk(1)->setLiquidLayerStateId(1, 1, 0, VanillaBlocks::WATER()->getStillForm()->getStateId());
		self::assertFalse(LiquidLayer::hasWaterAt($this->world, 1, 1, 0));

		//water blocks and Waterlogged blocks
		$this->set(2, 1, 0, VanillaBlocks::WATER()->setDecay(3));
		self::assertTrue(LiquidLayer::hasWaterAt($this->world, 2, 1, 0));
		self::assertFalse(LiquidLayer::hasLayerWaterAt($this->world, 2, 1, 0));
		$this->set(3, 1, 0, VanillaBlocks::SEAGRASS());
		self::assertTrue(LiquidLayer::hasWaterAt($this->world, 3, 1, 0));
		self::assertFalse(LiquidLayer::hasWaterAt($this->world, 4, 1, 0), "air");
	}

	public function testUpdatesKeepWaterWhileTheBlockCanHoldIt() : void{
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		$this->set(0, 1, 0, VanillaBlocks::OAK_STAIRS()->setFacing(Facing::NORTH));
		$this->waterlog(0, 1, 0);

		$this->set(0, 1, 0, VanillaBlocks::OAK_STAIRS()->setFacing(Facing::EAST));
		self::assertSame($water, $this->liquidLayer(0, 1, 0), "state change");
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame($water, $this->liquidLayer(0, 1, 0), "another waterloggable block");
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::DOUBLE));
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0), "double slab");

		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->waterlog(0, 1, 0);
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0), "replaced by a full block");
		self::assertInstanceOf(Opaque::class, $this->get(0, 1, 0));
	}

	public function testBreakingWaterloggedBlockLeavesSource() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::STONE_BRICK_STAIRS());
		$this->waterlog(0, 1, 0);

		$this->breakBlock(0, 1, 0);
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(Water::class, $block);
		self::assertTrue($block->isSource());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));

		//the client is told about both layers
		$packets = $this->world->createBlockUpdatePackets([new Vector3(0, 1, 0)]);
		$liquid = array_filter($packets, fn($p) => $p instanceof UpdateBlockPacket && $p->dataLayerId === UpdateBlockPacket::DATA_LAYER_LIQUID);
		self::assertCount(1, $liquid);

		//setting it to air (plugins, pistons...) does what it is asked: air, and the water goes away
		$this->set(1, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->waterlog(1, 1, 0);
		$this->set(1, 1, 0, VanillaBlocks::AIR());
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $this->get(1, 1, 0)->getStateId());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(1, 1, 0));

		//a dry block leaves air
		$this->set(2, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->breakBlock(2, 1, 0);
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $this->get(2, 1, 0)->getStateId());
	}

	public function testLayerWaterSpreadsIntoEmptyNeighbour() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::STONE_BRICK_STAIRS());
		$this->waterlog(0, 1, 0);
		//poke the stair: the change next to it lets its water flow
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(1, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(60);

		$side = $this->get(1, 1, 0);
		self::assertInstanceOf(Water::class, $side);
		self::assertFalse($side->isSource());
		self::assertSame(1, $side->getDecay());
		self::assertInstanceOf(Stair::class, $this->get(0, 1, 0));
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 0, 1, 0));

		//water between two waterlogged blocks becomes a source
		$this->set(2, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->waterlog(2, 1, 0);
		$this->set(2, 1, 0, VanillaBlocks::OAK_FENCE()); //update it
		$this->runTicks(60);
		$between = $this->get(1, 1, 0);
		self::assertInstanceOf(Water::class, $between);
		self::assertTrue($between->isSource());
	}

	public function testWaterDoesNotFlowIntoWaterloggedFlowables() : void{
		$this->floor(-8, -8, 8, 8);
		//glow lichen on the floor, holding water: flowing water would otherwise wash it away
		$this->set(1, 1, 0, VanillaBlocks::GLOW_LICHEN()->setFace(Facing::DOWN, true));
		$this->waterlog(1, 1, 0);
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->runTicks(60);
		self::assertSame(VanillaBlocks::GLOW_LICHEN()->getTypeId(), $this->get(1, 1, 0)->getTypeId());
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 1, 1, 0));
	}

	public function testFarmlandIsHydratedByHeldWater() : void{
		$this->floor(-8, -8, 8, 8);
		foreach([
			"waterlogged slab" => function() : void{
				$this->set(3, 1, 0, VanillaBlocks::OAK_SLAB());
				$this->waterlog(3, 1, 0);
			},
			"seagrass" => fn() => $this->set(3, 1, 0, VanillaBlocks::SEAGRASS()),
		] as $name => $placeWater){
			$this->set(3, 1, 0, VanillaBlocks::STONE());
			$this->set(0, 1, 0, VanillaBlocks::FARMLAND()->setWetness(0));
			$placeWater();
			$farmland = $this->get(0, 1, 0);
			self::assertInstanceOf(Farmland::class, $farmland);
			$farmland->onRandomTick();
			$farmland = $this->get(0, 1, 0);
			self::assertInstanceOf(Farmland::class, $farmland, $name);
			self::assertSame(Farmland::MAX_WETNESS, $farmland->getWetness(), $name);
		}
	}

	public function testSugarcaneStaysNextToHeldWater() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::SAND());
		$this->set(1, 1, 0, VanillaBlocks::OAK_SLAB());
		$this->waterlog(1, 1, 0);
		$this->set(0, 2, 0, VanillaBlocks::SUGARCANE());
		$this->get(0, 2, 0)->onNearbyBlockChange();
		self::assertInstanceOf(Sugarcane::class, $this->get(0, 2, 0));

		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->get(0, 2, 0)->onNearbyBlockChange();
		self::assertNotInstanceOf(Sugarcane::class, $this->get(0, 2, 0));
	}

	public function testConcretePowderHardensNextToSeagrass() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::CONCRETE_POWDER());
		$this->set(1, 1, 0, VanillaBlocks::SEAGRASS());
		$this->runTicks(1);
		self::assertInstanceOf(Concrete::class, $this->get(0, 1, 0));

		$this->set(0, 2, 0, VanillaBlocks::CONCRETE_POWDER());
		$this->set(0, 3, 0, VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::TOP));
		$this->waterlog(0, 3, 0);
		$this->get(0, 2, 0)->onNearbyBlockChange();
		self::assertInstanceOf(Concrete::class, $this->get(0, 2, 0), "open side of a waterlogged slab");
	}

	public function testConcretePowderIgnoresWaterBehindFullFaces() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(-1, 1, 0, VanillaBlocks::STONE());
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		$this->set(0, 2, 0, VanillaBlocks::CONCRETE_POWDER());
		//full bottom face of a waterlogged bottom slab above it
		$this->set(0, 3, 0, VanillaBlocks::OAK_SLAB());
		$this->waterlog(0, 3, 0);
		//full back face of a waterlogged stair beside it
		$this->set(1, 2, 0, VanillaBlocks::OAK_STAIRS()->setFacing(Facing::WEST));
		$this->waterlog(1, 2, 0);
		$this->get(0, 2, 0)->onNearbyBlockChange();
		self::assertNotInstanceOf(Concrete::class, $this->get(0, 2, 0));

		//the open front of the stair lets the water touch it
		$this->set(1, 2, 0, VanillaBlocks::OAK_STAIRS()->setFacing(Facing::EAST));
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 1, 2, 0));
		$this->get(0, 2, 0)->onNearbyBlockChange();
		self::assertInstanceOf(Concrete::class, $this->get(0, 2, 0));
	}

	public function testSetBlockAirOnWaterloggedStairGivesAir() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_STAIRS());
		$this->waterlog(0, 1, 0);
		$this->world->setBlock(new Vector3(0, 1, 0), VanillaBlocks::AIR());
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $this->world->getBlockAt(0, 1, 0)->getStateId());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));
	}

	public function testExplosionsSpareWaterloggedBlocks() : void{
		$this->floor(-4, -4, 4, 4);
		$this->set(1, 1, 0, VanillaBlocks::OAK_STAIRS());
		$this->waterlog(1, 1, 0);
		$this->set(-1, 1, 0, VanillaBlocks::OAK_STAIRS());
		$explosion = new Explosion(new Position(0.5, 1.5, 0.5, $this->world), 4);
		$explosion->explodeA();
		$hashes = array_keys($explosion->affectedBlocks);
		self::assertNotContains(World::blockHash(1, 1, 0), $hashes, "waterlogged stair");
		self::assertContains(World::blockHash(-1, 1, 0), $hashes, "dry stair");
	}

	public function testLavaHardensNextToHeldWater() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->waterlog(1, 1, 0);
		$this->set(0, 1, 0, VanillaBlocks::LAVA());
		$this->get(0, 1, 0)->onNearbyBlockChange();
		self::assertSame(VanillaBlocks::OBSIDIAN()->getTypeId(), $this->get(0, 1, 0)->getTypeId());
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 1, 1, 0), "the fence keeps its water");

		$this->set(0, 1, 0, VanillaBlocks::LAVA()->setDecay(2));
		$this->get(0, 1, 0)->onNearbyBlockChange();
		self::assertSame(VanillaBlocks::COBBLESTONE()->getTypeId(), $this->get(0, 1, 0)->getTypeId());
	}

	/**
	 * A water source in a 1-block hole, closed on every side.
	 */
	private function hole() : void{
		$this->floor(-2, -2, 2, 2);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				for($y = 1; $y <= 2; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER(), false);
	}

	public function testCoralKeepsTheWaterItIsPlacedIn() : void{
		$this->hole();
		$this->set(0, 1, 0, VanillaBlocks::CORAL());
		self::assertSame(VanillaBlocks::WATER()->getStillForm()->getStateId(), $this->liquidLayer(0, 1, 0));

		//its own water keeps it alive, though there is no water around it
		$coral = $this->get(0, 1, 0);
		self::assertInstanceOf(BaseCoral::class, $coral);
		$coral->onScheduledUpdate();
		$coral = $this->get(0, 1, 0);
		self::assertInstanceOf(BaseCoral::class, $coral);
		self::assertFalse($coral->isDead());

		//breaking it gives the water back
		$this->breakBlock(0, 1, 0);
		self::assertInstanceOf(Water::class, $this->get(0, 1, 0));

		//placed out of water: no water, and it dies
		$this->set(0, 1, 0, VanillaBlocks::AIR());
		$this->set(0, 1, 0, VanillaBlocks::CORAL());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));
		$this->get(0, 1, 0)->onScheduledUpdate();
		$coral = $this->get(0, 1, 0);
		self::assertInstanceOf(BaseCoral::class, $coral);
		self::assertTrue($coral->isDead());
	}

	public function testCoralFanPlacedInWaterIsWaterlogged() : void{
		$this->hole();
		$item = VanillaItems::CORAL_FAN();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		$fan = $this->get(0, 1, 0);
		self::assertInstanceOf(FloorCoralFan::class, $fan);
		self::assertFalse($fan->isDead());
		self::assertTrue(LiquidLayer::hasLayerWaterAt($this->world, 0, 1, 0));

		//a dead fan stays dead in water
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER(), false);
		$item = VanillaItems::CORAL_FAN()->setDead(true);
		self::assertTrue($this->world->useItemOn(new Vector3(0, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5)));
		$fan = $this->get(0, 1, 0);
		self::assertInstanceOf(FloorCoralFan::class, $fan);
		self::assertTrue($fan->isDead());
	}

	public function testEyesInsideWaterloggedStairAreUnderwater() : void{
		$this->floor(-2, -2, 2, 2);
		//a cow: zombies breathe underwater
		$zombie = new Cow(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$eyeY = (int) floor(1 + $zombie->getEyeHeight());
		$this->set(0, $eyeY, 0, VanillaBlocks::OAK_STAIRS());
		self::assertFalse($zombie->isUnderwater());
		$this->waterlog(0, $eyeY, 0);
		self::assertTrue($zombie->isUnderwater());
		self::assertFalse($zombie->canBreathe());
		$zombie->close();
	}

	public function testMobInWaterloggedSlabIsInWater() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_SLAB());
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1.5, 0.5), $this->world));
		self::assertFalse($zombie->isInWater());
		self::assertSame(0, PathBlockTypes::at($this->world, 0, 1, 0) & PathBlockTypes::WATER);
		$this->waterlog(0, 1, 0);
		self::assertTrue($zombie->isInWater());
		self::assertNotSame(0, PathBlockTypes::at($this->world, 0, 1, 0) & PathBlockTypes::WATER);
		//the state cache isn't changed by the water: an identical dry slab stays dry
		$this->set(1, 1, 0, VanillaBlocks::OAK_SLAB());
		self::assertSame(0, PathBlockTypes::at($this->world, 1, 1, 0) & PathBlockTypes::WATER);
		$zombie->close();
	}

	public function testHeldWaterExtinguishesEntities() : void{
		$this->floor(-2, -2, 2, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->waterlog(0, 1, 0);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$zombie->setOnFire(5);
		self::assertTrue($zombie->isOnFire());
		(new \ReflectionMethod($zombie, "checkBlockIntersections"))->invoke($zombie);
		self::assertFalse($zombie->isOnFire());
		$zombie->close();
	}
}
