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

use pocketmine\block\Block;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\Path3DFinder;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\utils\Random;
use pocketmine\world\World;
use function ceil;
use function count;
use function floor;
use function max;
use function sqrt;

/**
 * Path3DFinder::isClear() reads the blocks of the swept boxes from the chunk of their column: it must answer like the
 * original check, which read every block through PathBlockTypes::at().
 */
final class Path3DClearEquivalenceTest extends MobTestCase{

	/**
	 * Blocks of every kind isClear() looks at in the air and in water from y=1 to y=8, up to the edge of the loaded
	 * chunks (x/z -32..47; the segments also reach into unloaded chunks), and a few at the top of the world.
	 */
	private function terrain(int $seed) : void{
		$random = new Random($seed);
		/** @var Block[] $palette */
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::OAK_SLAB(), VanillaBlocks::WATER(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::COBBLESTONE_WALL(),
			VanillaBlocks::TALL_GRASS(), VanillaBlocks::FIRE(), VanillaBlocks::MAGMA(), VanillaBlocks::OAK_TRAPDOOR(), VanillaBlocks::CACTUS(),
			VanillaBlocks::OAK_LEAVES(), VanillaBlocks::GLASS(), VanillaBlocks::COBWEB(), VanillaBlocks::LAVA(), VanillaBlocks::OAK_DOOR(),
		];
		for($x = -32; $x <= 47; ++$x){
			for($z = -32; $z <= 47; ++$z){
				//a lake in the middle (x/z 5..35), air around it
				$lake = $x >= 5 && $x <= 35 && $z >= 5 && $z <= 35;
				for($y = 1; $y <= 8; ++$y){
					$roll = $random->nextBoundedInt($lake ? 16 : 30);
					if($roll === 0){
						$this->world->setBlockAt($x, $y, $z, $palette[$random->nextBoundedInt(count($palette))], false);
					}elseif($lake && $roll === 1){
						//a slab holding water in the liquid layer
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::OAK_SLAB(), false);
						WaterloggedHelper::waterlog($this->world->getBlockAt($x, $y, $z));
					}elseif($lake){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
					}
				}
			}
		}
		$maxY = $this->world->getMaxY();
		for($i = 0; $i < 60; ++$i){
			$this->world->setBlockAt($random->nextRange(-30, 45), $maxY - 1 - $random->nextBoundedInt(3), $random->nextRange(-30, 45), VanillaBlocks::STONE(), false);
		}
	}

	/**
	 * The original isClear(): every block of the swept boxes through PathBlockTypes::at(), cached for the call, with
	 * the same skipping of a box like the one before and of blocks already checked.
	 */
	private static function referenceClear(World $world, int $medium, float $fromX, float $fromY, float $fromZ, float $toX, float $toY, float $toZ, float $halfWidth, float $height) : bool{
		$dx = $toX - $fromX;
		$dy = $toY - $fromY;
		$dz = $toZ - $fromZ;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($length > Path3DFinder::MAX_RANGE * 2){
			return false;
		}
		$originX = (int) floor($fromX);
		$originY = (int) floor($fromY);
		$originZ = (int) floor($fromZ);
		$steps = max(1, (int) ceil($length / 0.4));
		$top = $height - 0.001;
		$halfWidth -= 0.001;
		$types = [];
		$type = function(int $x, int $y, int $z) use ($world, &$types) : int{
			return $types[World::blockHash($x, $y, $z)] ??= PathBlockTypes::at($world, $x, $y, $z);
		};
		$seen = [];
		$lastBox = -1;
		$water = $medium === Path3DFinder::MEDIUM_WATER;
		for($s = 0; $s <= $steps; ++$s){
			$px = $fromX + $dx * $s / $steps;
			$py = $fromY + $dy * $s / $steps;
			$pz = $fromZ + $dz * $s / $steps;
			$minX = (int) floor($px - $halfWidth);
			$maxX = (int) floor($px + $halfWidth);
			$minY = (int) floor($py);
			$maxY = (int) floor($py + $top);
			$minZ = (int) floor($pz - $halfWidth);
			$maxZ = (int) floor($pz + $halfWidth);
			$bottomCheck = $water || $py - $minY < 0.5;
			$box = ((($minX - $originX + 512) << 20) | (($minY - $originY + 512) << 10) | ($minZ - $originZ + 512)) * 32 +
				($maxX - $minX) * 8 + ($maxZ - $minZ) * 2 + ($bottomCheck ? 1 : 0) + ($maxY - $minY) * 16;
			if($box === $lastBox){
				continue;
			}
			$lastBox = $box;
			for($bx = $minX; $bx <= $maxX; ++$bx){
				for($bz = $minZ; $bz <= $maxZ; ++$bz){
					for($by = $minY; $by <= $maxY; ++$by){
						$bottom = $by === $minY && $bottomCheck;
						$key = World::blockHash($bx, $by, $bz) . ($bottom ? "b" : "");
						if(isset($seen[$key])){
							continue;
						}
						$seen[$key] = true;
						$flags = $type($bx, $by, $bz);
						if(($flags & PathBlockTypes::PASSABLE) === 0){
							return false;
						}
						if($water){
							if(($flags & (PathBlockTypes::DANGER | PathBlockTypes::NEAR_DANGER)) !== 0 || ($bottom && ($flags & PathBlockTypes::WATER) === 0)){
								return false;
							}
						}elseif(($flags & (PathBlockTypes::WATER | PathBlockTypes::DANGER | PathBlockTypes::NEAR_DANGER)) !== 0 || ($bottom && ($type($bx, $by - 1, $bz) & PathBlockTypes::TALL) !== 0)){
							return false;
						}
					}
				}
			}
		}
		return true;
	}

	public function testSameAnswers() : void{
		$this->terrain(11);
		$random = new Random(12);
		$maxY = $this->world->getMaxY();
		$clear = [0, 0];
		$sizes = [[0.25, 0.3], [0.35, 0.6], [0.5, 0.9], [0.45, 1.95], [0.7, 1.4], [1.0, 2.5]];
		foreach([Path3DFinder::MEDIUM_AIR, Path3DFinder::MEDIUM_WATER] as $medium){
			foreach($sizes as [$halfWidth, $height]){
				$finder = new Path3DFinder($this->world, $medium, max(0, (int) ceil($height) - 1), max(1, (int) floor($halfWidth * 2 + 1)), 400);
				for($i = 0; $i < 300; ++$i){
					$top = $random->nextBoundedInt(15) === 0;
					//swimmers mostly in the lake, flyers mostly around it
					$inLake = $random->nextBoundedInt(4) !== 0 ? $medium === Path3DFinder::MEDIUM_WATER : $medium !== Path3DFinder::MEDIUM_WATER;
					$fromX = $inLake ? 7 + $random->nextFloat() * 26 : $random->nextFloat() * 70 - 28;
					$fromY = $top ? $maxY - 4 + $random->nextFloat() * 6 : 1 + $random->nextFloat() * 7;
					$fromZ = $inLake ? 7 + $random->nextFloat() * 26 : ($random->nextBoundedInt(2) === 0 ? -28 + $random->nextFloat() * 30 : 38 + $random->nextFloat() * 10);
					//mostly short segments, some long ones (out of the loaded chunks, or too long to check)
					$reach = $random->nextBoundedInt(6) === 0 ? 110.0 : 6.0;
					$toX = $fromX + ($random->nextFloat() - 0.5) * $reach;
					$toY = $top ? $fromY + ($random->nextFloat() - 0.5) * 6 : 1 + $random->nextFloat() * 7;
					$toZ = $fromZ + ($random->nextFloat() - 0.5) * $reach;
					$expected = self::referenceClear($this->world, $medium, $fromX, $fromY, $fromZ, $toX, $toY, $toZ, $halfWidth, $height);
					self::assertSame($expected, $finder->isClear($fromX, $fromY, $fromZ, $toX, $toY, $toZ, $halfWidth, $height), "medium $medium size $halfWidth x $height segment $i");
					++$clear[$expected ? 1 : 0];
				}
			}
		}
		self::assertGreaterThan(300, $clear[1], "the test should find clear segments");
		self::assertGreaterThan(300, $clear[0], "the test should find blocked segments");
	}
}
