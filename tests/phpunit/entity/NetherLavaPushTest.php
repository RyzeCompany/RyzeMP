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

namespace pocketmine\entity;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\world\dimension\Dimension;

/**
 * Lava in ultra-warm dimensions pushes entities harder than Overworld lava (see {@link ProjectilePhysicsTest}).
 */
final class NetherLavaPushTest extends MobTestCase{

	protected function getDimension() : Dimension{
		return Dimension::NETHER;
	}

	public function testNetherLavaPushesHarder() : void{
		$lava = ProjectilePhysicsTest::pushInStream($this, $this->world, VanillaBlocks::LAVA()->setDecay(2), VanillaBlocks::LAVA()->setDecay(4));
		self::assertEqualsWithDelta(0.007, $lava, 1e-9);
	}
}
