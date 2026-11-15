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

namespace pocketmine\world\dimension;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\NetherPortal;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;

class NetherPortalTest extends DimensionWorldTestCase{

	public function testLightMinimalFrame() : void{
		$this->buildFrameX(0, 10, 0, 2, 3);
		self::assertTrue(NetherPortalShape::tryLight($this->world, new Vector3(1, 11, 0)));
		for($x = 0; $x < 2; ++$x){
			for($y = 10; $y < 13; ++$y){
				$block = $this->get($x, $y, 0);
				self::assertInstanceOf(NetherPortal::class, $block);
				self::assertSame(Axis::X, $block->getAxis());
			}
		}
		self::assertCount(1, NetherPortalRegistry::getInstance()->getAll($this->world));
	}

	public function testCornersAreNotNeeded() : void{
		$this->buildFrameX(0, 10, 0, 3, 4, corners: false);
		$shape = NetherPortalShape::findIgnitable($this->world, new Vector3(2, 13, 0));
		self::assertNotNull($shape);
		self::assertSame(3, $shape->width);
		self::assertSame(4, $shape->height);
		self::assertTrue($shape->origin->equals(new Vector3(0, 10, 0)));
	}

	public function testZAxisFrame() : void{
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($z = -1; $z <= 2; ++$z){
			$this->set(5, 9, $z, $obsidian);
			$this->set(5, 13, $z, $obsidian);
		}
		for($y = 10; $y <= 12; ++$y){
			$this->set(5, $y, -1, $obsidian);
			$this->set(5, $y, 2, $obsidian);
		}
		$shape = NetherPortalShape::findIgnitable($this->world, new Vector3(5, 10, 0));
		self::assertNotNull($shape);
		self::assertSame(Axis::Z, $shape->axis);
	}

	public function testAnyFireInAFrameLightsIt() : void{
		//fire from anything (spreading fire, lava, lightning, dispensers...) goes through World::setBlock()
		$this->buildFrameX(0, 10, 0, 2, 3);
		$this->set(1, 10, 0, VanillaBlocks::FIRE());
		self::assertInstanceOf(NetherPortal::class, $this->get(0, 12, 0));
		self::assertInstanceOf(NetherPortal::class, $this->get(1, 10, 0), "the fire became portal");

		//soul fire too, even in the air in the middle of the frame
		$this->buildFrameX(10, 10, 0, 2, 3);
		$this->set(11, 11, 0, VanillaBlocks::SOUL_FIRE());
		self::assertInstanceOf(NetherPortal::class, $this->get(10, 10, 0));
	}

	public function testFireOutsideAFrameStaysFire() : void{
		$this->set(0, 9, 5, VanillaBlocks::OBSIDIAN());
		$this->set(0, 10, 5, VanillaBlocks::FIRE());
		self::assertSame(BlockTypeIds::FIRE, $this->get(0, 10, 5)->getTypeId());
	}

	public function testFireDoesNotLightAFrameInTheEnd() : void{
		$end = $this->createWorld("dimension_test_the_end", Dimension::END);
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($w = -1; $w <= 2; ++$w){
			for($h = -1; $h <= 3; ++$h){
				if($w === -1 || $w === 2 || $h === -1 || $h === 3){
					$end->setBlockAt($w, 10 + $h, 0, $obsidian);
				}
			}
		}
		$end->setBlockAt(0, 10, 0, VanillaBlocks::FIRE());
		self::assertSame(BlockTypeIds::FIRE, $end->getBlockAt(0, 10, 0)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $end->getBlockAt(1, 11, 0)->getTypeId());
	}

	public function testTooSmallOrOpenFrameDoesNotLight() : void{
		$this->buildFrameX(0, 10, 0, 1, 3);
		self::assertFalse(NetherPortalShape::tryLight($this->world, new Vector3(0, 10, 0)));

		$this->buildFrameX(10, 10, 0, 2, 3);
		$this->set(10, 13, 0, VanillaBlocks::AIR()); //hole in the top
		self::assertFalse(NetherPortalShape::tryLight($this->world, new Vector3(10, 10, 0)));
	}

