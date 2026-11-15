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

namespace pocketmine\world;

use pocketmine\block\Fire;
use pocketmine\block\Observer;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Fires (which reschedule themselves forever on netherrack) only update in ticking chunks, like vanilla block ticks.
 */
final class PausedBlockUpdatesTest extends RedstoneTestCase{
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
		//fire at x=1 in the ticked chunk 0,0 and at x=17 in chunk 1,0
		foreach([1, 17] as $x){
			$this->set($x, 0, 1, VanillaBlocks::NETHERRACK());
			$this->set($x, 1, 1, VanillaBlocks::FIRE());
			$this->world->scheduleDelayedBlockUpdate($this->get($x, 1, 1)->getPosition(), 1);
		}
		$this->world->registerTickingChunk($this->ticker, 0, 0);
	}

	private function scheduled(int $x) : bool{
		return $this->world->isBlockUpdateScheduled($x, 1, 1);
	}

	public function testFireOutsideTickingChunksWaits() : void{
		$this->runTicks(100);
		self::assertInstanceOf(Fire::class, $this->get(1, 1, 1));
		self::assertInstanceOf(Fire::class, $this->get(17, 1, 1));
		self::assertTrue($this->scheduled(1), "the fire of the ticked chunk keeps updating");
		self::assertFalse($this->scheduled(17), "the fire of the unticked chunk waits");

		$this->world->registerTickingChunk($this->ticker, 1, 0);
		$this->runTicks(2);
		self::assertTrue($this->scheduled(17), "resumed once its chunk is ticked");
		$this->runTicks(100);
		self::assertTrue($this->scheduled(17), "and keeps updating");

		$this->world->unregisterTickingChunk($this->ticker, 1, 0);
		$this->runTicks(100);
		self::assertFalse($this->scheduled(17), "waits again");
		self::assertTrue($this->scheduled(1));
	}

	public function testNoChunkTickingRunsEverywhere() : void{
		$this->runTicks(100);
		self::assertFalse($this->scheduled(17));
		//ticking disabled: the waiting update runs, and fires update in every loaded chunk as before
		$this->world->setChunkTickRadius(0);
		$this->runTicks(100);
		self::assertTrue($this->scheduled(1));
		self::assertTrue($this->scheduled(17));
	}

	public function testUnloadDropsWaitingUpdates() : void{
		$this->runTicks(100);
		$paused = new \ReflectionProperty(World::class, "pausedBlockUpdates");
		$waiting = $paused->getValue($this->world);
		self::assertIsArray($waiting);
		self::assertCount(1, $waiting);
		$this->world->unloadChunk(1, 0, false, false);
		self::assertSame([], $paused->getValue($this->world));
	}

	/**
	 * Counts the power changes of the first observer of a clock over the given ticks.
	 */
	private function observerToggles(int $x, int $ticks) : int{
		$toggles = 0;
		$last = null;
		for($i = 0; $i < $ticks; ++$i){
			$this->runTicks(1);
			$observer = $this->get($x, 3, 1);
			self::assertInstanceOf(Observer::class, $observer);
			if($last !== null && $observer->isPowered() !== $last){
				$toggles++;
			}
			$last = $observer->isPowered();
		}
		return $toggles;
	}

	public function testRedstoneClockOutsideTickingChunksWaits() : void{
		//observer clocks in the ticked chunk 0,0 and in chunk 1,0
		foreach([1, 17] as $x){
			$this->set($x, 3, 1, VanillaBlocks::OBSERVER()->setFacing(Facing::EAST));
			$this->set($x + 1, 3, 1, VanillaBlocks::OBSERVER()->setFacing(Facing::WEST));
		}
		$this->runTicks(10);
		self::assertGreaterThanOrEqual(18, $this->observerToggles(1, 60), "the clock of the ticked chunk runs"); //2 toggles per 6-tick period
		self::assertSame(0, $this->observerToggles(17, 60), "the clock of the unticked chunk waits");

		$this->world->registerTickingChunk($this->ticker, 1, 0);
		$this->runTicks(2);
		self::assertGreaterThanOrEqual(18, $this->observerToggles(17, 60), "and runs again once its chunk is ticked");
	}
}
