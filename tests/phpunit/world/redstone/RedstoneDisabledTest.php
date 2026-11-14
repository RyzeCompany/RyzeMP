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
use pocketmine\math\Vector3;

class RedstoneDisabledTest extends RedstoneTestCase{

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false]];
	}

	public function testNothingIsPowered() : void{
		self::assertNull($this->world->getRedstone());
		$this->floor(-1, -1, 4, 1);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->set(1, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->runTicks(5);
		self::assertSame(0, $this->wirePower(1, 1, 0));
		self::assertFalse($this->world->isBlockPowered(new Vector3(2, 1, 0)));
	}
}
