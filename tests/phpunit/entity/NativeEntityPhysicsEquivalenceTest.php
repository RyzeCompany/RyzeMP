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

use pocketmine\block\Block;
use pocketmine\block\Fence;
use pocketmine\block\Thin;
use pocketmine\block\utils\SlabType;
use pocketmine\block\utils\WallConnectionType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Wall;
use pocketmine\entity\ai\MobBlockPhysics;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\Random;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;
use function bin2hex;
use function count;
use function floor;
use function function_exists;
use function get_class;
use function implode;
use function is_array;
use function opcache_get_status;
use function pack;
use const INF;
use const NAN;

/**
 * The collision part of Entity::move() run by chunkutils2 (World::clipEntityMovement()) must give exactly what the PHP
 * code gives: the clipped movement, the moved box, the step up (ySize) and the ground state, compared bit for bit, for
 * random boxes and movements among slabs, stairs, fences, walls, panes, trapdoors and other boxes in random states, on
 * both sides of chunk borders and of zero, at the bottom and the top of the world, next to unloaded chunks, with cold
 * and warm collision box caches, and with blocks changed between the moves.
 */
final class NativeEntityPhysicsEquivalenceTest extends RedstoneTestCase{

	protected function tearDown() : void{
		World::setNativeEntityPhysicsEnabled(true);
		parent::tearDown();
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

	/**
	 * @return Block[]
	 */
	private static function palette() : array{
		return [
			VanillaBlocks::STONE(), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::BOTTOM), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::TOP),
			VanillaBlocks::CARPET(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::COBBLESTONE_WALL(), VanillaBlocks::GLASS_PANE(),
			VanillaBlocks::OAK_STAIRS(), VanillaBlocks::OAK_TRAPDOOR()->setOpen(true), VanillaBlocks::OAK_TRAPDOOR(), VanillaBlocks::SOUL_SAND(),
			VanillaBlocks::CAKE(), VanillaBlocks::HOPPER(), VanillaBlocks::LANTERN(), VanillaBlocks::SCAFFOLDING(), VanillaBlocks::WATER(),
			VanillaBlocks::OAK_DOOR(), VanillaBlocks::CHEST(), VanillaBlocks::SNOW_LAYER(), VanillaBlocks::BAMBOO(), VanillaBlocks::COBWEB()
		];
	}

	private function fillWorld(Random $random) : void{
		$palette = self::palette();
		$air = VanillaBlocks::AIR();
		$minY = $this->world->getMinY();
		$maxY = $this->world->getMaxY() - 1;
		foreach([[1, 6], [$minY, $minY + 3], [$maxY - 3, $maxY]] as [$fromY, $toY]){
			for($x = -30; $x <= 30; ++$x){
				for($z = -30; $z <= 30; ++$z){
					for($y = $fromY; $y <= $toY; ++$y){
						$block = $random->nextBoundedInt(100) < 55 ? $air : self::withRandomConnections($random, $palette[$random->nextBoundedInt(count($palette))]);
						$this->world->setBlockAt($x, $y, $z, $block, false);
					}
				}
			}
		}
	}

	private static function coordinate(Random $random, float $base, float $spread) : float{
		$value = $base + $random->nextSignedFloat() * $spread;
		return match($random->nextBoundedInt(7)){
			0 => floor($value),
			1 => floor($value) + 0.00001,
			2 => floor($value) - 0.000005,
			3 => floor($value) + 0.5,
			4 => floor($value) + 0.1875,
			default => $value
		};
	}

	private static function delta(Random $random, bool $nonFinite) : float{
		return match($random->nextBoundedInt(16)){
			0 => 0.0,
			1 => -0.0,
			2 => $nonFinite ? NAN : 0.5,
			3 => $nonFinite ? INF : -0.0784000015258789,
			4 => $nonFinite ? -INF : 1.0,
			5 => -0.0784000015258789,
			6 => (float) ($random->nextBoundedInt(5) - 2),
			7 => $random->nextSignedFloat() * 3,
			default => $random->nextSignedFloat() * 0.6
		};
	}

