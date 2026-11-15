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

use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\populator\BubbleColumns;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Bubble columns over soul sand and magma blocks: creation, propagation, removal, entity push and air.
 */
class BubbleColumnTest extends RedstoneTestCase{
	private const TOP = 6;

	private function liquidLayer(int $x, int $y, int $z) : int{
		$chunk = $this->world->getChunk($x >> 4, $z >> 4);
		self::assertNotNull($chunk);
		return $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf);
	}

	/**
	 * A 1x1 shaft of water sources from y=1 to TOP, walled with stone, on a stone floor.
	 */
	private function shaft() : void{
		$this->floor(-1, -1, 1, 1);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				for($y = 1; $y <= self::TOP; ++$y){
					$this->world->setBlockAt($x, $y, $z, $x === 0 && $z === 0 ? VanillaBlocks::WATER() : VanillaBlocks::STONE(), false);
				}
			}
		}
	}

	private function assertColumn(int $fromY, int $toY, bool $dragDown) : void{
		for($y = $fromY; $y <= $toY; ++$y){
			$block = $this->get(0, $y, 0);
			self::assertInstanceOf(BubbleColumn::class, $block, "y=$y");
			self::assertSame($dragDown, $block->isDragDown(), "y=$y");
			self::assertSame(VanillaBlocks::WATER()->getStillForm()->getStateId(), $this->liquidLayer(0, $y, 0), "liquid layer y=$y");
		}
	}

	private function assertWaterSources(int $fromY, int $toY) : void{
		for($y = $fromY; $y <= $toY; ++$y){
			$block = $this->get(0, $y, 0);
			self::assertInstanceOf(Water::class, $block, "y=$y");
			self::assertTrue($block->isSource(), "y=$y");
			self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, $y, 0), "liquid layer y=$y");
		}
	}

	public function testSoulSandMakesUpwardColumnThroughAllSources() : void{
		$this->shaft();
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);
		$this->assertColumn(1, self::TOP, false);
		self::assertInstanceOf(Air::class, $this->get(0, self::TOP + 1, 0));
	}

	public function testMagmaMakesWhirlpoolAndColumnFollowsTheBlockBelow() : void{
		$this->shaft();
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$this->runTicks(10);
		$this->assertColumn(1, self::TOP, true);

		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);
		$this->assertColumn(1, self::TOP, false);

		$this->set(0, 0, 0, VanillaBlocks::STONE());
		$this->runTicks(10);
		$this->assertWaterSources(1, self::TOP);
	}

	public function testWaterPouredOverSoulSandBecomesColumn() : void{
		$this->floor(-1, -1, 1, 1);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				if($x !== 0 || $z !== 0){
					$this->world->setBlockAt($x, 1, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		$this->world->setBlockAt(0, 0, 0, VanillaBlocks::SOUL_SAND(), false);
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->runTicks(10);
		$this->assertColumn(1, 1, false);
	}

	public function testColumnStopsAtWaterloggedAndFlowingWater() : void{
		self::assertFalse(BubbleColumn::canOccupy(VanillaBlocks::KELP()), "waterlogged blocks stop the column");
		self::assertFalse(BubbleColumn::canOccupy(VanillaBlocks::WATER()->setDecay(2)));
		self::assertFalse(BubbleColumn::canOccupy(VanillaBlocks::WATER()->setFalling(true)));
		self::assertTrue(BubbleColumn::canOccupy(VanillaBlocks::WATER()->getStillForm()));

		$this->shaft();
		$this->world->setBlockAt(0, 3, 0, VanillaBlocks::WATER()->setDecay(2), false);
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);
		$this->assertColumn(1, 2, false);
		self::assertNotInstanceOf(BubbleColumn::class, $this->get(0, 3, 0));
		self::assertNotInstanceOf(BubbleColumn::class, $this->get(0, 4, 0));
	}

	public function testBlockInsideColumnCutsItAndColumnRegrowsWhenRemoved() : void{
		$this->shaft();
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);

		$this->set(0, 3, 0, VanillaBlocks::STONE());
		$this->runTicks(10);
		$this->assertColumn(1, 2, false);
		$this->assertWaterSources(4, self::TOP);

		//a water source back in the gap: the column below extends through it and everything above
		$this->set(0, 3, 0, VanillaBlocks::WATER());
		$this->runTicks(10);
		$this->assertColumn(1, self::TOP, false);
	}

	public function testRandomTickBuildsMissingColumn() : void{
		$this->shaft();
		//placed without updates, as in chunks generated before bubble columns existed
		$this->world->setBlockAt(0, 0, 0, VanillaBlocks::MAGMA(), false);
		$this->runTicks(10);
		self::assertInstanceOf(Water::class, $this->get(0, 1, 0));

		$magma = $this->get(0, 0, 0);
		self::assertTrue($magma->ticksRandomly());
		$magma->onRandomTick();
		$this->assertColumn(1, self::TOP, true);
	}

	public function testPopulatorFillsGeneratedChunk() : void{
		$chunk = new Chunk([], false);
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		$chunk->setBlockStateId(3, 10, 4, VanillaBlocks::MAGMA()->getStateId());
		$chunk->setBlockStateId(7, 30, 8, VanillaBlocks::SOUL_SAND()->getStateId());
		for($y = 11; $y <= 40; ++$y){
			$chunk->setBlockStateId(3, $y, 4, $water);
			if($y >= 31){
				$chunk->setBlockStateId(7, $y, 8, $water);
			}
		}
		//a stone block cuts the whirlpool at y=20
		$chunk->setBlockStateId(3, 20, 4, VanillaBlocks::STONE()->getStateId());

		self::assertSame(9 + 10, BubbleColumns::fillChunk($chunk, -64, 320));
		$down = VanillaBlocks::BUBBLE_COLUMN()->setDragDown(true)->getStateId();
		$up = VanillaBlocks::BUBBLE_COLUMN()->setDragDown(false)->getStateId();
		self::assertSame($down, $chunk->getBlockStateId(3, 11, 4));
		self::assertSame($down, $chunk->getBlockStateId(3, 19, 4));
		self::assertSame($water, $chunk->getBlockStateId(3, 21, 4));
		self::assertSame($water, $chunk->getSubChunk(1)->getLiquidLayerStateId(3, 19 & 0xf, 4));
		self::assertSame($up, $chunk->getBlockStateId(7, 31, 8));
		self::assertSame($up, $chunk->getBlockStateId(7, 40, 8));
		self::assertSame(Block::EMPTY_STATE_ID, $chunk->getBlockStateId(7, 41, 8));
	}

	public function testPushVelocities() : void{
		//inside an upward column entities accelerate up to 0.7 blocks/tick, at the top up to 1.8
		self::assertEqualsWithDelta(0.06, BubbleColumn::pushedVerticalSpeed(0.0, false, false), 1e-9);
		self::assertEqualsWithDelta(0.7, BubbleColumn::pushedVerticalSpeed(0.69, false, false), 1e-9);
		self::assertEqualsWithDelta(0.1, BubbleColumn::pushedVerticalSpeed(0.0, false, true), 1e-9);
		self::assertEqualsWithDelta(1.8, BubbleColumn::pushedVerticalSpeed(1.75, false, true), 1e-9);
		//whirlpools drag down to 0.3 blocks/tick, 0.9 at the top
		self::assertEqualsWithDelta(-0.03, BubbleColumn::pushedVerticalSpeed(0.0, true, false), 1e-9);
		self::assertEqualsWithDelta(-0.3, BubbleColumn::pushedVerticalSpeed(-0.29, true, false), 1e-9);
		self::assertEqualsWithDelta(-0.9, BubbleColumn::pushedVerticalSpeed(-0.89, true, true), 1e-9);
	}

	public function testItemsArePushedUpAndDragged() : void{
		$this->shaft();
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);

		$item = new ItemEntity(Location::fromObject(new Vector3(0.5, 2.2, 0.5), $this->world), VanillaItems::STICK());
		$column = $this->get(0, 2, 0);
		self::assertInstanceOf(BubbleColumn::class, $column);
		$column->onEntityInside($item);
		self::assertEqualsWithDelta(0.06, $item->getMotion()->y, 1e-9);
		//top block: nothing above, stronger push
		$top = $this->get(0, self::TOP, 0);
		$top->onEntityInside($item);
		self::assertEqualsWithDelta(0.16, $item->getMotion()->y, 1e-9);

		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		$this->runTicks(10);
		$item->setMotion(new Vector3(0, 0, 0));
		$this->get(0, 2, 0)->onEntityInside($item);
		self::assertEqualsWithDelta(-0.03, $item->getMotion()->y, 1e-9);
		$item->close();
	}

	public function testMobsBreatheInsideColumn() : void{
		$this->shaft();
		//a cow: zombies breathe underwater
		$zombie = new Cow(Location::fromObject(new Vector3(0.5, 2, 0.5), $this->world));
		self::assertTrue($zombie->isUnderwater());
		self::assertFalse($zombie->canBreathe(), "plain water");

		$this->set(0, 0, 0, VanillaBlocks::SOUL_SAND());
		$this->runTicks(10);
		self::assertTrue($zombie->isUnderwater());
		self::assertTrue($zombie->canBreathe(), "bubble column");
		$zombie->close();
	}

	public function testSerializesAsVanillaState() : void{
		$data = GlobalBlockStateHandlers::getSerializer()->serialize(VanillaBlocks::BUBBLE_COLUMN()->setDragDown(true)->getStateId());
		self::assertSame(BlockTypeNames::BUBBLE_COLUMN, $data->getName());
		self::assertSame(1, $data->getState(BlockStateNames::DRAG_DOWN)?->getValue());
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId(GlobalBlockStateHandlers::getDeserializer()->deserialize($data));
		self::assertInstanceOf(BubbleColumn::class, $block);
		self::assertTrue($block->isDragDown());
	}
}
