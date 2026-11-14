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

use pocketmine\block\Button;
use pocketmine\block\Observer;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Scheduled block updates are saved with their chunk and run after it loads again, with the ticks they had left.
 */
final class ScheduledBlockUpdatesSaveTest extends RedstoneTestCase{

	protected function keepsSavedChunks() : bool{
		return true;
	}

	private function reloadChunk(int $chunkX, int $chunkZ, int $ticksUnloaded) : void{
		self::assertTrue($this->world->unloadChunk($chunkX, $chunkZ, false));
		self::assertFalse($this->world->isChunkLoaded($chunkX, $chunkZ));
		$this->runTicks($ticksUnloaded);
		self::assertNotNull($this->world->loadChunk($chunkX, $chunkZ));
	}

	private function buttonPressed() : bool{
		$button = $this->get(1, 1, 1);
		self::assertInstanceOf(Button::class, $button);
		return $button->isPressed();
	}

	public function testPressedButtonReleasesAfterReload() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		self::assertTrue($this->buttonPressed());
		$this->runTicks(5); //stone buttons stay pressed for 20 ticks: 15 left

		$this->reloadChunk(0, 0, 40);
		self::assertTrue($this->buttonPressed(), "nothing ran while the chunk was unloaded");
		self::assertTrue($this->world->isBlockUpdateScheduled(1, 1, 1));
		$this->runTicks(14);
		self::assertTrue($this->buttonPressed(), "the update keeps the ticks it had left");
		$this->runTicks(1);
		self::assertFalse($this->buttonPressed(), "released after the saved delay");
	}

	public function testUnloadedQueueEntriesDoNotRunAfterReload() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->runTicks(5);
		//reloaded right away: the old queue entry (due in 15 ticks) must not release the button before the saved one
		$this->reloadChunk(0, 0, 3);
		$this->runTicks(13);
		self::assertTrue($this->buttonPressed());
		$this->runTicks(2);
		self::assertFalse($this->buttonPressed());
	}

	public function testReloadedTwice() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->runTicks(5); //15 ticks left
		$this->reloadChunk(0, 0, 3);
		$this->runTicks(2); //13 ticks left
		$this->reloadChunk(0, 0, 2);
		//neither old queue entry releases it early
		$this->runTicks(12);
		self::assertTrue($this->buttonPressed());
		$this->runTicks(1);
		self::assertFalse($this->buttonPressed());
	}

	public function testReloadedTwiceInTheSameTick() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->runTicks(5); //15 ticks left
		//two stale queue entries end up with the same due tick as the live one
		$this->reloadChunk(0, 0, 0);
		$this->reloadChunk(0, 0, 0);
		//then unloaded for a while: neither stale entry may run the update reloaded with 15 ticks left
		$this->reloadChunk(0, 0, 10);
		$this->runTicks(14);
		self::assertTrue($this->buttonPressed());
		$this->runTicks(1);
		self::assertFalse($this->buttonPressed());
		self::assertSame([], $this->getWorldProperty("droppedScheduledBlockUpdates"), "stale entries are forgotten once skipped");
		self::assertSame([], $this->getWorldProperty("scheduledBlockUpdateChunkIndex"), "no empty chunk entry is kept");
	}

	public function testChunkIndexForgottenOnUnsavedUnload() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		self::assertArrayHasKey(World::chunkHash(0, 0), $this->getWorldProperty("scheduledBlockUpdateChunkIndex"));
		self::assertTrue($this->world->unloadChunk(0, 0, false, false));
		self::assertSame([], $this->getWorldProperty("scheduledBlockUpdateChunkIndex"));
	}

	/**
	 * @return mixed[]
	 */
	private function getWorldProperty(string $name) : array{
		$value = (new \ReflectionProperty(World::class, $name))->getValue($this->world);
		self::assertIsArray($value);
		return $value;
	}

	public function testNothingSavedWithoutAutoSave() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->world->setAutoSave(false);
		self::assertTrue($this->world->unloadChunk(0, 0, false));
		self::assertSame([], $this->savedChunks);
	}

	public function testObserverClockSurvivesReload() : void{
		$this->floor(0, 0, 4, 2);
		$this->set(1, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::EAST));
		$this->set(2, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::WEST));
		$this->runTicks(41);

		$this->reloadChunk(0, 0, 20);
		$toggles = 0;
		$observer = $this->get(1, 1, 0);
		self::assertInstanceOf(Observer::class, $observer);
		$last = $observer->isPowered();
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			$observer = $this->get(1, 1, 0);
			self::assertInstanceOf(Observer::class, $observer);
			if($observer->isPowered() !== $last){
				$toggles++;
				$last = $observer->isPowered();
			}
		}
		//a pulse of 2 ticks every 6 ticks: about 33 toggles in 100 ticks
		self::assertGreaterThanOrEqual(30, $toggles, "the clock keeps running after its chunk was reloaded");
	}

	public function testSavedUpdatesAreInTheChunkData() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->runTicks(5);
		$this->world->saveChunks();
		$data = $this->savedChunks[World::chunkHash(0, 0)] ?? null;
		self::assertNotNull($data);
		self::assertSame([[1, 1, 1, 15]], $data->getScheduledBlockUpdates());
		self::assertSame([], ($this->savedChunks[World::chunkHash(1, 0)] ?? null)?->getScheduledBlockUpdates() ?? []);
	}
}
