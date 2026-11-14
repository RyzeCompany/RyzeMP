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

use pocketmine\block\utils\DripstoneThickness;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Pointed dripstone dripping water and lava into cauldrons. The world is not ticked: drops are let fall with a fixed
 * random value and land by calling the scheduled update.
 */
final class PointedDripstoneDripTest extends RedstoneTestCase{
	private const ROOT_Y = 30;
	private const CAULDRON_Y = 24;

	/**
	 * Builds a stone ceiling with the given fluid above it and a stalactite of the given length below it, and
	 * returns the top block of the stalactite.
	 */
	private function buildStalactite(Block $fluid, int $length = 2) : PointedDripstone{
		$this->set(0, self::ROOT_Y, 0, VanillaBlocks::STONE());
		$this->set(0, self::ROOT_Y + 1, 0, $fluid);
		for($i = 1; $i <= $length; ++$i){
			$thickness = $i === $length ? DripstoneThickness::TIP : ($i === $length - 1 ? DripstoneThickness::FRUSTUM : DripstoneThickness::BASE);
			$this->set(0, self::ROOT_Y - $i, 0, VanillaBlocks::POINTED_DRIPSTONE()->setHanging(true)->setThickness($thickness));
		}
		$top = $this->get(0, self::ROOT_Y - 1, 0);
		self::assertInstanceOf(PointedDripstone::class, $top);
		return $top;
	}

	public function testOnlyStalactitesTickRandomly() : void{
		self::assertTrue(VanillaBlocks::POINTED_DRIPSTONE()->setHanging(true)->ticksRandomly());
		self::assertFalse(VanillaBlocks::POINTED_DRIPSTONE()->setHanging(false)->ticksRandomly());
		self::assertTrue(VanillaBlocks::SULFUR_SPIKE()->setHanging(true)->ticksRandomly(), "sulfur spikes grow");
	}

	public function testFluidAboveTheRootBlock() : void{
		$top = $this->buildStalactite(VanillaBlocks::WATER(), 3);
		self::assertTrue($top->isStalactiteStart());
		self::assertSame(PointedDripstone::FLUID_WATER, $top->getFluidAboveStalactite()[0]);
		$tip = $top->findTip();
		self::assertNotNull($tip);
		self::assertSame(self::ROOT_Y - 3, $tip->getPosition()->getFloorY());

		$this->set(0, self::ROOT_Y + 1, 0, VanillaBlocks::LAVA());
		self::assertSame(PointedDripstone::FLUID_LAVA, $top->getFluidAboveStalactite()[0]);
		$this->set(0, self::ROOT_Y + 1, 0, VanillaBlocks::WATER()->setDecay(3));
		self::assertSame(PointedDripstone::FLUID_NONE, $top->getFluidAboveStalactite()[0], "flowing water doesn't drip");
	}

	public function testWaterFillsACauldronGradually() : void{
		$top = $this->buildStalactite(VanillaBlocks::WATER());
		$this->set(0, self::CAULDRON_Y, 0, VanillaBlocks::CAULDRON());

		self::assertFalse($top->maybeTransferFluid(0.5), "random value above the water chance");
		self::assertTrue($top->maybeTransferFluid(0.1));
		self::assertTrue($this->world->isBlockUpdateScheduled(0, self::ROOT_Y - 1, 0), "the drop lands later");
		self::assertInstanceOf(Cauldron::class, $this->get(0, self::CAULDRON_Y, 0));

		$top->onScheduledUpdate();
		$cauldron = $this->get(0, self::CAULDRON_Y, 0);
		self::assertInstanceOf(WaterCauldron::class, $cauldron);
		self::assertSame(PointedDripstone::WATER_DRIP_FILL_AMOUNT, $cauldron->getFillLevel());

		for($i = 0; $i < 5; ++$i){
			$top->onScheduledUpdate();
		}
		$cauldron = $this->get(0, self::CAULDRON_Y, 0);
		self::assertInstanceOf(WaterCauldron::class, $cauldron);
		self::assertSame(FillableCauldron::MAX_FILL_LEVEL, $cauldron->getFillLevel());
		self::assertFalse($top->maybeTransferFluid(0.1), "a full cauldron takes no more water");
	}

	public function testLavaFillsACauldronAtOnce() : void{
		$top = $this->buildStalactite(VanillaBlocks::LAVA(), 1);
		$this->set(0, self::CAULDRON_Y, 0, VanillaBlocks::CAULDRON());
		self::assertFalse($top->maybeTransferFluid(0.1), "lava drips less often than water");
		self::assertTrue($top->maybeTransferFluid(0.05));
		$top->onScheduledUpdate();
		$cauldron = $this->get(0, self::CAULDRON_Y, 0);
		self::assertInstanceOf(LavaCauldron::class, $cauldron);
		self::assertSame(FillableCauldron::MAX_FILL_LEVEL, $cauldron->getFillLevel());
	}

	public function testLavaDoesNotGoIntoAWaterCauldron() : void{
		$top = $this->buildStalactite(VanillaBlocks::LAVA());
		$this->set(0, self::CAULDRON_Y, 0, VanillaBlocks::WATER_CAULDRON()->setFillLevel(2));
		self::assertFalse($top->maybeTransferFluid(0.05));
	}

	public function testBlockedDrop() : void{
		$top = $this->buildStalactite(VanillaBlocks::WATER());
		$this->set(0, self::CAULDRON_Y, 0, VanillaBlocks::CAULDRON());
		$this->set(0, self::CAULDRON_Y + 2, 0, VanillaBlocks::STONE());
		self::assertFalse($top->maybeTransferFluid(0.1));

		$this->set(0, self::CAULDRON_Y + 2, 0, VanillaBlocks::OAK_FENCE());
		self::assertFalse($top->maybeTransferFluid(0.1), "fences block the central column");

		$this->set(0, self::CAULDRON_Y + 2, 0, VanillaBlocks::TORCH());
		self::assertTrue($top->maybeTransferFluid(0.1), "torches let drops through");
	}

	public function testTooFarBelow() : void{
		$top = $this->buildStalactite(VanillaBlocks::WATER(), 1);
		$this->set(0, self::ROOT_Y - 1 - PointedDripstone::MAX_DRIP_SEARCH_LENGTH, 0, VanillaBlocks::CAULDRON());
		self::assertFalse($top->maybeTransferFluid(0.1));
	}

	public function testMudDriesIntoClay() : void{
		$top = $this->buildStalactite(VanillaBlocks::MUD());
		self::assertTrue($top->maybeTransferFluid(0.1));
		self::assertSame(BlockTypeIds::CLAY, $this->get(0, self::ROOT_Y + 1, 0)->getTypeId());
	}

	public function testOnlyTheTopBlockDrips() : void{
		$this->buildStalactite(VanillaBlocks::WATER(), 3);
		$this->set(0, self::CAULDRON_Y, 0, VanillaBlocks::CAULDRON());
		$middle = $this->get(0, self::ROOT_Y - 2, 0);
		self::assertInstanceOf(PointedDripstone::class, $middle);
		self::assertFalse($middle->maybeTransferFluid(0.1));
	}
}
