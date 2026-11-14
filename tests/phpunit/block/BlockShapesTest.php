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

use pocketmine\block\utils\SlabType;
use pocketmine\block\utils\WallConnectionType;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use function mt_srand;

/**
 * Wall sides and posts, fence connections, pane/bar support and mud (batch block-shapes). The expected wall states were
 * checked against BDS 1.26.52.
 */
class BlockShapesTest extends RedstoneTestCase{

	protected function setUp() : void{
		parent::setUp();
		$this->floor(-8, -8, 8, 8);
	}

	private function wall(int $x, int $y, int $z) : Wall{
		$block = $this->get($x, $y, $z);
		self::assertInstanceOf(Wall::class, $block, "($x, $y, $z)");
		return $block;
	}

	/**
	 * Builds an east-west line of three walls at y=1 centred on ($x, $z), puts $above on the middle one and returns
	 * the middle wall once the updates have run.
	 */
	private function lineWith(?Block $above, int $x = 0, int $z = 0) : Wall{
		for($dx = -1; $dx <= 1; ++$dx){
			$this->set($x + $dx, 1, $z, VanillaBlocks::COBBLESTONE_WALL());
		}
		if($above !== null){
			$this->set($x, 2, $z, $above);
		}
		$this->runTicks(3);
		return $this->wall($x, 1, $z);
	}

	private static function assertSides(?WallConnectionType $type, Wall $wall) : void{
		self::assertSame($type, $wall->getConnection(Facing::EAST), "east");
		self::assertSame($type, $wall->getConnection(Facing::WEST), "west");
		self::assertNull($wall->getConnection(Facing::NORTH), "north");
		self::assertNull($wall->getConnection(Facing::SOUTH), "south");
	}

	public function testStraightLineHasShortSidesAndNoPost() : void{
		$wall = $this->lineWith(null);
		self::assertSides(WallConnectionType::SHORT, $wall);
		self::assertFalse($wall->isPost());
		//the ends keep their posts
		self::assertTrue($this->wall(-1, 1, 0)->isPost());
		self::assertTrue($this->wall(1, 1, 0)->isPost());
	}

	public function testUnderBottomSlabSidesAreTall() : void{
		$wall = $this->lineWith(VanillaBlocks::STONE_SLAB()->setSlabType(SlabType::BOTTOM));
		self::assertSides(WallConnectionType::TALL, $wall);
		self::assertFalse($wall->isPost());
	}

	public function testUnderFullBlockSidesAreTall() : void{
		$wall = $this->lineWith(VanillaBlocks::STONE());
		self::assertSides(WallConnectionType::TALL, $wall);
		self::assertFalse($wall->isPost());
	}

	public function testUnderTopSlabSidesStayShort() : void{
		$wall = $this->lineWith(VanillaBlocks::STONE_SLAB()->setSlabType(SlabType::TOP));
		self::assertSides(WallConnectionType::SHORT, $wall);
		self::assertFalse($wall->isPost());
	}

	public function testBlocksWithoutCollisionCoverTheTop() : void{
		//BDS: carpets, pressure plates, liquids and cobwebs above make tall sides
		self::assertSides(WallConnectionType::TALL, $this->lineWith(VanillaBlocks::STONE_PRESSURE_PLATE(), 0, 0));
		self::assertSides(WallConnectionType::TALL, $this->lineWith(VanillaBlocks::CARPET(), 0, 4));
		self::assertSides(WallConnectionType::TALL, $this->lineWith(VanillaBlocks::WATER(), 0, -4));
	}

	public function testTorchOnTopRaisesThePost() : void{
		$wall = $this->lineWith(VanillaBlocks::TORCH()->setFacing(Facing::UP));
		self::assertSides(WallConnectionType::SHORT, $wall);
		self::assertTrue($wall->isPost());
	}

	public function testSmallBlocksOnTopRaiseThePost() : void{
		self::assertTrue($this->lineWith(VanillaBlocks::LANTERN(), 0, 0)->isPost());
		self::assertTrue($this->lineWith(VanillaBlocks::CANDLE(), 0, 4)->isPost());
		self::assertTrue($this->lineWith(VanillaBlocks::OAK_FENCE(), 0, -4)->isPost());
	}

