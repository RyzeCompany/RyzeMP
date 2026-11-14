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

namespace pocketmine\entity\animal;

use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use function count;
use function floor;
use function intdiv;
use function mt_rand;
use function mt_srand;

/**
 * {@link Bee::findFlower()} skips the tries on block states that can't give a flower without making the block: it
 * finds the same flower, with the same random draws, as looking every tried block up.
 */
final class BeeFlowerSearchTest extends MobTestCase{

	/**
	 * The search before the block state check: every tried block is made.
	 */
	private static function referenceFindFlower(Bee $bee) : ?Vector3{
		$world = $bee->getWorld();
		$loc = $bee->getLocation();
		$bx = (int) floor($loc->x);
		$by = (int) floor($loc->y);
		$bz = (int) floor($loc->z);
		for($i = 0; $i < 24; ++$i){
			$x = $bx + mt_rand(-6, 6);
			$y = $by + mt_rand(-4, 2);
			$z = $bz + mt_rand(-6, 6);
			$block = $world->getBlockAt($x, $y, $z);
			if(Bee::isPollinatable($block) && !LiquidLayer::holdsWater($block)){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	public function testSameFlowersAndDraws() : void{
		mt_srand(4242);
		$blocks = [
			VanillaBlocks::POPPY(), VanillaBlocks::DANDELION(), VanillaBlocks::CORNFLOWER(), VanillaBlocks::CHERRY_LEAVES(),
			VanillaBlocks::OPEN_EYEBLOSSOM(), VanillaBlocks::CLOSED_EYEBLOSSOM(), VanillaBlocks::TALL_GRASS(),
			VanillaBlocks::OAK_LEAVES(), VanillaBlocks::WATER(), VanillaBlocks::BANNER(), VanillaBlocks::WALL_BANNER(),
			VanillaBlocks::FLOWER_POT(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::GRASS()
		];
		for($x = -12; $x <= 12; ++$x){
			for($z = -12; $z <= 12; ++$z){
				for($y = 1; $y <= 4; ++$y){
					if(mt_rand(0, 9) < 3){
						$this->world->setBlockAt($x, $y, $z, $blocks[mt_rand(0, count($blocks) - 1)], false);
					}
				}
			}
		}
		$bee = new Bee(new Location(0.5, 3, 0.5, $this->world, 0, 0));
		$found = 0;
		for($seed = 0; $seed < 400; ++$seed){
			$bee->teleport(new Vector3(($seed % 13) - 6 + 0.5, 1 + ($seed % 4), (intdiv($seed, 13) % 13) - 6 + 0.5));
			mt_srand($seed);
			$expected = self::referenceFindFlower($bee);
			$expectedNext = mt_rand();
			mt_srand($seed);
			$actual = $bee->findFlower();
			self::assertEquals($expected, $actual, "seed $seed");
			self::assertSame($expectedNext, mt_rand(), "random draws of seed $seed");
			if($actual !== null){
				++$found;
			}
		}
		self::assertGreaterThan(50, $found);
		$bee->close();
	}
}
