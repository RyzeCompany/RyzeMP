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
use pocketmine\block\Thin;
use pocketmine\block\utils\DripleafState;
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
use const INF;
use const NAN;

/**
 * World::hasCollisionBlocks() must answer exactly count(World::getCollisionBlocks()) > 0: same cells, same rules for
 * the cells inside the box and for the ring around it, same handling of the world height, of unloaded chunks and of
 * rounding at cell edges.
 */
final class CollisionBlocksEquivalenceTest extends RedstoneTestCase{

	/** Number of assertSameAnswer() calls: every other one runs with an empty block cache. */
	private int $checks = 0;

	protected function getChunkRadius() : int{
		return 3; //x/z -48..63 loaded
	}

	/**
	 * @return Block[]
	 */
	private static function palette() : array{
		$palette = [
			VanillaBlocks::STONE(), VanillaBlocks::GLASS(), VanillaBlocks::MUD(), VanillaBlocks::SOUL_SAND(),
			VanillaBlocks::HONEY_BLOCK(), VanillaBlocks::FARMLAND(), VanillaBlocks::GRASS_PATH(),
			VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::BOTTOM), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::TOP),
			VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::DOUBLE),
			VanillaBlocks::CARPET(), VanillaBlocks::MOSS_CARPET(),
			VanillaBlocks::OAK_FENCE(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::NETHER_BRICK_FENCE(),
			VanillaBlocks::COBBLESTONE_WALL(), VanillaBlocks::COBBLESTONE_WALL(), VanillaBlocks::GLASS_PANE(), VanillaBlocks::IRON_BARS(),
			VanillaBlocks::OAK_FENCE_GATE(), VanillaBlocks::OAK_FENCE_GATE()->setOpen(true),
			VanillaBlocks::OAK_FENCE_GATE()->setFacing(Facing::EAST),
			VanillaBlocks::OAK_TRAPDOOR(), VanillaBlocks::OAK_TRAPDOOR()->setTop(true), VanillaBlocks::OAK_TRAPDOOR()->setOpen(true),
			VanillaBlocks::LADDER(), VanillaBlocks::COBWEB(), VanillaBlocks::TORCH(), VanillaBlocks::WATER(),
			VanillaBlocks::ANVIL(), VanillaBlocks::LANTERN(), VanillaBlocks::LANTERN()->setHanging(true), VanillaBlocks::CAKE(),
			VanillaBlocks::HOPPER(), VanillaBlocks::END_ROD(), VanillaBlocks::CHAIN(), VanillaBlocks::CACTUS(),
			VanillaBlocks::BAMBOO(), VanillaBlocks::POINTED_DRIPSTONE(), VanillaBlocks::SCAFFOLDING(), VanillaBlocks::STONECUTTER(),
			VanillaBlocks::BELL(), VanillaBlocks::STONE_PRESSURE_PLATE(), VanillaBlocks::LILY_PAD(),
			VanillaBlocks::AMETHYST_CLUSTER(), VanillaBlocks::CAULDRON(), VanillaBlocks::COMPOSTER(),
			VanillaBlocks::ENCHANTING_TABLE(), VanillaBlocks::DAYLIGHT_SENSOR(), VanillaBlocks::BREWING_STAND(),
			//blocks whose state is read from the world when they're fetched (neighbours or tile)
			VanillaBlocks::OAK_DOOR(), VanillaBlocks::OAK_DOOR()->setTop(true), VanillaBlocks::OAK_DOOR()->setOpen(true)->setFacing(Facing::EAST),
			VanillaBlocks::CHORUS_PLANT(), VanillaBlocks::CHORUS_PLANT(), VanillaBlocks::END_STONE(),
			VanillaBlocks::BED(), VanillaBlocks::BED()->setHead(true)->setFacing(Facing::EAST),
			VanillaBlocks::OAK_SIGN(), VanillaBlocks::OAK_WALL_SIGN(), VanillaBlocks::FLOWER_POT(),
			VanillaBlocks::MOB_HEAD(), VanillaBlocks::MOB_HEAD()->setFacing(Facing::NORTH), VanillaBlocks::MOB_HEAD()->setFacing(Facing::EAST),
			VanillaBlocks::BIG_DRIPLEAF_HEAD(), VanillaBlocks::BIG_DRIPLEAF_HEAD()->setLeafState(DripleafState::PARTIAL_TILT),
			VanillaBlocks::BIG_DRIPLEAF_HEAD()->setLeafState(DripleafState::FULL_TILT)->setFacing(Facing::WEST),
			VanillaBlocks::SEA_PICKLE(), VanillaBlocks::SEA_PICKLE()->setCount(4),
		];
		foreach([Facing::NORTH, Facing::SOUTH, Facing::EAST, Facing::WEST] as $facing){
			$palette[] = VanillaBlocks::OAK_STAIRS()->setFacing($facing);
			$palette[] = VanillaBlocks::OAK_STAIRS()->setFacing($facing)->setUpsideDown(true);
		}
		for($layers = 1; $layers <= 8; ++$layers){
			$palette[] = VanillaBlocks::SNOW_LAYER()->setLayers($layers);
		}
		return $palette;
	}

	/**
	 * Fences, glass panes, iron bars and walls keep the connections they're placed with (they're part of their state),
	 * so they get random ones: connected arms reach the edges of their cell, and wall posts are taller.
	 */
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
			$block->setPost($random->nextBoolean());
		}
		return $block;
	}

	/**
	 * Fills the given area with blocks of the palette, leaving about $airPercent % of the cells empty.
	 *
	 * @param Block[] $palette
	 */
	private function scatter(Random $random, array $palette, int $airPercent, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : void{
		$air = VanillaBlocks::AIR();
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					$block = $random->nextBoundedInt(100) < $airPercent ? $air : self::withRandomConnections($random, $palette[$random->nextBoundedInt(count($palette))]);
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
	}

	private function assertSameAnswer(AxisAlignedBB $bb, int &$hits, int &$misses) : void{
		if((++$this->checks & 1) === 0){
			//with an empty block cache, so the blocks are created (and their state read from the world) by the call
			//being checked
			$this->world->clearCache(true);
			$actual = $this->world->hasCollisionBlocks($bb);
			$this->world->clearCache(true);
			$expected = count($this->world->getCollisionBlocks($bb, true)) > 0;
			$this->world->clearCache(true);
			$expectedAllBlocks = count($this->world->getCollisionBlocks($bb, false)) > 0;
		}else{
			$expected = count($this->world->getCollisionBlocks($bb, true)) > 0;
			$expectedAllBlocks = count($this->world->getCollisionBlocks($bb, false)) > 0;
			$actual = $this->world->hasCollisionBlocks($bb);
		}
		self::assertSame($expected, $expectedAllBlocks, "$bb");
		self::assertSame($expected, $actual, "$bb");
		if($expected){
			++$hits;
		}else{
			++$misses;
		}
	}

	/**
	 * A coordinate near $base: random, on a cell edge, or a hair away from one.
	 */
	private static function coordinate(Random $random, float $base, float $spread) : float{
		$value = $base + $random->nextSignedFloat() * $spread;
		return match($random->nextBoundedInt(8)){
			0 => floor($value),
			1 => floor($value) + 1e-9,
			2 => floor($value) - 1e-9,
			3 => floor($value) + 0.5,
			4 => floor($value) + 0.0625 * $random->nextBoundedInt(16),
			default => $value
		};
	}

	private static function boxSize(Random $random) : float{
		return match($random->nextBoundedInt(8)){
			0 => 0.0,
			1 => 0.6,
			2 => 0.9,
			3 => 0.4,
			4 => 1.0,
			5 => 2.0,
			default => $random->nextFloat() * 2.5
		};
	}

	/**
	 * @phpstan-param list<array{float, float, float, float, float}> $areas centre x, centre y, centre z, spread xz, spread y
	 * @param int $tallPercent how often a box is up to 40 blocks tall and 6 wide (so its columns cross subchunks)
	 */
	private function checkRandomBoxes(Random $random, array $areas, int $count, int $tallPercent, int &$hits, int &$misses) : void{
		for($i = 0; $i < $count; ++$i){
			[$centreX, $centreY, $centreZ, $spreadXZ, $spreadY] = $areas[$random->nextBoundedInt(count($areas))];
			$x = self::coordinate($random, $centreX, $spreadXZ);
			$y = self::coordinate($random, $centreY, $spreadY);
			$z = self::coordinate($random, $centreZ, $spreadXZ);
			if($random->nextBoundedInt(100) < $tallPercent){
				$width = $random->nextFloat() * 6;
				$depth = $random->nextBoolean() ? $width : $random->nextFloat() * 6;
				$bb = new AxisAlignedBB($x, $y, $z, $x + $width, $y + $random->nextFloat() * 40, $z + $depth);
			}elseif($random->nextBoolean()){
				//the feet box of Player::checkGroundState()
				$bb = new AxisAlignedBB($x - 0.3, $y - ($random->nextBoolean() ? 0.2 : 0.01), $z - 0.3, $x + 0.3, $y + 0.2, $z + 0.3);
				if($random->nextBoolean()){
					$bb = $bb->addCoord($random->nextSignedFloat() * 0.6, $random->nextSignedFloat() * 0.8, $random->nextSignedFloat() * 0.6);
				}
			}else{
				$width = self::boxSize($random);
				$height = self::boxSize($random);
				$depth = $random->nextBoolean() ? $width : self::boxSize($random);
				$bb = new AxisAlignedBB($x, $y, $z, $x + $width, $y + $height, $z + $depth);
			}
			$this->assertSameAnswer($bb, $hits, $misses);
		}
	}

	public function testMatchesGetCollisionBlocks() : void{
		$random = new Random(4242);
		$palette = self::palette();
		//ordinary terrain, sparse and dense
		$this->scatter($random, $palette, 85, -20, -2, -6, -1, 6, 6);
		$this->scatter($random, $palette, 40, 0, -2, -6, 20, 6, 6);
		//the bottom and top of the world
		$this->scatter($random, $palette, 50, -6, -64, -6, 6, -61, 6);
		$this->scatter($random, $palette, 50, -6, 316, -6, 6, 319, 6);
		//next to unloaded chunks (x 64+ and x -49-)
		$this->scatter($random, $palette, 50, 58, -2, -6, 63, 6, 6);
		$this->scatter($random, $palette, 50, -48, -2, -6, -43, 6, 6);

		$hits = 0;
		$misses = 0;
		$this->checkRandomBoxes($random, [
			[-10.0, 2.0, 0.0, 11.0, 5.0],
			[10.0, 2.0, 0.0, 11.0, 5.0],
			[0.0, -63.0, 0.0, 7.0, 3.0],
			[0.0, 319.0, 0.0, 7.0, 3.0],
			[63.5, 2.0, 0.0, 3.0, 5.0],
			[-48.0, 2.0, 0.0, 3.0, 5.0],
		], 20000, 0, $hits, $misses);
		self::assertGreaterThan(5000, $hits);
		self::assertGreaterThan(5000, $misses);
	}

	public function testTallAndWideBoxes() : void{
		$random = new Random(9001);
		$palette = self::palette();
		//sparse terrain across the chunk borders at x/z 15|16 and -17|-16 and the subchunk borders at y 0, 16 and 32
		$this->scatter($random, $palette, 97, 10, -6, 10, 21, 37, 21);
		$this->scatter($random, $palette, 92, -22, -6, -22, -11, 37, -11);
		//next to unloaded chunks (x 64+), and at the bottom and top of the world
		$this->scatter($random, $palette, 90, 58, 6, -3, 63, 22, 3);
		$this->scatter($random, $palette, 85, -4, -64, -4, 4, -56, 4);
		$this->scatter($random, $palette, 85, -4, 311, -4, 4, 319, 4);

		$hits = 0;
		$misses = 0;
		$this->checkRandomBoxes($random, [
			[16.0, 14.0, 16.0, 6.0, 22.0],
			[-16.0, 14.0, -16.0, 6.0, 22.0],
			[63.5, 10.0, 0.0, 3.0, 8.0],
			[0.0, -66.0, 0.0, 5.0, 8.0],
			[0.0, 310.0, 0.0, 5.0, 12.0],
		], 4000, 40, $hits, $misses);
		self::assertGreaterThan(1000, $hits);
		self::assertGreaterThan(1000, $misses);
	}

	public function testGroundUnderTheFeet() : void{
		$random = new Random(77);
		$palette = self::palette();
		$air = VanillaBlocks::AIR();
		$hits = 0;
		$misses = 0;
		//one block of each kind as the ground, the rest air, and players standing around it at many heights
		foreach($palette as $ground){
			$this->world->setBlockAt(0, 0, 0, self::withRandomConnections($random, $ground), false);
			for($i = 0; $i < 200; ++$i){
				$x = self::coordinate($random, 0.5, 1.4);
				$z = self::coordinate($random, 0.5, 1.4);
				$y = self::coordinate($random, 1.0, 1.5);
				$bb = new AxisAlignedBB($x - 0.3, $y - 0.2, $z - 0.3, $x + 0.3, $y + 0.2, $z + 0.3);
				$this->assertSameAnswer($bb, $hits, $misses);
				$this->assertSameAnswer($bb->addCoord(-$random->nextSignedFloat(), -$random->nextSignedFloat(), -$random->nextSignedFloat()), $hits, $misses);
			}
			$this->world->setBlockAt(0, 0, 0, $air, false);
		}
		self::assertGreaterThan(1000, $hits);
		self::assertGreaterThan(1000, $misses);
	}

	public function testCellEdgeRounding() : void{
		$hits = 0;
		$misses = 0;
		//floor(-1e-20) is -1 but floor(-1e-20 - 1) + 1 is 0: the stone at x = -1 is in the ring, where only blocks
		//reaching out of their cell count, so it doesn't collide
		$this->world->setBlockAt(-1, 1, 0, VanillaBlocks::STONE(), false);
		$bb = new AxisAlignedBB(-1e-20, 1.2, 0.2, 0.5, 1.6, 0.8);
		self::assertSame([], $this->world->getCollisionBlocks($bb, true));
		self::assertFalse($this->world->hasCollisionBlocks($bb));
		//a fence there reaches into the box's cells and counts
		$this->world->setBlockAt(-1, 1, 0, VanillaBlocks::OAK_FENCE(), false);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::OAK_FENCE(), false);
		$this->assertSameAnswer($bb, $hits, $misses);
		$this->assertSameAnswer(new AxisAlignedBB(-0.0, 1.2, 0.2, 0.0, 1.6, 0.8), $hits, $misses);
		$this->assertSameAnswer(new AxisAlignedBB(1.0, 1.0, 1.0, 1.0, 1.0, 1.0), $hits, $misses);
		$this->assertSameAnswer(new AxisAlignedBB(-1e-20, -1e-20, -1e-20, 1e-20, 1e-20, 1e-20), $hits, $misses);
		//entirely outside the world height, and entirely in unloaded chunks
		$this->world->setBlockAt(0, -64, 0, VanillaBlocks::STONE(), false);
		$this->world->setBlockAt(0, 319, 0, VanillaBlocks::STONE(), false);
		foreach([-66.5, -65.5, -65.0, -64.9, -64.0, 318.9, 319.5, 320.0, 320.5, 321.0, 322.5] as $y){
			$this->assertSameAnswer(new AxisAlignedBB(0.2, $y - 0.2, 0.2, 0.8, $y + 0.2, 0.8), $hits, $misses);
			$this->assertSameAnswer(new AxisAlignedBB(0.2, $y, 0.2, 0.8, $y, 0.8), $hits, $misses);
		}
		$this->assertSameAnswer(new AxisAlignedBB(70.2, 0.8, 0.2, 70.8, 1.2, 0.8), $hits, $misses);
		$this->assertSameAnswer(new AxisAlignedBB(-60.2, 0.8, 0.2, -59.8, 1.2, 0.8), $hits, $misses);
		self::assertGreaterThan(0, $hits);
		self::assertGreaterThan(0, $misses);
	}

	public function testInvertedAndNonFiniteBoxes() : void{
		//AxisAlignedBB's constructor refuses inverted boxes, but its fields are public and nothing stops NaN or INF
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::OAK_FENCE()->setConnection(Facing::EAST, true), false);
		$this->world->setBlockAt(1, 1, 0, VanillaBlocks::COBBLESTONE_WALL()->setPost(true), false);
		$boxes = [];
		foreach([[0.2, 0.5, 0.2, 0.8, 1.5, 0.8], [0.2, 1.5, 0.2, 0.8, 2.6, 0.8], [0.9, 1.1, 0.1, 1.2, 1.4, 0.9]] as [$minX, $minY, $minZ, $maxX, $maxY, $maxZ]){
			$base = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
			$boxes[] = $base;

			$bb = clone $base;
			[$bb->minY, $bb->maxY] = [$base->maxY, $base->minY];
			$boxes[] = $bb;
			$bb = clone $base;
			[$bb->minX, $bb->maxX] = [$base->maxX, $base->minX];
			$boxes[] = $bb;
			$bb = clone $base;
			$bb->minY = $base->maxY + 0.7;
			$boxes[] = $bb;

			foreach([NAN, INF, -INF] as $value){
				$bb = clone $base;
				$bb->minX = $value;
				$boxes[] = $bb;
				$bb = clone $base;
				$bb->minY = $value;
				$boxes[] = $bb;
				$bb = clone $base;
				$bb->maxY = $value;
				$boxes[] = $bb;
				$bb = clone $base;
				$bb->maxZ = $value;
				$boxes[] = $bb;
			}
		}
		$hits = 0;
		$misses = 0;
		foreach($boxes as $bb){
			$this->assertSameAnswer($bb, $hits, $misses);
			$this->assertSameAnswer($bb, $hits, $misses); //again with the other block cache state
		}
		self::assertGreaterThan(0, $hits);
		self::assertGreaterThan(0, $misses);
	}
}