	public function testOversizedFrameDoesNotLight() : void{
		$this->buildFrameX(-10, 10, 0, 22, 3);
		self::assertNull(NetherPortalShape::findIgnitable($this->world, new Vector3(0, 10, 0)));
	}

	public function testBreakingTheFrameBreaksThePortal() : void{
		$this->buildFrameX(0, 10, 0, 2, 3);
		self::assertTrue(NetherPortalShape::tryLight($this->world, new Vector3(0, 10, 0)));
		$this->world->setBlockAt(-1, 11, 0, VanillaBlocks::AIR());
		$this->runTicks(5);
		for($x = 0; $x < 2; ++$x){
			for($y = 10; $y < 13; ++$y){
				self::assertSame(BlockTypeIds::AIR, $this->get($x, $y, 0)->getTypeId(), "portal block at $x $y should have broken");
			}
		}
		self::assertNull(NetherPortalShape::findLit($this->world, new Vector3(0, 10, 0)));
	}

	public function testRegistryFindsNearestLitPortalAndForgetsBrokenOnes() : void{
		$this->buildFrameX(0, 10, 0, 2, 3);
		$this->buildFrameX(20, 10, 0, 2, 3);
		NetherPortalShape::tryLight($this->world, new Vector3(0, 10, 0));
		NetherPortalShape::tryLight($this->world, new Vector3(20, 10, 0));
		$registry = NetherPortalRegistry::getInstance();

		$found = $registry->findNearest($this->world, new Vector3(18, 10, 0), 16);
		self::assertNotNull($found);
		self::assertSame(20, $found->origin->getFloorX());

		self::assertNull($registry->findNearest($this->world, new Vector3(-30, 10, 0), 16));

		$this->world->setBlockAt(20, 9, 0, VanillaBlocks::STONE());
		$this->runTicks(5);
		$found = $registry->findNearest($this->world, new Vector3(18, 10, 0), 32);
		self::assertNotNull($found);
		self::assertSame(0, $found->origin->getFloorX());
		self::assertCount(1, $registry->getAll($this->world));

		//saved to disk
		$reloaded = new NetherPortalRegistry($this->registryPath);
		self::assertCount(1, $reloaded->getAll($this->world));
	}

	public function testBuilderUsesSolidGround() : void{
		$this->fill(-20, 0, -20, 20, 9, 20, VanillaBlocks::STONE());
		$shape = NetherPortalBuilder::create($this->world, new Vector3(3, 40, 4));
		self::assertSame(10, $shape->origin->getFloorY(), "portal should stand on the ground");
		self::assertNotNull(NetherPortalShape::findLit($this->world, $shape->origin));
		foreach($shape->getInteriorPositions() as $pos){
			self::assertInstanceOf(NetherPortal::class, $this->world->getBlock($pos));
		}
		self::assertCount(1, NetherPortalRegistry::getInstance()->getAll($this->world));
	}

	public function testBuilderMakesPlatformWhenNoGround() : void{
		$shape = NetherPortalBuilder::create($this->world, new Vector3(3, 40, 4));
		self::assertSame(70, $shape->origin->getFloorY());
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->get(3, 69, 5)->getTypeId(), "platform in front of the portal");
		self::assertNotNull(NetherPortalShape::findLit($this->world, $shape->origin));
	}

	public function testScaledArrival() : void{
		$nether = PortalTravel::getScaledArrival(new Vector3(801.5, 64, -803.2), Dimension::OVERWORLD, $this->world);
		//target is this (Overworld) test world: no scaling into it from the Overworld
		self::assertEquals(801.0, $nether->x);

		$fromNether = PortalTravel::getScaledArrival(new Vector3(10.5, 64, -3.5), Dimension::NETHER, $this->world);
		self::assertEquals(84.0, $fromNether->x);
		self::assertEquals(-28.0, $fromNether->z);
		self::assertEquals(64.0, $fromNether->y);
	}
}