	public function testUnderWallWithPostRaisesThePost() : void{
		//the wall above is on its own, so it has a post
		$wall = $this->lineWith(VanillaBlocks::COBBLESTONE_WALL());
		self::assertTrue($this->wall(0, 2, 0)->isPost());
		self::assertSides(WallConnectionType::SHORT, $wall);
		self::assertTrue($wall->isPost());
	}

	public function testUnderWallLineSidesAreTall() : void{
		for($dx = -1; $dx <= 1; ++$dx){
			$this->set($dx, 2, 0, VanillaBlocks::COBBLESTONE_WALL());
		}
		$wall = $this->lineWith(null);
		self::assertFalse($this->wall(0, 2, 0)->isPost());
		self::assertSides(WallConnectionType::TALL, $wall);
		self::assertFalse($wall->isPost());
	}

	public function testFenceArmAboveCoversOneSide() : void{
		$this->set(1, 2, 0, VanillaBlocks::OAK_FENCE());
		$wall = $this->lineWith(VanillaBlocks::OAK_FENCE());
		self::assertSame(WallConnectionType::TALL, $wall->getConnection(Facing::EAST));
		self::assertSame(WallConnectionType::SHORT, $wall->getConnection(Facing::WEST));
		self::assertTrue($wall->isPost());
	}

