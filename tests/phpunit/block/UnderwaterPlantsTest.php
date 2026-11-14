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

use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\format\SubChunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;

/**
 * Kelp, seagrass and sea pickles under water: the liquid layer, water flow and growth.
 */
class UnderwaterPlantsTest extends RedstoneTestCase{

	private function liquidLayer(int $x, int $y, int $z) : int{
		$chunk = $this->world->getChunk($x >> 4, $z >> 4);
		self::assertNotNull($chunk);
		return $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf);
	}

	/**
	 * A pool of water sources 1 deep on a stone floor.
	 */
	private function pool(int $minX, int $minZ, int $maxX, int $maxZ) : void{
		$this->floor($minX - 1, $minZ - 1, $maxX + 1, $maxZ + 1);
		for($x = $minX - 1; $x <= $maxX + 1; ++$x){
			for($z = $minZ - 1; $z <= $maxZ + 1; ++$z){
				$inside = $x >= $minX && $x <= $maxX && $z >= $minZ && $z <= $maxZ;
				for($y = 1; $y <= 3; ++$y){
					$this->world->setBlockAt($x, $y, $z, $inside ? VanillaBlocks::WATER() : VanillaBlocks::STONE(), false);
				}
			}
		}
	}

	public function testLiquidLayerFollowsWaterloggedBlocks() : void{
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		$this->floor(0, 0, 0, 0);
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));

		$this->set(0, 1, 0, VanillaBlocks::KELP()->setAge(3));
		self::assertSame($water, $this->liquidLayer(0, 1, 0));

		//a state change of the same block keeps the water
		$this->set(0, 1, 0, VanillaBlocks::KELP()->setAge(4));
		self::assertSame($water, $this->liquidLayer(0, 1, 0));

		$this->set(0, 1, 0, VanillaBlocks::STONE());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));

		//dry sea pickles have no water, wet ones do
		$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(false));
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));
		$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(true));
		self::assertSame($water, $this->liquidLayer(0, 1, 0));
		$this->set(0, 1, 0, VanillaBlocks::SEA_PICKLE()->setUnderwater(false));
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));
	}

	public function testBlockUpdatesIncludeLiquidLayer() : void{
		$this->floor(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::SEAGRASS());
		$packets = $this->world->createBlockUpdatePackets([new Vector3(0, 1, 0)]);
		$liquid = array_filter($packets, fn($p) => $p instanceof UpdateBlockPacket && $p->dataLayerId === UpdateBlockPacket::DATA_LAYER_LIQUID);
		self::assertCount(1, $liquid);

		//subchunks without a liquid layer don't get the extra packet
		$this->set(0, 40, 0, VanillaBlocks::STONE());
		$packets = $this->world->createBlockUpdatePackets([new Vector3(0, 40, 0)]);
		self::assertCount(1, $packets);
	}

	public function testSubChunkKeepsEmptyLayerZeroUnderLiquidLayer() : void{
		$subChunk = new SubChunk(Block::EMPTY_STATE_ID, [], new \pocketmine\world\format\PalettedBlockArray(0));
		$subChunk->setLiquidLayerStateId(1, 2, 3, VanillaBlocks::WATER()->getStateId());
		$subChunk->collectGarbage();
		self::assertCount(2, $subChunk->getBlockLayers());
		self::assertSame(Block::EMPTY_STATE_ID, $subChunk->getBlockStateId(1, 2, 3));
		self::assertSame(VanillaBlocks::WATER()->getStateId(), $subChunk->getLiquidLayerStateId(1, 2, 3));

		$subChunk->setLiquidLayerStateId(1, 2, 3, Block::EMPTY_STATE_ID);
		$subChunk->collectGarbage();
		self::assertCount(0, $subChunk->getBlockLayers());
	}

	public function testBrokenKelpColumnLeavesWater() : void{
		$this->pool(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::KELP());
		$this->set(0, 2, 0, VanillaBlocks::KELP());
		$this->set(0, 3, 0, VanillaBlocks::KELP());
		$this->runTicks(5);

		$this->breakBlock(0, 1, 0);
		$this->runTicks(5);
		for($y = 1; $y <= 3; ++$y){
			$block = $this->get(0, $y, 0);
			self::assertInstanceOf(Water::class, $block, "y=$y");
			self::assertTrue($block->isSource(), "y=$y");
			self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, $y, 0));
		}
	}

	public function testKelpNeedsWaterSourceAndSupport() : void{
		$this->pool(0, 0, 0, 0);
		$kelp = VanillaBlocks::KELP();
		$click = new Vector3(0.5, 0.5, 0.5);
		self::assertTrue($kelp->canBePlacedAt($this->get(0, 1, 0), $click, 1, false));
		self::assertFalse($kelp->canBePlacedAt($this->get(0, 5, 0), $click, 1, false), "air");

		$this->set(0, 1, 0, VanillaBlocks::WATER()->setDecay(3));
		self::assertFalse($kelp->canBePlacedAt($this->get(0, 1, 0), $click, 1, false), "flowing water");

		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(0, 0, 0, VanillaBlocks::MAGMA());
		self::assertFalse($kelp->canBePlacedAt($this->get(0, 1, 0), $click, 1, false), "magma below");
	}

	public function testWaterflowsOutOfKelpAndStays() : void{
		$this->floor(-8, -8, 8, 8);
		$this->set(0, 1, 0, VanillaBlocks::KELP());
		//poke the kelp so it spreads its water
		$this->set(0, 2, 0, VanillaBlocks::STONE());
		$this->set(0, 2, 0, VanillaBlocks::AIR());
		$this->runTicks(60);

		$side = $this->get(1, 1, 0);
		self::assertInstanceOf(Water::class, $side);
		self::assertFalse($side->isSource());
		self::assertSame(1, $side->getDecay());
		self::assertInstanceOf(Kelp::class, $this->get(0, 1, 0));

		//water next to two waterlogged blocks becomes a source
		$this->set(2, 1, 0, VanillaBlocks::SEAGRASS());
		$this->runTicks(60);
		$between = $this->get(1, 1, 0);
		self::assertInstanceOf(Water::class, $between);
		self::assertTrue($between->isSource());
	}

	public function testKelpGrowsWithBoneMeal() : void{
		$this->pool(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::KELP()->setAge(5));
		$this->get(0, 1, 0)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5));
		$top = $this->get(0, 2, 0);
		self::assertInstanceOf(Kelp::class, $top);
		self::assertSame(6, $top->getAge());

		//bone meal on the bottom grows the top of the column
		$this->get(0, 1, 0)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5));
		self::assertInstanceOf(Kelp::class, $this->get(0, 3, 0));

		//no water above: no growth (y=4 is air)
		$this->get(0, 1, 0)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5));
		self::assertNotInstanceOf(Kelp::class, $this->get(0, 4, 0));
	}

	public function testKelpRandomGrowthStopsAtMaxAge() : void{
		$this->pool(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::KELP()->setAge(Kelp::MAX_AGE));
		for($i = 0; $i < 200; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		self::assertInstanceOf(Water::class, $this->get(0, 2, 0));

		$this->set(0, 1, 0, VanillaBlocks::KELP()->setAge(Kelp::MAX_AGE - 1));
		for($i = 0; $i < 500 && !$this->get(0, 2, 0) instanceof Kelp; ++$i){
			$this->get(0, 1, 0)->onRandomTick();
		}
		$grown = $this->get(0, 2, 0);
		self::assertInstanceOf(Kelp::class, $grown);
		self::assertSame(Kelp::MAX_AGE, $grown->getAge());
	}

	public function testSeagrassBoneMealAndTallSeagrassBreaking() : void{
		$this->pool(0, 0, 0, 0);
		$this->set(0, 1, 0, VanillaBlocks::SEAGRASS());
		$this->get(0, 1, 0)->onInteract(VanillaItems::BONE_MEAL(), 1, new Vector3(0.5, 0.5, 0.5));
		$bottom = $this->get(0, 1, 0);
		$top = $this->get(0, 2, 0);
		self::assertInstanceOf(TallSeagrass::class, $bottom);
		self::assertInstanceOf(TallSeagrass::class, $top);
		self::assertFalse($bottom->isTop());
		self::assertTrue($top->isTop());

		$drops = $top->getDrops(VanillaItems::SHEARS());
		self::assertCount(1, $drops);
		self::assertSame(2, $drops[0]->getCount());
		self::assertCount(0, $top->getDrops(VanillaItems::AIR()));

		$this->breakBlock(0, 2, 0);
		self::assertInstanceOf(Water::class, $this->get(0, 1, 0));
		self::assertInstanceOf(Water::class, $this->get(0, 2, 0));
	}

	public function testUnderwaterSeaPickle() : void{
		$this->pool(0, 0, 0, 0);
		$pickle = VanillaBlocks::SEA_PICKLE()->setUnderwater(true)->setCount(2);
		$this->set(0, 1, 0, $pickle);
		self::assertSame(9, $this->get(0, 1, 0)->getLightLevel());
		$this->breakBlock(0, 1, 0);
		$water = $this->get(0, 1, 0);
		self::assertInstanceOf(Water::class, $water);
		self::assertTrue($water->isSource());
		self::assertSame(Block::EMPTY_STATE_ID, $this->liquidLayer(0, 1, 0));
	}
}