	private function box(Random $random, bool $nonFinite) : AxisAlignedBB{
		$level = $random->nextBoundedInt(5);
		$baseY = match($level){
			0 => (float) ($this->world->getMinY() + 1),
			1 => (float) ($this->world->getMaxY() - 2),
			default => 3.0
		};
		$x = self::coordinate($random, 0, 44); //beyond the loaded chunks (-32..47) too
		$y = self::coordinate($random, $baseY, 3);
		$z = self::coordinate($random, 0, 44);
		$width = match($random->nextBoundedInt(4)){
			0 => 0.6,
			1 => 0.9,
			2 => 1.4,
			default => $random->nextFloat() * 3
		};
		$height = match($random->nextBoundedInt(4)){
			0 => 1.8,
			1 => 0.7,
			2 => 2.9,
			default => $random->nextFloat() * 3
		};
		$bb = new AxisAlignedBB($x - $width / 2, $y, $z - $width / 2, $x + $width / 2, $y + $height, $z + $width / 2);
		if($nonFinite && $random->nextBoundedInt(8) === 0){
			$values = [NAN, INF, -INF, -0.0];
			match($random->nextBoundedInt(6)){
				0 => $bb->minX = $values[$random->nextBoundedInt(4)],
				1 => $bb->minY = $values[$random->nextBoundedInt(4)],
				2 => $bb->minZ = $values[$random->nextBoundedInt(4)],
				3 => $bb->maxX = $values[$random->nextBoundedInt(4)],
				4 => $bb->maxY = $values[$random->nextBoundedInt(4)],
				default => $bb->maxZ = $values[$random->nextBoundedInt(4)]
			};
		}
		return $bb;
	}

	private static function f(float $v) : string{
		return bin2hex(pack("e", $v));
	}

	/**
	 * Moves the entity from the given state and returns everything the collision part decides.
	 */
	private function move(NativeClipTestEntity $entity, AxisAlignedBB $bb, float $ySize, bool $onGround, float $stepHeight, float $dx, float $dy, float $dz) : string{
		$entity->boundingBox = clone $bb;
		$entity->setYSizeForTest($ySize);
		$entity->onGround = $onGround;
		$entity->setStepHeight($stepHeight);
		$entity->clip = null;
		try{
			$entity->moveForTest($dx, $dy, $dz);
		}catch(\Throwable $e){
			return "exception " . get_class($e) . ": " . $e->getMessage();
		}
		$box = $entity->boundingBox;
		$clip = $entity->clip ?? [];
		$parts = [];
		foreach($clip as $v){
			$parts[] = self::f($v);
		}
		return "clip " . implode(" ", $parts) . " bb " . self::f($box->minX) . " " . self::f($box->minY) . " " . self::f($box->minZ) . " " .
			self::f($box->maxX) . " " . self::f($box->maxY) . " " . self::f($box->maxZ) . " ySize " . self::f($entity->getYSizeForTest()) .
			" ground " . ($entity->onGround ? 1 : 0) . " collided " . ($entity->isCollidedHorizontally ? 1 : 0) . ($entity->isCollidedVertically ? 1 : 0);
	}