	public function testIsolatedWallHasPost() : void{
		$this->set(0, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->runTicks(2);
		$wall = $this->wall(0, 1, 0);
		self::assertSame([], $wall->getConnections());
		self::assertTrue($wall->isPost());
	}

	public function testCornerAndTKeepPostUnderBlocks() : void{
		$this->set(0, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->set(1, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->set(0, 1, 1, VanillaBlocks::COBBLESTONE_WALL());
		$this->set(0, 2, 0, VanillaBlocks::STONE());
		$this->runTicks(3);
		$corner = $this->wall(0, 1, 0);
		self::assertSame(WallConnectionType::TALL, $corner->getConnection(Facing::EAST));
		self::assertSame(WallConnectionType::TALL, $corner->getConnection(Facing::SOUTH));
		self::assertTrue($corner->isPost());

		$this->set(-1, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->runTicks(3);
		$t = $this->wall(0, 1, 0);
		self::assertCount(3, $t->getConnections());
		self::assertTrue($t->isPost());
	}

	public function testSidesUpdateWhenTheBlockAboveGoes() : void{
		$wall = $this->lineWith(VanillaBlocks::STONE());
		self::assertSides(WallConnectionType::TALL, $wall);
		$this->set(0, 2, 0, VanillaBlocks::AIR());
		$this->runTicks(3);
		self::assertSides(WallConnectionType::SHORT, $this->wall(0, 1, 0));
	}

	public function testWallConnectsOnlyToGatesInLine() : void{
		$this->set(0, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		//gate east of the wall facing north spans east-west: in line
		$this->set(1, 1, 0, VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::NORTH));
		//gate west of the wall facing west: turned sideways
		$this->set(-1, 1, 0, VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::WEST));
		$this->runTicks(3);
		$wall = $this->wall(0, 1, 0);
		self::assertNotNull($wall->getConnection(Facing::EAST));
		self::assertNull($wall->getConnection(Facing::WEST));
	}

	private function fence(int $x, int $y, int $z) : Fence{
		$block = $this->get($x, $y, $z);
		self::assertInstanceOf(Fence::class, $block, "($x, $y, $z)");
		return $block;
	}

	public function testNetherBrickAndWoodenFencesDontConnect() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->set(1, 1, 0, VanillaBlocks::NETHER_BRICK_FENCE());
		$this->set(-1, 1, 0, VanillaBlocks::SPRUCE_FENCE());
		$this->runTicks(3);
		self::assertFalse($this->fence(0, 1, 0)->hasConnection(Facing::EAST));
		self::assertFalse($this->fence(1, 1, 0)->hasConnection(Facing::WEST));
		//different woods do join
		self::assertTrue($this->fence(0, 1, 0)->hasConnection(Facing::WEST));
		self::assertTrue($this->fence(-1, 1, 0)->hasConnection(Facing::EAST));
	}

	public function testFenceConnectsOnlyToGatesInLine() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->set(1, 1, 0, VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::SOUTH));
		$this->set(0, 1, 1, VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::SOUTH));
		$this->runTicks(3);
		$fence = $this->fence(0, 1, 0);
		self::assertTrue($fence->hasConnection(Facing::EAST), "gate spanning east-west, in line");
		self::assertFalse($fence->hasConnection(Facing::SOUTH), "gate facing along the connection");
	}

	public function testFenceIgnoresConnectionExceptions() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_FENCE());
		$this->set(1, 1, 0, VanillaBlocks::PUMPKIN());
		$this->set(-1, 1, 0, VanillaBlocks::MELON());
		$this->set(0, 1, 1, VanillaBlocks::BARRIER());
		$this->set(0, 1, -1, VanillaBlocks::STONE());
		$this->runTicks(3);
		$fence = $this->fence(0, 1, 0);
		self::assertFalse($fence->hasConnection(Facing::EAST));
		self::assertFalse($fence->hasConnection(Facing::WEST));
		self::assertFalse($fence->hasConnection(Facing::SOUTH));
		self::assertTrue($fence->hasConnection(Facing::NORTH));
	}

	private function useOn(Item $item, int $x, int $y, int $z, int $face) : bool{
		return $this->world->useItemOn(new Vector3($x, $y, $z), $item, $face, new Vector3(0.5, 0.5, 0.5));
	}

	public function testTorchStandsOnGlassPane() : void{
		$this->set(0, 1, 0, VanillaBlocks::GLASS_PANE());
		self::assertTrue($this->useOn(VanillaBlocks::TORCH()->asItem(), 0, 1, 0, Facing::UP));
		self::assertInstanceOf(Torch::class, $this->get(0, 2, 0));
		$this->runTicks(2);
		self::assertInstanceOf(Torch::class, $this->get(0, 2, 0), "survives the neighbour update");
	}

	public function testLanternHangsUnderIronBars() : void{
		$this->set(0, 3, 0, VanillaBlocks::IRON_BARS());
		self::assertTrue($this->useOn(VanillaBlocks::LANTERN()->asItem(), 0, 3, 0, Facing::DOWN));
		$lantern = $this->get(0, 2, 0);
		self::assertInstanceOf(Lantern::class, $lantern);
		self::assertTrue($lantern->isHanging());
		$this->runTicks(2);
		self::assertInstanceOf(Lantern::class, $this->get(0, 2, 0));
	}

	public function testTallFlowerCanBePlacedOnMud() : void{
		$this->set(0, 1, 0, VanillaBlocks::MUD());
		self::assertTrue($this->useOn(VanillaBlocks::SUNFLOWER()->asItem(), 0, 1, 0, Facing::UP));
		self::assertInstanceOf(DoublePlant::class, $this->get(0, 2, 0));
		self::assertInstanceOf(DoublePlant::class, $this->get(0, 3, 0));

		$this->set(2, 1, 0, VanillaBlocks::MUDDY_MANGROVE_ROOTS());
		self::assertTrue($this->useOn(VanillaBlocks::SUNFLOWER()->asItem(), 2, 1, 0, Facing::UP));
		self::assertInstanceOf(DoublePlant::class, $this->get(2, 2, 0));
	}

	public function testMudCollisionIsFourteenPixelsHigh() : void{
		$this->set(0, 1, 0, VanillaBlocks::MUD());
		$boxes = $this->get(0, 1, 0)->getCollisionBoxes();
		self::assertCount(1, $boxes);
		self::assertEqualsWithDelta(1 + 14 / 16, $boxes[0]->maxY, 0.0001);
		//still a full block for support and redstone
		self::assertTrue(VanillaBlocks::MUD()->isFullCube());
		self::assertTrue(VanillaBlocks::MUD()->isRedstoneConductor());
	}

	public function testEntityStandsInMud() : void{
		//the zombie's AI is random (wandering, jumping): same rolls whatever ran before (failed now and then in the full suite)
		mt_srand(20261026);
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::MUD());
			}
		}
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 2.5, 0.5), $this->world));
		$zombie->spawnToAll();
		$this->runTicks(40);
		self::assertTrue($zombie->isOnGround());
		self::assertEqualsWithDelta(1 + 14 / 16, $zombie->getPosition()->y, 0.001);
		$zombie->flagForDespawn();
	}
}
