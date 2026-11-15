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

namespace pocketmine\entity\ai;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathRecomputeWatch;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_fill;
use function count;
use function mt_rand;
use function mt_srand;

/**
 * Where the dangerous blocks of chunk sections are, for the danger margin of path searches: worked out from the packed
 * words of the block layers, kept with the layer and forgotten when a dangerous block appears or goes away.
 */
final class PathDangerRowsTest extends RedstoneTestCase{

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private static function bruteForce(PalettedBlockArray $layer) : array{
		$rows = array_fill(0, 256, 0);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				for($y = 0; $y < 16; ++$y){
					$danger = PathBlockTypes::dangerOfState($layer->get($x, $y, $z)) & PathBlockTypes::NEAR_DANGER;
					$rows[($y << 4) | $z] |= PathRecomputeWatch::dangerRowBits($danger, 1 << $x);
				}
			}
		}
		return $rows;
	}

	public function testRowsMatchTheBlocksForEveryPaletteSize() : void{
		mt_srand(4711);
		$dangers = [
			VanillaBlocks::LAVA()->getStateId(), VanillaBlocks::FIRE()->getStateId(), VanillaBlocks::CACTUS()->getStateId(),
			VanillaBlocks::MAGMA()->getStateId(), VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE)->getStateId(),
		];
		$harmless = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $block){
			if(PathBlockTypes::dangerOfState($stateId) === 0 && count($harmless) < 300){
				$harmless[] = $stateId;
			}
		}
		self::assertCount(300, $harmless);
		//palettes of 1 to about 260 states: every number of bits per block
		foreach([1, 2, 3, 5, 9, 17, 33, 65, 140, 260] as $states){
			foreach([1, 40, 900] as $dangerCount){
				$layer = new PalettedBlockArray(VanillaBlocks::AIR()->getStateId());
				for($i = 0; $i < $states; ++$i){
					$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $harmless[$i]);
				}
				for($i = 0; $i < $dangerCount; ++$i){
					$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $dangers[mt_rand(0, count($dangers) - 1)]);
				}
				self::assertSame(self::bruteForce($layer), PathRecomputeWatch::computeDangerRows($layer), "$states states, $dangerCount dangerous blocks, {$layer->getBitsPerBlock()} bits per block");
			}
		}
		//a section all of one state
		$lava = new PalettedBlockArray(VanillaBlocks::LAVA()->getStateId());
		self::assertSame(self::bruteForce($lava), PathRecomputeWatch::computeDangerRows($lava));
		$air = new PalettedBlockArray(VanillaBlocks::AIR()->getStateId());
		self::assertSame(array_fill(0, 256, 0), PathRecomputeWatch::computeDangerRows($air));
	}

	public function testRowsAreForgottenWhenADangerousBlockAppears() : void{
		$watch = $this->world->getPathRecomputeWatch();
		$this->world->setBlockAt(3, 1, 5, VanillaBlocks::CACTUS(), false);
		$layer = $this->world->getChunk(0, 0)?->getSubChunk(0)->getBlockLayers()[0] ?? null;
		self::assertNotNull($layer);
		$rows = $watch->getDangerRows($layer);
		self::assertSame(PathRecomputeWatch::dangerRowBits(PathBlockTypes::HURTS | PathBlockTypes::DAMAGE_BLOCK, 1 << 3), $rows[(1 << 4) | 5]);

		//a harmless change keeps them, a fire is in them at once
		$this->world->setBlockAt(7, 1, 7, VanillaBlocks::STONE(), false);
		self::assertSame($rows, $watch->getDangerRows($layer));
		$this->world->setBlockAt(8, 2, 9, VanillaBlocks::FIRE(), false);
		self::assertSame(self::bruteForce($layer), $watch->getDangerRows($layer));
		self::assertNotSame(0, $watch->getDangerRows($layer)[(2 << 4) | 9]);
		$this->world->setBlockAt(3, 1, 5, VanillaBlocks::AIR(), false);
		self::assertSame(0, $watch->getDangerRows($layer)[(1 << 4) | 5]);
	}
}
