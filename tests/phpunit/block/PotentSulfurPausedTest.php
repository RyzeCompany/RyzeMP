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
use pocketmine\world\ChunkTicker;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Potent sulfur only updates in ticking chunks, and its eruption countdown waits meanwhile.
 */
final class PotentSulfurPausedTest extends RedstoneTestCase{
	//in chunk 1,0
	private const X = 24;
	private const Y = 10;
	private const Z = 8;

	private ChunkTicker $ticker;

	protected function getPocketmineYml() : array{
		//no random ticks: only the scheduled updates are under test
		return ["chunk-ticking" => ["tick-radius" => 4, "blocks-per-subchunk-per-tick" => 0]];
	}

	protected function setUp() : void{
		parent::setUp();
		$radius = $this->getChunkRadius();
		for($cx = -$radius; $cx <= $radius; ++$cx){
			for($cz = -$radius; $cz <= $radius; ++$cz){
				$this->world->getChunk($cx, $cz)?->setLightPopulated(true);
			}
		}
		$this->ticker = new ChunkTicker();
		$this->world->registerTickingChunk($this->ticker, 1, 0);
	}

	private function state() : PotentSulfurState{
		$block = $this->get(self::X, self::Y, self::Z);
		self::assertInstanceOf(PotentSulfur::class, $block);
		return $block->getSulfurState();
	}

	private function buildDormantSpring() : void{
		$this->set(self::X, self::Y - 1, self::Z, VanillaBlocks::MAGMA());
		foreach([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dz]){
			$this->set(self::X + $dx, self::Y + 1, self::Z + $dz, VanillaBlocks::STONE());
		}
		$this->set(self::X, self::Y + 1, self::Z, VanillaBlocks::WATER());
		$this->set(self::X, self::Y, self::Z, VanillaBlocks::POTENT_SULFUR());
		$this->runTicks(1);
		self::assertSame(PotentSulfurState::DORMANT, $this->state());
	}

	private function ticksUntilErupting(int $max) : int{
		$ticks = 0;
		while($this->state() === PotentSulfurState::DORMANT && $ticks < $max){
			$this->runTicks(1);
			++$ticks;
		}
		return $ticks;
	}

	public function testCountdownWaitsOutsideTickingChunks() : void{
		$this->buildDormantSpring();
		$dormantTicks = PotentSulfur::getCountdownSteps($this->world->getSeed(), self::X, self::Y, self::Z, PotentSulfurState::DORMANT, 1) * PotentSulfur::COUNTDOWN_STEP_TICKS;
		$this->runTicks(100);
		self::assertSame(1, PotentSulfur::getCountdownCount($this->world));

		$this->world->unregisterTickingChunk($this->ticker, 1, 0);
		$this->runTicks(1000); //longer than any dormant countdown
		self::assertSame(PotentSulfurState::DORMANT, $this->state());
		self::assertFalse($this->world->isBlockUpdateScheduled(self::X, self::Y, self::Z), "waits outside the ticking chunks");

		$this->world->registerTickingChunk($this->ticker, 1, 0);
		$ticks = $this->ticksUntilErupting(1000);
		self::assertSame(PotentSulfurState::ERUPTING, $this->state());
		self::assertEqualsWithDelta($dormantTicks - 100, $ticks, PotentSulfur::GAS_INTERVAL_TICKS * 2 + 2, "the countdown went on where it stopped");
	}

	public function testBreakingDropsTheCountdown() : void{
		$this->buildDormantSpring();
		$this->runTicks(20);
		self::assertSame(1, PotentSulfur::getCountdownCount($this->world));
		$this->breakBlock(self::X, self::Y, self::Z);
		self::assertSame(0, PotentSulfur::getCountdownCount($this->world));
	}

	public function testReplacedBlockStartsANewCountdown() : void{
		$this->buildDormantSpring();
		$dormantTicks = PotentSulfur::getCountdownSteps($this->world->getSeed(), self::X, self::Y, self::Z, PotentSulfurState::DORMANT, 1) * PotentSulfur::COUNTDOWN_STEP_TICKS;
		$this->runTicks(20);
		//replaced without breaking (like a plugin or a structure would), then put back much later
		$this->world->setBlockAt(self::X, self::Y, self::Z, VanillaBlocks::STONE());
		$this->runTicks($dormantTicks + 100);
		$this->set(self::X, self::Y, self::Z, VanillaBlocks::POTENT_SULFUR()->setSulfurState(PotentSulfurState::DORMANT));
		$this->world->scheduleDelayedBlockUpdate($this->get(self::X, self::Y, self::Z)->getPosition(), 1);
		$this->runTicks(2);
		self::assertSame(PotentSulfurState::DORMANT, $this->state(), "the old countdown, long past its end, isn't reused as over");
	}
}
