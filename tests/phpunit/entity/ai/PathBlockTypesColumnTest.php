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
use pocketmine\block\SweetBerryBush;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\utils\Random;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;
use function count;

/**
 * The column reads of {@link PathBlockTypes} (one chunk lookup for a column of blocks, each block read once while
 * settling a random target on the ground) must give exactly what reading every block through
 * {@link PathBlockTypes::at()} gives.
 */
final class PathBlockTypesColumnTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 1; //x/z -16..31 loaded, the rest isn't
	}

	/**
	 * Uneven terrain over a floor with holes: blocks of every kind the flags tell apart (slabs, fences, water, waterlogged
	 * slabs, lava, fire, magma, cactus, berry bushes, doors, trapdoors, snow layers...) up to y=6.
	 */
	private function terrain(int $seed) : void{
		$random = new Random($seed);
		/** @var Block[] $palette */
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::DIRT(), VanillaBlocks::OAK_SLAB(), VanillaBlocks::WATER(), VanillaBlocks::LAVA(),
			VanillaBlocks::CACTUS(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::GLASS(), VanillaBlocks::TALL_GRASS(),
			VanillaBlocks::SNOW_LAYER(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::FIRE(), VanillaBlocks::MAGMA(),
			VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE), VanillaBlocks::COBWEB(),
			VanillaBlocks::OAK_TRAPDOOR(), VanillaBlocks::OAK_DOOR(), VanillaBlocks::LADDER(), VanillaBlocks::FARMLAND(),
			VanillaBlocks::OAK_STAIRS(),
		];
		for($x = -16; $x <= 31; ++$x){
			for($z = -16; $z <= 31; ++$z){
				$this->world->setBlockAt($x, 0, $z, $random->nextBoundedInt(10) === 0 ? VanillaBlocks::AIR() : VanillaBlocks::STONE(), false);
				for($y = 1; $y <= 6; ++$y){
					if($random->nextBoundedInt(4) !== 0){
						continue;
					}
					$this->world->setBlockAt($x, $y, $z, $palette[$random->nextBoundedInt(count($palette))], false);
					if($random->nextBoundedInt(6) === 0){
						//water in the liquid layer of the slabs, stairs, fences... that hold some
						WaterloggedHelper::waterlog($this->world->getBlockAt($x, $y, $z));
					}
				}
			}
		}
	}

	/**
	 * The original single-position check: every block through at().
	 */
	private static function referenceStanding(World $world, int $x, int $y, int $z, int $headBlocks, bool $allowWater) : int{
		$feet = PathBlockTypes::at($world, $x, $y, $z);
		if(($feet & (PathBlockTypes::PASSABLE | PathBlockTypes::LOW)) === 0 || ($feet & PathBlockTypes::DANGER) !== 0 || (!$allowWater && ($feet & PathBlockTypes::WATER) !== 0)){
			return -1;
		}
		for($i = 1; $i <= $headBlocks; ++$i){
			$head = PathBlockTypes::at($world, $x, $y + $i, $z);
			if(($head & PathBlockTypes::PASSABLE) === 0 || ($head & PathBlockTypes::DANGER) !== 0){
				return -1;
			}
		}
		if(($feet & (PathBlockTypes::LOW | PathBlockTypes::WATER)) !== 0){
			return 1;
		}
		$below = PathBlockTypes::at($world, $x, $y - 1, $z);
		if(($below & PathBlockTypes::DANGER) !== 0){
			return -1;
		}
		return ($below & PathBlockTypes::FLOOR) !== 0 ? 1 : 0;
	}

	private static function referenceStandable(World $world, int $x, int $y, int $z, int $headBlocks, bool $allowWater, int $footprint) : bool{
		$supported = false;
		for($i = 0; $i < $footprint; ++$i){
			for($j = 0; $j < $footprint; ++$j){
				$cell = self::referenceStanding($world, $x + $i, $y, $z + $j, $headBlocks, $allowWater);
				if($cell < 0){
					return false;
				}
				$supported = $supported || $cell === 1;
			}
		}
		return $supported;
	}

	public function testInColumnMatchesAt() : void{
		$this->terrain(11);
		$world = $this->world;
		$random = new Random(12);
		$layerWater = 0;
		for($i = 0; $i < 4000; ++$i){
			//also unloaded chunks and out of the world's height
			$x = $random->nextRange(-24, 40);
			$z = $random->nextRange(-24, 40);
			$y = $random->nextBoundedInt(4) === 0 ? $random->nextRange($world->getMinY() - 3, $world->getMaxY() + 3) : $random->nextRange(-2, 8);
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			self::assertSame(PathBlockTypes::at($world, $x, $y, $z), PathBlockTypes::inColumn($world, $chunk, $world->getMinY(), $world->getMaxY(), $x, $y, $z), "$x $y $z");
			if($chunk !== null && $y >= $world->getMinY() && $y < $world->getMaxY() && LiquidLayer::hasLayerWaterAt($world, $x, $y, $z)){
				++$layerWater;
			}
		}
		self::assertGreaterThan(0, $layerWater, "some blocks hold water in the liquid layer");
	}

	public function testThreeInSubChunkMatchesAt() : void{
		$this->terrain(41);
		$world = $this->world;
		$random = new Random(42);
		$checked = 0;
		for($i = 0; $i < 4000; ++$i){
			$x = $random->nextRange(-24, 40);
			$z = $random->nextRange(-24, 40);
			//mostly the rows of the terrain, also the empty subchunks above it and the stone floor below
			$y = $random->nextBoundedInt(5) === 0 ? $random->nextRange($world->getMinY() + 1, $world->getMaxY() - 2) : $random->nextRange(-3, 9);
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null || ($y - 1) >> 4 !== ($y + 1) >> 4){
				continue; //not what the callers ask for
			}
			$below = $middle = $above = -1;
			PathBlockTypes::threeInSubChunk($world, $chunk, $x, $y, $z, $below, $middle, $above);
			self::assertSame([PathBlockTypes::at($world, $x, $y - 1, $z), PathBlockTypes::at($world, $x, $y, $z), PathBlockTypes::at($world, $x, $y + 1, $z)], [$below, $middle, $above], "$x $y $z");
			++$checked;
		}
		self::assertGreaterThan(1500, $checked);
	}

	public function testStandableMatchesReference() : void{
		$this->terrain(21);
		$world = $this->world;
		$random = new Random(22);
		for($i = 0; $i < 6000; ++$i){
			$x = $random->nextRange(-20, 34);
			$y = $random->nextRange(-1, 8);
			$z = $random->nextRange(-20, 34);
			$headBlocks = $random->nextBoundedInt(3);
			$allowWater = $random->nextBoolean();
			$footprint = 1 + $random->nextBoundedInt(3);
			self::assertSame(
				self::referenceStandable($world, $x, $y, $z, $headBlocks, $allowWater, $footprint),
				PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, $allowWater, $footprint),
				"$x $y $z head $headBlocks water $allowWater footprint $footprint"
			);
		}
	}

	public function testFindStandableDownMatchesLoop() : void{
		$this->terrain(31);
		$world = $this->world;
		$random = new Random(32);
		$found = 0;
		for($i = 0; $i < 3000; ++$i){
			$x = $random->nextRange(-20, 34);
			$top = $random->nextRange(-6, 12);
			$z = $random->nextRange(-20, 34);
			$count = 1 + $random->nextBoundedInt(16);
			$headBlocks = $random->nextBoundedInt(3);
			$allowWater = $random->nextBoolean();
			$footprint = 1 + $random->nextBoundedInt(3);
			//what RandomPos::getLandPos() did: down until standable, at most $count positions
			$expected = null;
			for($y = $top; $y > $top - $count; --$y){
				if(self::referenceStandable($world, $x, $y, $z, $headBlocks, $allowWater, $footprint)){
					$expected = $y;
					break;
				}
			}
			if($expected !== null){
				++$found;
			}
			self::assertSame($expected, PathBlockTypes::findStandableDown($world, $x, $top, $z, $count, $headBlocks, $allowWater, $footprint), "$x $top $z count $count head $headBlocks water $allowWater footprint $footprint");
		}
		self::assertGreaterThan(300, $found, "the terrain has standable positions");
	}
}
