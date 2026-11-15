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
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * A provider that doesn't keep scheduled block updates (anything but LevelDB): the queued updates of a saved chunk stay
 * in memory and run if the chunk is loaded again by then, as before they were saved.
 */
final class ScheduledBlockUpdatesUnsavedProviderTest extends RedstoneTestCase{

	protected function keepsSavedChunks() : bool{
		return true;
	}

	protected function keepsScheduledBlockUpdates() : bool{
		return false;
	}

	public function testQueuedUpdateRunsAfterQuickReload() : void{
		$this->floor(0, 0, 3, 3);
		$this->set(1, 1, 1, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		$this->interact(1, 1, 1);
		$this->runTicks(5); //15 ticks left
		self::assertTrue($this->world->unloadChunk(0, 0, false));
		$this->runTicks(3);
		self::assertNotNull($this->world->loadChunk(0, 0));
		$this->runTicks(11);
		$button = $this->get(1, 1, 1);
		self::assertInstanceOf(Button::class, $button);
		self::assertTrue($button->isPressed());
		$this->runTicks(1);
		$button = $this->get(1, 1, 1);
		self::assertInstanceOf(Button::class, $button);
		self::assertFalse($button->isPressed(), "the in-memory update released it on time");
	}
}
