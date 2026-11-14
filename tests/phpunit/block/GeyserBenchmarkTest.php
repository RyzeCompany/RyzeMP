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

use pocketmine\block\utils\PotentSulfurState;
use pocketmine\world\redstone\RedstoneTestCase;
use function getenv;
use function hrtime;
use function min;
use function printf;
use const PHP_INT_MAX;

/**
 * Cost of 50 continuous geysers (updated every tick), 50 wet and 50 dormant potent sulfur blocks (every 10 ticks; the
 * dormant ones erupt now and then), compared with
 * the same springs over stone. Set CAVE_BENCH=1 to print the numbers.
 */
final class GeyserBenchmarkTest extends RedstoneTestCase{
	private const Y = 10;
	private const COUNT = 50;

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false]];
	}

	/**
	 * Builds the springs: potent sulfur (or stone) under a water source, closed by stone around and above the water.
	 */
	private function build(Block $floor, Block $below, int $offsetZ) : void{
		$stone = VanillaBlocks::STONE();
		for($i = 0; $i < self::COUNT; ++$i){
			$x = -25 + ($i % 10) * 5;
			$z = $offsetZ + (int) ($i / 10) * 3;
			$this->world->setBlockAt($x, self::Y - 1, $z, $below, false);
			foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]){
				$this->world->setBlockAt($x + $dx, self::Y + 1, $z + $dz, $stone, false);
			}
			$this->world->setBlockAt($x, self::Y + 1, $z, VanillaBlocks::WATER(), false);
			$this->world->setBlockAt($x, self::Y, $z, $floor);
		}
	}

	public function testGeysers() : void{
		$bench = getenv("CAVE_BENCH") === "1";
		$ticks = $bench ? 2000 : 40;
		$this->build(VanillaBlocks::POTENT_SULFUR(), VanillaBlocks::LAVA(), -16);
		$this->build(VanillaBlocks::POTENT_SULFUR(), VanillaBlocks::STONE(), 2);
		$this->build(VanillaBlocks::POTENT_SULFUR(), VanillaBlocks::MAGMA(), 20);
		$this->runTicks(2);
		$geyser = $this->get(-25, self::Y, -16);
		self::assertInstanceOf(PotentSulfur::class, $geyser);
		self::assertSame(PotentSulfurState::CONTINUOUS, $geyser->getSulfurState());
		$wet = $this->get(-25, self::Y, 2);
		self::assertInstanceOf(PotentSulfur::class, $wet);
		self::assertSame(PotentSulfurState::WET, $wet->getSulfurState());

		$best = PHP_INT_MAX;
		for($run = 0; $run < ($bench ? 5 : 1); ++$run){
			$start = hrtime(true);
			$this->runTicks($ticks);
			$best = min($best, hrtime(true) - $start);
		}

		//the same world with stone instead of sulfur
		$this->build(VanillaBlocks::STONE(), VanillaBlocks::LAVA(), -16);
		$this->build(VanillaBlocks::STONE(), VanillaBlocks::STONE(), 2);
		$this->build(VanillaBlocks::STONE(), VanillaBlocks::MAGMA(), 20);
		$this->runTicks(2);
		$control = PHP_INT_MAX;
		for($run = 0; $run < ($bench ? 5 : 1); ++$run){
			$start = hrtime(true);
			$this->runTicks($ticks);
			$control = min($control, hrtime(true) - $start);
		}
		if($bench){
			printf("\n%d continuous geysers + %d wet + %d dormant sulfur: %.2f us per tick, %.2f us without them (best of 5, %d ticks)\n", self::COUNT, self::COUNT, self::COUNT, $best / 1000 / $ticks, $control / 1000 / $ticks, $ticks);
		}
		self::assertFalse($this->world->isBlockUpdateScheduled(-25, self::Y, -16), "stone has no updates");
	}
}