	private function check(int $seed, bool $nonFinite, int $cases) : void{
		if(World::getNativeEntityPhysics() === null){
			self::markTestSkipped("chunkutils2 without the native entity physics (0.3.12+)");
		}
		$random = new Random($seed);
		$this->fillWorld($random);
		$entity = new NativeClipTestEntity(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$palette = self::palette();
		$stepped = 0;
		for($i = 0; $i < $cases; ++$i){
			$bb = $this->box($random, $nonFinite);
			$ySize = $random->nextBoolean() ? 0.0 : $random->nextFloat() * 0.6;
			$onGround = $random->nextBoolean();
			$stepHeight = match($random->nextBoundedInt(5)){
				0 => 0.0,
				1 => 1.0,
				2 => 0.5625,
				3 => $random->nextFloat() * 1.5,
				default => 0.6
			};
			$dx = self::delta($random, $nonFinite);
			$dy = self::delta($random, $nonFinite);
			$dz = self::delta($random, $nonFinite);
			$cold = $random->nextBoundedInt(4) === 0;

			if($cold){
				$this->world->clearCache(true);
			}
			World::setNativeEntityPhysicsEnabled(false);
			$expected = $this->move($entity, $bb, $ySize, $onGround, $stepHeight, $dx, $dy, $dz);
			if($cold){
				$this->world->clearCache(true);
			}
			World::setNativeEntityPhysicsEnabled(true);
			$actual = $this->move($entity, $bb, $ySize, $onGround, $stepHeight, $dx, $dy, $dz);
			self::assertSame($expected, $actual, "case #$i: $bb ySize=$ySize onGround=" . ($onGround ? 1 : 0) . " step=$stepHeight move=$dx $dy $dz cold=" . ($cold ? 1 : 0));
			if($entity->getYSizeForTest() !== $ySize * 0.4){
				++$stepped;
			}

			if($random->nextBoundedInt(20) === 0){
				//a block change around the box (the cache of that cell and its neighbours is invalidated)
				$x = (int) floor($bb->minX) + $random->nextBoundedInt(3) - 1;
				$y = (int) floor($bb->minY) + $random->nextBoundedInt(3) - 1;
				$z = (int) floor($bb->minZ) + $random->nextBoundedInt(3) - 1;
				if($this->world->isInWorld($x, $y, $z) && $this->world->isChunkLoaded($x >> 4, $z >> 4)){
					$this->world->setBlockAt($x, $y, $z, self::withRandomConnections($random, $palette[$random->nextBoundedInt(count($palette))]), false);
				}
			}
		}
		self::assertGreaterThan(0, $stepped, "some moves stepped up");
	}

	public function testSameAsPhp() : void{
		$this->check(20261023, false, 12000);
	}

	/**
	 * The native block lookups (Entity::blockFlagsAt(), PathBlockTypes::at(), MobBlockPhysics::at() and the scan of
	 * Entity::getBlocksAroundWithEntityInsideActions()) find the same flags and the same blocks in the same order, among
	 * liquids, plants holding water, waterlogged blocks, blocks with entity collision and plain ones, on both sides of
	 * chunk borders, at the bottom and top of the world, outside it and next to unloaded chunks.
	 */
	public function testBlockContactSameAsPhp() : void{
		if(World::getNativeEntityPhysics() === null){
			self::markTestSkipped("chunkutils2 without the native entity physics (0.3.12+)");
		}
		$palette = [
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::GLASS(),
			VanillaBlocks::WATER(), VanillaBlocks::WATER()->setDecay(3), VanillaBlocks::WATER()->setFalling(true), VanillaBlocks::LAVA(),
			VanillaBlocks::SEAGRASS(), VanillaBlocks::KELP(), VanillaBlocks::SEA_PICKLE()->setUnderwater(true), VanillaBlocks::OAK_STAIRS(),
			VanillaBlocks::OAK_SLAB(), VanillaBlocks::BUBBLE_COLUMN(), VanillaBlocks::COBWEB(), VanillaBlocks::SWEET_BERRY_BUSH(),
			VanillaBlocks::POWDER_SNOW(), VanillaBlocks::MAGMA(), VanillaBlocks::CACTUS(), VanillaBlocks::SOUL_SAND(), VanillaBlocks::FIRE(),
			VanillaBlocks::LADDER(), VanillaBlocks::SCAFFOLDING(), VanillaBlocks::HONEY_BLOCK(), VanillaBlocks::SLIME(), VanillaBlocks::OAK_LEAVES()
		];
		$random = new Random(4321);
		$minY = $this->world->getMinY();
		$maxY = $this->world->getMaxY() - 1;
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		foreach([[-2, 6], [$minY, $minY + 2], [$maxY - 2, $maxY]] as [$fromY, $toY]){
			for($x = -32; $x <= 36; ++$x){
				for($z = -32; $z <= 36; ++$z){
					for($y = $fromY; $y <= $toY; ++$y){
						$block = $palette[$random->nextBoundedInt(count($palette))];
						$this->world->setBlockAt($x, $y, $z, $block, false);
						if(($block instanceof \pocketmine\block\Stair || $block instanceof \pocketmine\block\Slab) && $random->nextBoolean()){
							$this->world->getChunk($x >> 4, $z >> 4)?->getSubChunk($y >> 4)->setLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf, $water);
						}
					}
				}
			}
		}
		$entity = new NativeClipTestEntity(new Location(0.5, 10, 0.5, $this->world, 0, 0));
		$found = 0;
		for($i = 0; $i < 20000; ++$i){
			$level = $random->nextBoundedInt(4);
			$x = $random->nextSignedFloat() * 50; //the loaded chunks are -32..47
			$y = match($level){
				0 => $minY - 1 + $random->nextFloat() * 4,
				1 => $maxY - 1 + $random->nextFloat() * 4,
				default => -3 + $random->nextFloat() * 11
			};
			$z = $random->nextSignedFloat() * 50;
			$bx = (int) floor($x);
			$by = (int) floor($y);
			$bz = (int) floor($z);

			World::setNativeEntityPhysicsEnabled(false);
			$expectedFlags = [$entity->blockFlagsForTest($bx, $by, $bz), PathBlockTypes::at($this->world, $bx, $by, $bz), MobBlockPhysics::at($this->world, $bx, $by, $bz)];
			World::setNativeEntityPhysicsEnabled(true);
			self::assertSame($expectedFlags, [$entity->blockFlagsForTest($bx, $by, $bz), PathBlockTypes::at($this->world, $bx, $by, $bz), MobBlockPhysics::at($this->world, $bx, $by, $bz)], "flags at $bx $by $bz");

			$width = $random->nextFloat() * 2.5;
			$entity->boundingBox = new AxisAlignedBB($x - $width / 2, $y, $z - $width / 2, $x + $width / 2, $y + $random->nextFloat() * 3, $z + $width / 2);
			World::setNativeEntityPhysicsEnabled(false);
			$expected = $entity->blocksAroundForTest();
			World::setNativeEntityPhysicsEnabled(true);
			self::assertSame($expected, $entity->blocksAroundForTest(), "blocks around " . $entity->boundingBox);
			$found += count($expected);
		}
		self::assertGreaterThan(1000, $found, "the boxes found blocks");
	}

