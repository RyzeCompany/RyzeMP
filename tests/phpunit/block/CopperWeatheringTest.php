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

use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\world\redstone\RedstoneTestCase;
use function mt_srand;

final class CopperWeatheringTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false]];
	}

	/**
	 * Calls onRandomTick() on the block at the position up to $ticks times, until its oxidation changes.
	 */
	private function randomTickUntilChange(int $x, int $y, int $z, int $ticks) : CopperOxidation{
		$block = $this->get($x, $y, $z);
		self::assertInstanceOf(CopperMaterial::class, $block);
		$start = $block->getOxidation();
		for($i = 0; $i < $ticks; ++$i){
			$block = $this->get($x, $y, $z);
			$block->onRandomTick();
			$block = $this->get($x, $y, $z);
			self::assertInstanceOf(CopperMaterial::class, $block);
			if($block->getOxidation() !== $start){
				return $block->getOxidation();
			}
		}
		return $start;
	}

	public function testOnlyUnwaxedNotFullyOxidizedCopperTicks() : void{
		$ticked = $this->world->getRandomTickedBlocks();
		$blocks = [
			VanillaBlocks::COPPER(),
			VanillaBlocks::CUT_COPPER(),
			VanillaBlocks::CHISELED_COPPER(),
			VanillaBlocks::COPPER_GRATE(),
			VanillaBlocks::CUT_COPPER_SLAB(),
			VanillaBlocks::CUT_COPPER_STAIRS(),
			VanillaBlocks::COPPER_BULB(),
			VanillaBlocks::COPPER_TRAPDOOR(),
			VanillaBlocks::COPPER_BARS(),
			VanillaBlocks::COPPER_CHAIN(),
			VanillaBlocks::COPPER_LANTERN(),
			VanillaBlocks::LIGHTNING_ROD(),
			VanillaBlocks::COPPER_CHEST(),
			VanillaBlocks::COPPER_GOLEM_STATUE(),
		];
		foreach($blocks as $block){
			self::assertArrayHasKey((clone $block)->setOxidation(CopperOxidation::NONE)->getStateId(), $ticked, $block->getName());
			self::assertArrayHasKey((clone $block)->setOxidation(CopperOxidation::WEATHERED)->getStateId(), $ticked, $block->getName());
			self::assertArrayNotHasKey((clone $block)->setOxidation(CopperOxidation::OXIDIZED)->getStateId(), $ticked, $block->getName() . " fully oxidized");
			self::assertArrayNotHasKey((clone $block)->setWaxed(true)->getStateId(), $ticked, $block->getName() . " waxed");
		}
		$door = VanillaBlocks::COPPER_DOOR();
		self::assertArrayHasKey($door->getStateId(), $ticked);
		self::assertArrayNotHasKey((clone $door)->setTop(true)->getStateId(), $ticked, "the bottom half weathers for both");
	}

	public function testLoneCopperWeathers() : void{
		mt_srand(1);
		$this->set(0, 1, 0, VanillaBlocks::COPPER());
		self::assertSame(CopperOxidation::EXPOSED, $this->randomTickUntilChange(0, 1, 0, 2000));
	}

	public function testLessOxidizedNeighbourBlocksWeathering() : void{
		mt_srand(2);
		$this->set(0, 1, 0, VanillaBlocks::COPPER()->setOxidation(CopperOxidation::EXPOSED));
		$this->set(3, 1, 1, VanillaBlocks::CUT_COPPER()); //Manhattan distance 4, unoxidized
		self::assertSame(CopperOxidation::EXPOSED, $this->randomTickUntilChange(0, 1, 0, 2000));

		//a waxed neighbour does not count
		$this->set(3, 1, 1, VanillaBlocks::CUT_COPPER()->setWaxed(true));
		self::assertSame(CopperOxidation::WEATHERED, $this->randomTickUntilChange(0, 1, 0, 2000));
	}

	public function testWaxedCopperNeverWeathers() : void{
		mt_srand(3);
		$this->set(0, 1, 0, VanillaBlocks::COPPER()->setWaxed(true));
		self::assertSame(CopperOxidation::NONE, $this->randomTickUntilChange(0, 1, 0, 2000));
	}

	public function testDoorHalvesWeatherTogether() : void{
		mt_srand(4);
		$door = VanillaBlocks::COPPER_DOOR();
		$this->set(0, 1, 0, $door);
		$this->set(0, 2, 0, (clone $door)->setTop(true));
		self::assertSame(CopperOxidation::EXPOSED, $this->randomTickUntilChange(0, 1, 0, 2000));
		$top = $this->get(0, 2, 0);
		self::assertInstanceOf(CopperDoor::class, $top);
		self::assertTrue($top->isTop());
		self::assertSame(CopperOxidation::EXPOSED, $top->getOxidation());
	}
}
