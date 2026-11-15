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
use pocketmine\block\Piston;
use pocketmine\block\PistonHead;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\MovingBlock as TileMovingBlock;
use pocketmine\block\tile\PistonArm;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function array_map;

class PistonTest extends RedstoneTestCase{

	private function placePiston(int $x, int $y, int $z, int $facing, bool $sticky = false) : void{
		$this->set($x, $y, $z, ($sticky ? VanillaBlocks::STICKY_PISTON() : VanillaBlocks::PISTON())->setFacing($facing));
	}

	private function arm(int $x, int $y, int $z) : PistonArm{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(PistonArm::class, $tile);
		return $tile;
	}

	private function assertType(int $typeId, int $x, int $y, int $z) : void{
		self::assertSame($typeId, $this->get($x, $y, $z)->getTypeId(), "Unexpected block at $x $y $z: " . $this->get($x, $y, $z)->getName());
	}

	/**
	 * Powers the piston at 0,1,0 with a redstone block behind it (west side) and runs enough ticks for a full move.
	 */
	private function powerAndMove(bool $power = true) : void{
		if($power){
			$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		}else{
			$this->set(-1, 1, 0, VanillaBlocks::AIR());
		}
		$this->runTicks(8);
	}

	public function testExtendTiming() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->runTicks(2);

		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1); //the piston notices the power change
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
		$this->runTicks(2); //one redstone tick of start delay
		self::assertSame(PistonArm::STATE_EXTENDING, $this->arm(0, 1, 0)->getState());
		$this->assertType(BlockTypeIds::PISTON_HEAD, 1, 1, 0);
		$this->assertType(BlockTypeIds::MOVING_BLOCK, 2, 1, 0);
		$moving = $this->world->getTileAt(2, 1, 0);
		self::assertInstanceOf(TileMovingBlock::class, $moving);
		self::assertSame(BlockTypeIds::STONE, $moving->getMovingBlock()?->getTypeId());
		self::assertEquals([new Vector3(1, 1, 0)], $this->arm(0, 1, 0)->getAttachedBlocks());

		$this->runTicks(1);
		self::assertSame(0.5, $this->arm(0, 1, 0)->getProgress());
		$this->runTicks(1);
		self::assertSame(PistonArm::STATE_EXTENDED, $this->arm(0, 1, 0)->getState());
		self::assertSame(1.0, $this->arm(0, 1, 0)->getProgress());
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);
		$this->assertType(BlockTypeIds::PISTON_HEAD, 1, 1, 0);
		$head = $this->get(1, 1, 0);
		self::assertInstanceOf(PistonHead::class, $head);
		self::assertSame(Facing::EAST, $head->getFacing());
	}

	public function testRetract() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);

		$this->powerAndMove(false);
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
		$this->assertType(BlockTypeIds::AIR, 1, 1, 0);
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0); //a normal piston doesn't pull
	}

	public function testStickyPistonPulls() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST, true);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::STICKY_PISTON_HEAD, 1, 1, 0);
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);

		$this->powerAndMove(false);
		$this->assertType(BlockTypeIds::STONE, 1, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 2, 1, 0);
	}

	public function testPushLimit() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		for($x = 1; $x <= 12; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::DIRT());
		}
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::PISTON_HEAD, 1, 1, 0);
		$this->assertType(BlockTypeIds::DIRT, 13, 1, 0);

		$this->powerAndMove(false);
		$this->set(1, 1, 0, VanillaBlocks::DIRT()); //13 blocks now
		$this->powerAndMove();
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
		$this->assertType(BlockTypeIds::DIRT, 1, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 14, 1, 0);
	}

	public function testImmovableBlocks() : void{
		foreach([VanillaBlocks::OBSIDIAN(), VanillaBlocks::BEDROCK(), VanillaBlocks::ENCHANTING_TABLE()] as $block){
			$this->floor(-2, -2, 16, 2);
			$this->placePiston(0, 1, 0, Facing::EAST);
			$this->set(1, 1, 0, VanillaBlocks::STONE());
			$this->set(2, 1, 0, $block);
			$this->powerAndMove();
			self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState(), $block->getName());
			$this->assertType(BlockTypeIds::STONE, 1, 1, 0);
			$this->set(-1, 1, 0, VanillaBlocks::AIR());
			$this->runTicks(4);
		}
	}

	public function testBlockedPistonExtendsOnceFree() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::OBSIDIAN());
		$this->powerAndMove();
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
		$this->set(1, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(10);
		self::assertSame(PistonArm::STATE_EXTENDED, $this->arm(0, 1, 0)->getState());
	}

	public function testBreaksFragileBlocks() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(2, 1, 0, VanillaBlocks::DANDELION());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 3, 1, 0);
	}

	public function testSlimeDragsBlocks() : void{
		//no floor: slime would stick to it
		$this->placePiston(0, 1, 0, Facing::EAST, true);
		$this->set(1, 1, 0, VanillaBlocks::SLIME());
		$this->set(1, 2, 0, VanillaBlocks::STONE());
		$this->set(1, 1, 1, VanillaBlocks::STONE());
		$this->set(1, 1, -1, VanillaBlocks::GLAZED_TERRACOTTA()); //doesn't stick
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::SLIME, 2, 1, 0);
		$this->assertType(BlockTypeIds::STONE, 2, 2, 0);
		$this->assertType(BlockTypeIds::STONE, 2, 1, 1);
		$this->assertType(BlockTypeIds::AIR, 1, 2, 0);
		$this->assertType(BlockTypeIds::GLAZED_TERRACOTTA, 1, 1, -1);

		$this->powerAndMove(false);
		$this->assertType(BlockTypeIds::SLIME, 1, 1, 0);
		$this->assertType(BlockTypeIds::STONE, 1, 2, 0);
		$this->assertType(BlockTypeIds::STONE, 1, 1, 1);
	}

	public function testSlimeAndHoneyDontStick() : void{
		//no floor: slime would stick to it
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::SLIME());
		$this->set(1, 2, 0, VanillaBlocks::HONEY_BLOCK());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::SLIME, 2, 1, 0);
		$this->assertType(BlockTypeIds::HONEY_BLOCK, 1, 2, 0);
	}

	private function buildSlimeGroup() : void{
		//no floor: slime would stick to it
		$this->placePiston(0, 1, 0, Facing::EAST);
		for($x = 1; $x <= 6; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::SLIME());
			$this->set($x, 2, 0, VanillaBlocks::STONE());
		}
		$this->set(1, 3, 0, VanillaBlocks::STONE()); //not stuck to slime: neither moved nor counted
	}

	public function testSlimeGroupOfTwelveMoves() : void{
		$this->buildSlimeGroup();
		$this->powerAndMove();
		self::assertSame(PistonArm::STATE_EXTENDED, $this->arm(0, 1, 0)->getState());
		$this->assertType(BlockTypeIds::STONE, 7, 2, 0);
		$this->assertType(BlockTypeIds::STONE, 1, 3, 0);
	}

	public function testSlimeGroupOfThirteenIsBlocked() : void{
		$this->buildSlimeGroup();
		$this->set(3, 1, 1, VanillaBlocks::STONE()); //13th block, stuck to a slime block
		$this->powerAndMove();
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
		$this->assertType(BlockTypeIds::SLIME, 1, 1, 0);
	}

	public function testGlazedTerracottaIsNotPulled() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST, true);
		$this->set(1, 1, 0, VanillaBlocks::GLAZED_TERRACOTTA());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::GLAZED_TERRACOTTA, 2, 1, 0);
		$this->powerAndMove(false);
		$this->assertType(BlockTypeIds::GLAZED_TERRACOTTA, 2, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 1, 1, 0);
	}

	public function testVerticalPush() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::UP);
		$this->set(0, 2, 0, VanillaBlocks::STONE());
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::PISTON_HEAD, 0, 2, 0);
		$this->assertType(BlockTypeIds::STONE, 0, 3, 0);
	}

	public function testChestIsMovedWithItsContents() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::CHEST());
		$chest = $this->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(TileChest::class, $chest);
		$chest->getInventory()->setItem(3, VanillaItems::DIAMOND()->setCount(5));
		$this->powerAndMove();

		$this->assertType(BlockTypeIds::CHEST, 2, 1, 0);
		$moved = $this->world->getTileAt(2, 1, 0);
		self::assertInstanceOf(TileChest::class, $moved);
		self::assertTrue(VanillaItems::DIAMOND()->setCount(5)->equalsExact($moved->getInventory()->getItem(3)));
		self::assertNull($this->world->getTileAt(1, 1, 0));
	}

	public function testExtendedPistonIsImmovable() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->powerAndMove();
		$this->placePiston(0, 1, 1, Facing::NORTH);
		$this->set(-1, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(8);
		//the second piston can't push the extended first one
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 1)->getState());
		$this->assertType(BlockTypeIds::PISTON, 0, 1, 0);
	}

	public function testRetractedPistonIsPushed() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->placePiston(1, 1, 0, Facing::UP, true);
		$this->powerAndMove();
		$this->assertType(BlockTypeIds::STICKY_PISTON, 2, 1, 0);
		$moved = $this->get(2, 1, 0);
		self::assertInstanceOf(Piston::class, $moved);
		self::assertSame(Facing::UP, $moved->getFacing());
		self::assertTrue($this->arm(2, 1, 0)->isSticky());
	}

	public function testBreakingHeadBreaksPiston() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->powerAndMove();
		$this->breakBlock(1, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 1, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 0, 1, 0);
	}

	public function testNotPoweredFromFront() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::WEST);
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(8);
		self::assertSame(PistonArm::STATE_RETRACTED, $this->arm(0, 1, 0)->getState());
	}

	public function testPoweredBySideWire() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		//wire running north-south next to the piston: it doesn't point into the piston, but Bedrock still powers it
		$this->set(-1, 1, -1, VanillaBlocks::REDSTONE());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(-1, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(8);
		self::assertSame(PistonArm::STATE_EXTENDED, $this->arm(0, 1, 0)->getState());
	}

	public function testItemEntityIsPushed() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$item = $this->world->dropItem(new Vector3(1.5, 1.1, 0.5), VanillaItems::DIAMOND(), new Vector3(0, 0, 0));
		self::assertNotNull($item);
		$this->powerAndMove();
		self::assertGreaterThan(1.9, $item->getPosition()->x);
	}

	public function testArmNbtRoundTrip() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST, true);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		$arm = $this->arm(0, 1, 0);
		self::assertTrue($arm->isMoving());

		$nbt = $arm->saveNBT();
		self::assertSame(1, $nbt->getByte("State"));
		self::assertSame(1, $nbt->getByte("Sticky"));
		self::assertSame([1, 1, 0], array_map(fn($t) => $t->getValue(), $nbt->getListTag("AttachedBlocks")?->getValue() ?? []));
		$copy = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(PistonArm::class, $copy);
		self::assertSame(PistonArm::STATE_EXTENDING, $copy->getState());
		self::assertEquals([new Vector3(1, 1, 0)], $copy->getAttachedBlocks());

		$moving = $this->world->getTileAt(2, 1, 0);
		self::assertInstanceOf(TileMovingBlock::class, $moving);
		$movingNbt = $moving->saveNBT();
		self::assertSame("minecraft:stone", $movingNbt->getCompoundTag("movingBlock")?->getString("name"));
		self::assertSame(0, $movingNbt->getInt("pistonPosX"));
		$movingCopy = TileFactory::getInstance()->createFromData($this->world, $movingNbt);
		self::assertInstanceOf(TileMovingBlock::class, $movingCopy);
		self::assertSame(BlockTypeIds::STONE, $movingCopy->getMovingBlock()?->getTypeId());

		//network data
		$armSpawn = $arm->getSpawnCompound();
		self::assertSame("PistonArm", $armSpawn->getString("id"));
		self::assertSame(0.0, $armSpawn->getFloat("Progress"));
		self::assertNotSame("", $arm->getSerializedSpawnCompound()->getEncodedNbt());
		$movingSpawn = $moving->getSpawnCompound();
		self::assertSame("MovingBlock", $movingSpawn->getString("id"));
		self::assertSame("minecraft:stone", $movingSpawn->getCompoundTag("movingBlock")?->getString("name"));
		self::assertSame("minecraft:air", $movingSpawn->getCompoundTag("movingBlockExtra")?->getString("name"));
		$packets = $this->world->createBlockUpdatePackets([new Vector3(0, 1, 0), new Vector3(1, 1, 0), new Vector3(2, 1, 0)]);
		self::assertCount(5, $packets); //3 block updates, 2 block actor data (piston and moving block)
	}

	public function testOrphanMovingBlockIsResolved() : void{
		$this->floor(-2, -2, 16, 2);
		$this->set(5, 1, 0, VanillaBlocks::MOVING_BLOCK());
		$tile = $this->world->getTileAt(5, 1, 0);
		self::assertInstanceOf(TileMovingBlock::class, $tile);
		$tile->setMovement(VanillaBlocks::GOLD(), new Vector3(3, 1, 0), true, null, null);
		$this->runTicks(2);
		$this->assertType(BlockTypeIds::GOLD, 5, 1, 0);
	}

	public function testPistonRemovedMidMove() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		$this->assertType(BlockTypeIds::MOVING_BLOCK, 2, 1, 0);
		$this->set(0, 1, 0, VanillaBlocks::AIR()); //like an explosion: no break handling
		$this->runTicks(3);
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 1, 1, 0); //the head is gone with its piston
	}

	public function testBreakingPistonMidMoveFinishesIt() : void{
		$this->floor(-2, -2, 16, 2);
		$this->placePiston(0, 1, 0, Facing::EAST);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		$this->breakBlock(0, 1, 0);
		$this->assertType(BlockTypeIds::STONE, 2, 1, 0);
		$this->assertType(BlockTypeIds::AIR, 0, 1, 0);
		$this->runTicks(2);
		$this->assertType(BlockTypeIds::AIR, 1, 1, 0);
	}

	public function testPushReactionDefaults() : void{
		$cases = [
			[VanillaBlocks::STONE(), "NORMAL"],
			[VanillaBlocks::CHEST(), "NORMAL"],
			[VanillaBlocks::FURNACE(), "NORMAL"],
			[VanillaBlocks::OBSIDIAN(), "BLOCK"],
			[VanillaBlocks::ENDER_CHEST(), "BLOCK"],
			[VanillaBlocks::OAK_LEAVES(), "BREAK"],
			[VanillaBlocks::TORCH(), "BREAK"],
			[VanillaBlocks::SHULKER_BOX(), "BREAK"],
			[VanillaBlocks::GLAZED_TERRACOTTA(), "PUSH_ONLY"],
			[VanillaBlocks::END_ROD(), "NORMAL"],
		];
		foreach($cases as [$block, $expected]){
			self::assertSame($expected, $block->getPistonPushReaction()->name, $block->getName());
		}
	}
}
