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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\World;

/**
 * Eyeblossoms only open and close in the Overworld.
 */
final class EyeblossomNetherTest extends MobTestCase{

	protected function getDimension() : Dimension{
		return Dimension::NETHER;
	}

	public function testEyeblossomStaysClosedInTheNether() : void{
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$this->set(0, 1, 0, VanillaBlocks::CLOSED_EYEBLOSSOM());
		$this->set(2, 0, 0, VanillaBlocks::GRASS());
		$this->set(2, 1, 0, VanillaBlocks::CLOSED_EYEBLOSSOM());
		$this->world->setTime(World::TIME_NIGHT + 1000);
		$this->get(0, 1, 0)->onRandomTick();
		self::assertSame(BlockTypeIds::CLOSED_EYEBLOSSOM, $this->get(0, 1, 0)->getTypeId());
		self::assertFalse($this->world->isBlockUpdateScheduled(2, 1, 0));
	}
}
