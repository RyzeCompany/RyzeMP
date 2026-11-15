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

use pocketmine\block\RedstoneTorch;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use function fwrite;
use function getenv;
use function hrtime;
use function intdiv;
use function sprintf;
use const STDERR;

/**
 * Timings of big circuits. Small sizes run as normal tests; set REDSTONE_BENCH=1 to run the big ones and print the
 * results.
 *
 * @group benchmark
 */
class RedstoneBenchmarkTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 4;
	}

	private function isFullBenchmark() : bool{
		return getenv("REDSTONE_BENCH") === "1";
	}

	private function report(string $line) : void{
		if($this->isFullBenchmark()){
			fwrite(STDERR, "[redstone bench] $line\n");
		}
	}

	/**
	 * A square grid of wire (one single network) toggled from a corner by a lever.
	 */
	public function testWireGrid() : void{
		$size = $this->isFullBenchmark() ? 64 : 16;
		$min = -32;
		$max = $min + $size - 1;
		$this->floor($min - 1, $min - 1, $max, $max);
		$wire = VanillaBlocks::REDSTONE_WIRE();
		for($x = $min; $x <= $max; ++$x){
			for($z = $min; $z <= $max; ++$z){
				$this->world->setBlockAt($x, 1, $z, $wire, false);
			}
		}
		$this->set($min - 1, 1, $min, VanillaBlocks::LEVER());
		$this->runTicks(2);

		$toggles = $this->isFullBenchmark() ? 20 : 2;
		$engine = $this->redstone();
		$engine->resetStatistics();
		$start = hrtime(true);
		for($i = 0; $i < $toggles; ++$i){
			$this->interact($min - 1, 1, $min);
			$this->runTicks(1);
			self::assertSame($i % 2 === 0 ? 15 : 0, $this->wirePower($min, 1, $min));
		}
		$totalNs = hrtime(true) - $start;
		$stats = $engine->getStatistics();
		self::assertGreaterThan(0, $stats["wireBlocksSolved"]);

		$this->report(sprintf(
			"wire grid %dx%d (%d wires): %d toggles, %.2f ms per toggle (tick total), solver %.2f ms per toggle, %.2f us per wire solved",
			$size, $size, $size * $size, $toggles,
			$totalNs / $toggles / 1e6,
			$stats["solveTimeNs"] / $toggles / 1e6,
			$stats["solveTimeNs"] / $stats["wireBlocksSolved"] / 1e3
		));
	}

	/**
	 * A torch + repeater clock driving a long bus of wire and repeaters.
	 */
	public function testRepeaterClock() : void{
		$segments = $this->isFullBenchmark() ? 4 : 1;
		$this->floor(-4, -1, 6, 3 + 16 * $segments + 2);

		//cost of a tick of the test world without any circuit, for reference
		$idleTicks = 200;
		$start = hrtime(true);
		$this->runTicks($idleTicks);
		$idleNsPerTick = (hrtime(true) - $start) / $idleTicks;

		//clock: torch on the east side of S, output wire -> repeater -> wire stepping up on top of S
		$this->set(0, 1, 0, VanillaBlocks::STONE());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::EAST));
		$this->set(1, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$this->set(0, 1, 1, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST)->setDelay(4));
		$this->set(-1, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(0, 2, 0, VanillaBlocks::REDSTONE_WIRE());

		//bus going south from the torch output: 15 wires then a repeater, repeated, with lamps at the end
		$z = 2;
		for($s = 0; $s < $segments; ++$s){
			for($i = 0; $i < 15; ++$i){
				$this->set(1, 1, $z++, VanillaBlocks::REDSTONE_WIRE());
			}
			$this->set(1, 1, $z++, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::NORTH));
		}
		$this->set(1, 1, $z, VanillaBlocks::REDSTONE_LAMP());

		$ticks = $this->isFullBenchmark() ? 2000 : 200;
		$engine = $this->redstone();
		$engine->resetStatistics();
		$torchChanges = 0;
		$lastLit = true;
		$start = hrtime(true);
		for($i = 0; $i < $ticks; ++$i){
			$this->runTicks(1);
			$torch = $this->get(1, 1, 0);
			self::assertInstanceOf(RedstoneTorch::class, $torch);
			if($torch->isLit() !== $lastLit){
				$lastLit = $torch->isLit();
				$torchChanges++;
			}
		}
		$totalNs = hrtime(true) - $start;
		$stats = $engine->getStatistics();

		//period = 2 * (torch delay 2 + repeater delay 8) = 20 ticks: one change every 10 ticks, and no burnout
		self::assertGreaterThanOrEqual(intdiv($ticks, 10) - 2, $torchChanges);

		$this->report(sprintf(
			"repeater clock with %d bus segments (%d wires): %d ticks, %.1f us per tick (idle world: %.1f us), %d torch changes, %d wire blocks solved, %.2f us per wire solved",
			$segments, $segments * 15 + 4, $ticks,
			$totalNs / $ticks / 1e3,
			$idleNsPerTick / 1e3,
			$torchChanges,
			$stats["wireBlocksSolved"],
			$stats["wireBlocksSolved"] > 0 ? $stats["solveTimeNs"] / $stats["wireBlocksSolved"] / 1e3 : 0
		));
	}
}