	/**
	 * With NaN, infinite and negative zero values. The tracing JIT of PHP 8.4 doesn't always answer the same for those
	 * (see EntityMoveClipEquivalenceTest), so this is only checked without it.
	 */
	public function testSameAsPhpWithNonFiniteValues() : void{
		$status = function_exists("opcache_get_status") ? opcache_get_status(false) : false;
		if(is_array($status) && ($status["jit"]["on"] ?? false) === true){
			self::markTestSkipped("the JIT answers differently for non-finite values from one call to the next");
		}
		$this->check(777, true, 6000);
	}
}

/**
 * An entity whose movement only does the collision part: block intersections and falls are left out (they would act on
 * the entity differently from one move to the next), and the clipped movement is recorded.
 */
final class NativeClipTestEntity extends Entity implements NeverSavedWithChunkEntity{
	/** @var float[]|null */
	public ?array $clip = null;

	public static function getNetworkTypeId() : string{ return EntityIds::PIG; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.08; }

	protected function checkBlockIntersections() : void{}

	protected function updateFallState(float $distanceThisTick, bool $onGround) : ?float{
		return null;
	}

	protected function checkGroundState(float $wantedX, float $wantedY, float $wantedZ, float $dx, float $dy, float $dz) : void{
		$this->clip = [$dx, $dy, $dz];
		parent::checkGroundState($wantedX, $wantedY, $wantedZ, $dx, $dy, $dz);
	}

	public function moveForTest(float $dx, float $dy, float $dz) : void{
		$this->move($dx, $dy, $dz);
	}

	public function setYSizeForTest(float $ySize) : void{
		$this->ySize = $ySize;
	}

	public function getYSizeForTest() : float{
		return $this->ySize;
	}

	public function blockFlagsForTest(int $x, int $y, int $z) : int{
		return self::blockFlagsAt($this->getWorld(), $x, $y, $z);
	}

	/**
	 * @return string[] the blocks found around the box: class, position and state
	 */
	public function blocksAroundForTest() : array{
		$this->blocksAround = null;
		$result = [];
		foreach($this->getBlocksAroundWithEntityInsideActions() as $block){
			$pos = $block->getPosition();
			$result[] = $block::class . " " . $pos->x . " " . $pos->y . " " . $pos->z . " " . $block->getStateId();
		}
		return $result;
	}
}
