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

namespace pocketmine\world\structure;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Door;
use pocketmine\block\Jigsaw;
use pocketmine\block\Lever;
use pocketmine\block\Rail;
use pocketmine\block\utils\LeverFacing;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Wood;
use pocketmine\data\bedrock\block\BlockLegacyMetadata;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use function max;
use function min;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class StructureTransformTest extends TestCase{

	public function testPositionsKeepTheSmallestCorner() : void{
		$transform = new StructureTransform(3, 4, StructureMirror::NONE, StructureRotation::CLOCKWISE_90);
		self::assertSame(4, $transform->getTransformedSizeX());
		self::assertSame(3, $transform->getTransformedSizeZ());
		$minX = $minZ = PHP_INT_MAX;
		$maxX = $maxZ = PHP_INT_MIN;
		for($x = 0; $x < 3; ++$x){
			for($z = 0; $z < 4; ++$z){
				[$tx, $tz] = $transform->transformBlockPosition($x, $z);
				$minX = min($minX, $tx);
				$maxX = max($maxX, $tx);
				$minZ = min($minZ, $tz);
				$maxZ = max($maxZ, $tz);
			}
		}
		self::assertSame([0, 3], [$minX, $maxX]);
		self::assertSame([0, 2], [$minZ, $maxZ]);
	}

	public function testFacingsAndRotations() : void{
		$transform = new StructureTransform(5, 5, StructureMirror::X, StructureRotation::CLOCKWISE_90);
		//mirror X flips Z: north <-> south; then clockwise
		self::assertSame(Facing::WEST, $transform->transformFacing(Facing::NORTH));
		self::assertSame(Facing::SOUTH, $transform->transformFacing(Facing::EAST));
		self::assertSame(Facing::UP, $transform->transformFacing(Facing::UP));
		//yaw 0 = south: mirrored to north (180), turned to west... 270? north turned clockwise is east (270)
		self::assertEqualsWithDelta(270.0, $transform->transformYaw(0.0), 0.0001);
		//sign rotation 0 = south: same as yaw / 22.5
		self::assertSame(12, $transform->transformSignRotation(0));
		self::assertSame(4, (new StructureTransform(1, 1, StructureMirror::NONE, StructureRotation::CLOCKWISE_90))->transformSignRotation(0));
	}

	public function testBlocks() : void{
		$turn = new StructureTransform(1, 1, StructureMirror::NONE, StructureRotation::CLOCKWISE_90);
		$log = $turn->transformBlock(VanillaBlocks::OAK_LOG()->setAxis(Axis::X));
		self::assertInstanceOf(Wood::class, $log);
		self::assertSame(Axis::Z, $log->getAxis());

		$rail = $turn->transformBlock(VanillaBlocks::RAIL()->setShape(BlockLegacyMetadata::RAIL_ASCENDING_EAST));
		self::assertInstanceOf(Rail::class, $rail);
		self::assertSame(BlockLegacyMetadata::RAIL_ASCENDING_SOUTH, $rail->getShape());
		$curve = $turn->transformBlock(VanillaBlocks::RAIL()->setShape(BlockLegacyMetadata::RAIL_CURVE_NORTHEAST));
		self::assertInstanceOf(Rail::class, $curve);
		self::assertSame(BlockLegacyMetadata::RAIL_CURVE_SOUTHEAST, $curve->getShape());

		$lever = $turn->transformBlock(VanillaBlocks::LEVER()->setFacing(LeverFacing::UP_AXIS_X));
		self::assertInstanceOf(Lever::class, $lever);
		self::assertSame(LeverFacing::UP_AXIS_Z, $lever->getFacing());

		$mirror = new StructureTransform(1, 1, StructureMirror::Z, StructureRotation::NONE);
		$door = $mirror->transformBlock(VanillaBlocks::OAK_DOOR()->setFacing(Facing::EAST)->setHingeRight(false));
		self::assertInstanceOf(Door::class, $door);
		self::assertSame(Facing::WEST, $door->getFacing());
		self::assertTrue($door->isHingeRight(), "a mirror swaps the hinge");

		$jigsaw = $turn->transformBlock(VanillaBlocks::JIGSAW()->setFacing(Facing::UP)->setRotation(0));
		self::assertInstanceOf(Jigsaw::class, $jigsaw);
		self::assertSame(Facing::UP, $jigsaw->getFacing());
		self::assertSame(Facing::EAST, Jigsaw::TOP_DIRECTIONS[$jigsaw->getRotation()], "the top of a vertical jigsaw turns");

		$identity = new StructureTransform(2, 2);
		$stone = VanillaBlocks::STONE();
		self::assertSame($stone, $identity->transformBlock($stone));
	}
}
