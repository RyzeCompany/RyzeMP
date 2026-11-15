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

use pocketmine\block\Block;
use pocketmine\block\Fence;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\Thin;
use pocketmine\block\utils\SlabType;
use pocketmine\block\utils\WallConnectionType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Wall;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;
use function floor;

/**
 * World::getBlockCollisionBoxes() reads its cache and tests the boxes without method calls: it must give the same boxes
 * in the same order as the loop calling World::chunkHash(), World::chunkBlockHash() and AxisAlignedBB::intersectsWith(),
 * with a cold cache (every cell computed by the call) and a warm one.
 */
final class BlockCollisionBoxesEquivalenceTest extends RedstoneTestCase{

	/**
	 * The loop World::getBlockCollisionBoxes() replaced, uncached: the boxes of each cell are computed again.
	 *
	 * @return AxisAlignedBB[]
	 * @phpstan-return list<AxisAlignedBB>
	 */
	private function reference(AxisAlignedBB $bb) : array{
		$forCell = (new \ReflectionMethod(World::class, "getBlockCollisionBoxesForCell"))->getClosure($this->world);
		$collisionInfo = RuntimeBlockStateRegistry::getInstance()->collisionInfo;
		$collides = [];
		for($z = (int) floor($bb->minZ); $z <= (int) floor($bb->maxZ); ++$z){
			for($x = (int) floor($bb->minX); $x <= (int) floor($bb->maxX); ++$x){
				for($y = (int) floor($bb->minY); $y <= (int) floor($bb->maxY); ++$y){
					foreach($forCell($x, $y, $z, $collisionInfo) as $blockBB){
						if($blockBB->intersectsWith($bb)){
							$collides[] = $blockBB;
						}
					}
				}
			}
		}
		return $collides;
	}

	private static function withRandomConnections(Random $random, Block $block) : Block{
		if($block instanceof Fence || $block instanceof Thin){
			$block = clone $block;
			foreach(Facing::HORIZONTAL as $facing){
				$block->setConnection($facing, $random->nextBoolean());
			}
		}elseif($block instanceof Wall){
			$block = clone $block;
			foreach(Facing::HORIZONTAL as $facing){
				$block->setConnection($facing, match($random->nextBoundedInt(3)){
					0 => null,
					1 => WallConnectionType::SHORT,
					default => WallConnectionType::TALL
				});
			}
		}
		return $block;
	}

	private static function coordinate(Random $random, float $base, float $spread) : float{
		$value = $base + $random->nextSignedFloat() * $spread;
		return match($random->nextBoundedInt(6)){
			0 => floor($value),
			1 => floor($value) + 0.00001,
			2 => floor($value) - 0.000005,
			3 => floor($value) + 0.5,
			default => $value
		};
	}

	public function testSameBoxesAsTheMethodCallLoop() : void{
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::BOTTOM), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::TOP),
			VanillaBlocks::CARPET(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::COBBLESTONE_WALL(), VanillaBlocks::GLASS_PANE(),
			VanillaBlocks::OAK_STAIRS(), VanillaBlocks::OAK_TRAPDOOR()->setOpen(true), VanillaBlocks::SOUL_SAND(), VanillaBlocks::CAKE(),
			VanillaBlocks::HOPPER(), VanillaBlocks::LANTERN(), VanillaBlocks::SCAFFOLDING(), VanillaBlocks::WATER()
		];
		$random = new Random(20261023);
		$air = VanillaBlocks::AIR();
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				for($y = 1; $y <= 4; ++$y){
					$block = $random->nextBoundedInt(100) < 50 ? $air : self::withRandomConnections($random, $palette[$random->nextBoundedInt(count($palette))]);
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
		$found = 0;
		for($i = 0; $i < 3000; ++$i){
			if($i % 3 === 0){
				$this->world->clearCache(true);
			}
			$x = self::coordinate($random, 0, 6);
			$y = self::coordinate($random, 2, 2);
			$z = self::coordinate($random, 0, 6);
			$bb = new AxisAlignedBB($x, $y, $z, $x + $random->nextFloat() * 2, $y + $random->nextFloat() * 2.5, $z + $random->nextFloat() * 2);
			if($random->nextBoolean()){
				$bb = $bb->addCoord($random->nextSignedFloat() * 0.5, $random->nextSignedFloat() * 0.5, $random->nextSignedFloat() * 0.5);
			}
			$expected = $this->reference($bb);
			$actual = $this->world->getBlockCollisionBoxes($bb);
			self::assertCount(count($expected), $actual, "$bb");
			foreach($expected as $k => $box){
				$other = $actual[$k];
				self::assertSame([$box->minX, $box->minY, $box->minZ, $box->maxX, $box->maxY, $box->maxZ], [$other->minX, $other->minY, $other->minZ, $other->maxX, $other->maxY, $other->maxZ], "$bb");
			}
			$found += count($actual);
		}
		self::assertGreaterThan(1000, $found, "the boxes hit blocks");
	}
}
