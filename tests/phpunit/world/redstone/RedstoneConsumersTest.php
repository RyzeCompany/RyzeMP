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

namespace pocketmine\world\redstone;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\CopperBulb;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Hopper;
use pocketmine\block\PoweredRail;
use pocketmine\block\RedstoneLamp;
use pocketmine\block\Trapdoor;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WoodenDoor;
use pocketmine\data\bedrock\block\BlockLegacyMetadata;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\math\Vector3;
use function array_filter;

class RedstoneConsumersTest extends RedstoneTestCase{

	private function lampLit(int $x, int $y, int $z) : bool{
		$lamp = $this->get($x, $y, $z);
		self::assertInstanceOf(RedstoneLamp::class, $lamp);
		return $lamp->isLit();
	}

	public function testLampTurnsOnInstantlyAndOffAfterDelay() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(0, 1, 0, VanillaBlocks::LEVER());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->runTicks(1);
		self::assertFalse($this->lampLit(2, 1, 0));

		$this->interact(0, 1, 0);
		$this->runTicks(1);
		self::assertTrue($this->lampLit(2, 1, 0));

		$this->interact(0, 1, 0);
		$this->runTicks(1);
		self::assertTrue($this->lampLit(2, 1, 0));
		$this->runTicks(RedstoneLamp::TURN_OFF_DELAY_TICKS);
		self::assertFalse($this->lampLit(2, 1, 0));
	}

	public function testLampPoweredThroughBlock() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(1, 2, 0, VanillaBlocks::LEVER());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->interact(1, 2, 0);
		$this->runTicks(2);
		self::assertTrue($this->lampLit(2, 1, 0));
	}

	private function placeDoor(int $x, int $z, Door $door) : void{
		$this->set($x, 1, $z, (clone $door)->setTop(false));
		$this->set($x, 2, $z, (clone $door)->setTop(true));
	}

	private function doorOpen(int $x, int $y, int $z) : bool{
		$door = $this->get($x, $y, $z);
		self::assertInstanceOf(Door::class, $door);
		return $door->isOpen();
	}

	public function testDoorOpensWithPowerOnEitherHalf() : void{
		$this->floor(-1, -1, 6, 1);
		$this->placeDoor(2, 0, VanillaBlocks::OAK_DOOR());
		$this->set(1, 2, 0, VanillaBlocks::REDSTONE()); //next to the top half
		$this->runTicks(1);
		self::assertTrue($this->doorOpen(2, 1, 0));
		self::assertTrue($this->doorOpen(2, 2, 0));

		$this->breakBlock(1, 2, 0);
		$this->runTicks(1);
		self::assertFalse($this->doorOpen(2, 1, 0));
		self::assertFalse($this->doorOpen(2, 2, 0));
	}

	public function testPoweredWoodenDoorCanBeClosedByHand() : void{
		$this->floor(-1, -1, 6, 1);
		$this->placeDoor(2, 0, VanillaBlocks::OAK_DOOR());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		self::assertTrue($this->doorOpen(2, 1, 0));
		self::assertInstanceOf(WoodenDoor::class, $this->get(2, 1, 0));
		$this->interact(2, 1, 0);
		$this->runTicks(5);
		self::assertFalse($this->doorOpen(2, 1, 0), "doors only react to power changes");
	}

	public function testIronDoorOnlyOpensWithPower() : void{
		$this->floor(-1, -1, 6, 1);
		$this->placeDoor(2, 0, VanillaBlocks::IRON_DOOR());
		$this->interact(2, 1, 0);
		$this->runTicks(1);
		self::assertFalse($this->doorOpen(2, 1, 0));
		$this->set(1, 1, 0, VanillaBlocks::LEVER()->setActivated(true));
		$this->runTicks(1);
		self::assertTrue($this->doorOpen(2, 1, 0));
	}

	public function testTrapdoorAndFenceGate() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(1, 1, 0, VanillaBlocks::IRON_TRAPDOOR());
		$this->set(3, 1, 0, VanillaBlocks::OAK_FENCE_GATE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		$trapdoor = $this->get(1, 1, 0);
		self::assertInstanceOf(Trapdoor::class, $trapdoor);
		self::assertTrue($trapdoor->isOpen());
		$gate = $this->get(3, 1, 0);
		self::assertInstanceOf(FenceGate::class, $gate);
		self::assertTrue($gate->isOpen());

		$this->interact(1, 1, 0);
		$this->runTicks(1);
		$trapdoor = $this->get(1, 1, 0);
		self::assertInstanceOf(Trapdoor::class, $trapdoor);
		self::assertTrue($trapdoor->isOpen(), "iron trapdoors ignore hands");
	}

	public function testTntIgnitesWhenPowered() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(2, 1, 0, VanillaBlocks::TNT());
		$this->set(1, 1, 0, VanillaBlocks::LEVER());
		$this->runTicks(1);
		self::assertSame(BlockTypeIds::TNT, $this->get(2, 1, 0)->getTypeId());
		$this->interact(1, 1, 0);
		$this->runTicks(1);
		self::assertSame(BlockTypeIds::AIR, $this->get(2, 1, 0)->getTypeId());
		$primed = array_filter($this->world->getEntities(), fn($e) => $e instanceof PrimedTNT);
		self::assertCount(1, $primed);
		foreach($primed as $entity){
			$entity->flagForDespawn();
		}
	}

	public function testNoteBlockRemembersPowerEdge() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(2, 1, 0, VanillaBlocks::NOTE_BLOCK());
		$this->set(1, 1, 0, VanillaBlocks::LEVER());
		$this->runTicks(1);
		self::assertFalse($this->redstone()->getRememberedPowered(2, 1, 0));
		$this->interact(1, 1, 0);
		$this->runTicks(1);
		self::assertTrue($this->redstone()->getRememberedPowered(2, 1, 0));
		$this->interact(1, 1, 0);
		$this->runTicks(1);
		self::assertFalse($this->redstone()->getRememberedPowered(2, 1, 0));
	}

	public function testPoweredRailChain() : void{
		$this->floor(-1, -1, 20, 1);
		for($x = 1; $x <= 11; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::POWERED_RAIL()->setShape(BlockLegacyMetadata::RAIL_STRAIGHT_EAST_WEST));
		}
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		for($x = 1; $x <= 9; ++$x){
			$rail = $this->get($x, 1, 0);
			self::assertInstanceOf(PoweredRail::class, $rail);
			self::assertTrue($rail->isPowered(), "rail at x=$x");
		}
		foreach([10, 11] as $x){
			$rail = $this->get($x, 1, 0);
			self::assertInstanceOf(PoweredRail::class, $rail);
			self::assertFalse($rail->isPowered(), "rail at x=$x is out of range");
		}

		$this->breakBlock(0, 1, 0);
		$this->runTicks(3);
		$rail = $this->get(5, 1, 0);
		self::assertInstanceOf(PoweredRail::class, $rail);
		self::assertFalse($rail->isPowered());
	}

	public function testCopperBulbTogglesOnRisingEdge() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(2, 1, 0, VanillaBlocks::COPPER_BULB());
		$this->set(1, 1, 0, VanillaBlocks::LEVER());
		$this->runTicks(1);
		$bulb = $this->get(2, 1, 0);
		self::assertInstanceOf(CopperBulb::class, $bulb);
		self::assertFalse($bulb->isLit());

		$this->interact(1, 1, 0);
		$this->runTicks(1);
		$bulb = $this->get(2, 1, 0);
		self::assertInstanceOf(CopperBulb::class, $bulb);
		self::assertTrue($bulb->isLit());

		$this->interact(1, 1, 0); //falling edge: stays lit
		$this->runTicks(1);
		$bulb = $this->get(2, 1, 0);
		self::assertInstanceOf(CopperBulb::class, $bulb);
		self::assertTrue($bulb->isLit());

		$this->interact(1, 1, 0); //rising edge: toggles off
		$this->runTicks(1);
		$bulb = $this->get(2, 1, 0);
		self::assertInstanceOf(CopperBulb::class, $bulb);
		self::assertFalse($bulb->isLit());
	}

	public function testHopperLock() : void{
		$this->floor(-1, -1, 6, 1);
		$this->set(2, 1, 0, VanillaBlocks::HOPPER());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		$hopper = $this->get(2, 1, 0);
		self::assertInstanceOf(Hopper::class, $hopper);
		self::assertTrue($hopper->isPowered());
		self::assertTrue($this->world->isBlockPowered(new Vector3(2, 1, 0)));
		self::assertSame(15, $this->world->getBlockPower(new Vector3(2, 1, 0)));
		self::assertSame(0, $this->world->getBlockPower(new Vector3(4, 1, 0)));
	}
}
