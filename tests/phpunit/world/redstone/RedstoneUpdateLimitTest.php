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

use pocketmine\block\VanillaBlocks;

class RedstoneUpdateLimitTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => true, "max-wire-updates-per-tick" => 10]];
	}

	public function testDeferredNetworkIsSolvedNextTick() : void{
		$this->floor(-1, -1, 20, 3);
		for($x = 1; $x <= 12; ++$x){
			$this->world->setBlockAt($x, 1, 0, VanillaBlocks::REDSTONE_WIRE(), false);
			$this->world->setBlockAt($x, 1, 2, VanillaBlocks::REDSTONE_WIRE(), false);
		}
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::REDSTONE(), false);
		$this->world->setBlockAt(0, 1, 2, VanillaBlocks::REDSTONE(), false);
		$this->redstone()->markWireDirty(1, 1, 0);
		$this->redstone()->markWireDirty(1, 1, 2);

		$this->runTicks(1);
		//the first network (12 wires) used up the budget of 10: the second one waits
		$powered = ($this->wirePower(1, 1, 0) > 0 ? 1 : 0) + ($this->wirePower(1, 1, 2) > 0 ? 1 : 0);
		self::assertSame(1, $powered);
		self::assertSame(1, $this->redstone()->getStatistics()["pendingWires"]);

		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(1, 1, 0));
		self::assertSame(15, $this->wirePower(1, 1, 2));
		self::assertSame(0, $this->redstone()->getStatistics()["pendingWires"]);
	}
}
