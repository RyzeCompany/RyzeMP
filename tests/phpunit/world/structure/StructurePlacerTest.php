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
use pocketmine\block\Chest;
use pocketmine\block\Furnace;
use pocketmine\block\Stair;
use pocketmine\block\utils\StairShape;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_filter;
use function count;

/**
 * Loading structure templates rotated and mirrored, compared block by block with what BDS 1.26.52 placed for
 * "/structure load t1 <pos> <rotation> <mirror>" (the result areas were saved with 2 blocks of margin before the load
 * position on X and Z).
 */
final class StructurePlacerTest extends TestCase{

	/**
	 * @return \Generator<string, array{string, StructureMirror, StructureRotation}>
	 */
	public static function bdsLoadProvider() : \Generator{
		yield "no rotation" => ["res_r0", StructureMirror::NONE, StructureRotation::NONE];
		yield "90 degrees" => ["res_r90", StructureMirror::NONE, StructureRotation::CLOCKWISE_90];
		yield "180 degrees" => ["res_r180", StructureMirror::NONE, StructureRotation::CLOCKWISE_180];
		yield "270 degrees" => ["res_r270", StructureMirror::NONE, StructureRotation::CLOCKWISE_270];
		yield "mirror x" => ["res_mx", StructureMirror::X, StructureRotation::NONE];
		yield "mirror z" => ["res_mz", StructureMirror::Z, StructureRotation::NONE];
		yield "mirror xz" => ["res_mxz", StructureMirror::XZ, StructureRotation::NONE];
		yield "90 degrees mirror x" => ["res_r90mx", StructureMirror::X, StructureRotation::CLOCKWISE_90];
	}

	/**
	 * @dataProvider bdsLoadProvider
	 */
	public function testMatchesBdsLoad(string $result, StructureMirror $mirror, StructureRotation $rotation) : void{
		$template = StructureTemplateTest::bds("t1");
		$expected = StructureTemplateTest::bds($result);
		$placer = new StructurePlacer($template, 2, 0, 2, new StructurePlaceSettings($mirror, $rotation, includeEntities: false));
		$placed = [];
		foreach($placer->blocks() as [$px, $py, $pz, $paletteIndex]){
			$placed["$px:$py:$pz"] = $placer->getBlock($paletteIndex)->getStateId();
		}
		self::assertCount(23, $placed, "every block but the structure void is placed");

		$air = VanillaBlocks::AIR()->getStateId();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$upgrader = GlobalBlockStateHandlers::getUpgrader();
		for($x = 0; $x < $expected->getSizeX(); ++$x){
			for($y = 0; $y < $expected->getSizeY(); ++$y){
				for($z = 0; $z < $expected->getSizeZ(); ++$z){
					$paletteIndex = $expected->getPaletteIndex(StructureTemplate::index($x, $y, $z, $expected->getSizeY(), $expected->getSizeZ()));
					self::assertNotNull($paletteIndex);
					$expectedState = $deserializer->deserialize($upgrader->upgradeBlockStateNbt($expected->getPalette()[$paletteIndex]));
					self::assertSame($expectedState, $placed["$x:$y:$z"] ?? $air, "block at $x $y $z");
				}
			}
		}
	}

	/**
	 * A structure block of BDS in load mode (offset 1 0 1, rotation 90, powered by a redstone block west of it) loads
	 * like the command: the smallest corner of the rotated structure is at the offset.
	 */
	public function testMatchesBdsStructureBlockLoad() : void{
		$template = StructureTemplateTest::bds("t1");
		//the saved area starts at the redstone block; the structure block is next to it
		$expected = StructureTemplateTest::bds("res_sbl_r90");
		$placer = new StructurePlacer($template, 1 + 1, 0, 0 + 1, new StructurePlaceSettings(StructureMirror::NONE, StructureRotation::CLOCKWISE_90, includeEntities: false));
		$placed = [];
		foreach($placer->blocks() as [$px, $py, $pz, $paletteIndex]){
			$placed["$px:$py:$pz"] = $placer->getBlock($paletteIndex)->getStateId();
		}
		$air = VanillaBlocks::AIR()->getStateId();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$upgrader = GlobalBlockStateHandlers::getUpgrader();
		for($x = 0; $x < $expected->getSizeX(); ++$x){
			for($y = 0; $y < $expected->getSizeY(); ++$y){
				for($z = 0; $z < $expected->getSizeZ(); ++$z){
					if($y === 0 && $z === 0 && $x <= 1){
						continue; //redstone block and structure block
					}
					$paletteIndex = $expected->getPaletteIndex(StructureTemplate::index($x, $y, $z, $expected->getSizeY(), $expected->getSizeZ()));
					self::assertNotNull($paletteIndex);
					$expectedState = $deserializer->deserialize($upgrader->upgradeBlockStateNbt($expected->getPalette()[$paletteIndex]));
					self::assertSame($expectedState, $placed["$x:$y:$z"] ?? $air, "block at $x $y $z");
				}
			}
		}
	}

	/**
	 * Block entities move with their block (BDS keeps the world coordinates in the data).
	 */
	public function testBlockEntitiesMove() : void{
		$template = StructureTemplateTest::bds("t1");
		$placer = new StructurePlacer($template, 100, 64, 200, new StructurePlaceSettings(StructureMirror::NONE, StructureRotation::CLOCKWISE_90));
		$tiles = [];
		foreach($placer->blocks() as [$x, $y, $z, , , $blockEntity]){
			if($blockEntity !== null){
				$tiles[$blockEntity->getString("id")] = [$x, $y, $z, $blockEntity->getInt("x"), $blockEntity->getInt("y"), $blockEntity->getInt("z")];
			}
		}
		//BDS res_r90: furnace at relative (1, 0, 1), chest at (0, 1, 2)
		self::assertSame([101, 64, 201, 101, 64, 201], $tiles["Furnace"]);
		self::assertSame([100, 65, 202, 100, 65, 202], $tiles["Chest"]);
	}

	public function testEntitiesMoveAndTurn() : void{
		$template = StructureTemplateTest::bds("t1");
		$placer = new StructurePlacer($template, 10, 64, 20, new StructurePlaceSettings(StructureMirror::NONE, StructureRotation::CLOCKWISE_90));
		$entities = $placer->entities();
		self::assertCount(1, $entities);
		$pos = $entities[0]->getListTag("Pos");
		self::assertNotNull($pos);
		//the armor stand stood at (1.5, 0, 0.5) in the 3x2x4 template: 90 degrees clockwise -> (4 - 0.5, 0, 1.5)
		self::assertEqualsWithDelta([13.5, 64.0, 21.5], $pos->getAllValues(), 0.0001);
		self::assertFalse($entities[0]->getTag("UniqueID") !== null, "unique IDs are not kept");
		$rotation = $entities[0]->getListTag("Rotation");
		self::assertNotNull($rotation);
		self::assertEqualsWithDelta(90.0, $rotation->getAllValues()[0], 0.0001);

		$none = new StructurePlacer($template, 0, 0, 0, new StructurePlaceSettings(includeEntities: false));
		self::assertCount(0, $none->entities());
	}

	public function testRemoveBlocksPlacesNothing() : void{
		$placer = new StructurePlacer(StructureTemplateTest::bds("t1"), 0, 0, 0, new StructurePlaceSettings(includeBlocks: false));
		self::assertCount(0, [...$placer->blocks()]);
		self::assertCount(1, $placer->entities());
	}

	public function testIntegrityIsSeeded() : void{
		$template = StructureTemplateTest::bds("t1");
		$positions = function(int $seed) use ($template) : array{
			$placer = new StructurePlacer($template, 0, 0, 0, new StructurePlaceSettings(integrity: 50.0, seed: $seed));
			$result = [];
			foreach($placer->blocks() as [$x, $y, $z]){
				$result[] = "$x:$y:$z";
			}
			return $result;
		};
		$a = $positions(1234);
		self::assertSame($a, $positions(1234), "the same seed places the same blocks");
		self::assertLessThan(23, count($a));
		self::assertGreaterThan(0, count($a));
		$none = new StructurePlacer($template, 0, 0, 0, new StructurePlaceSettings(integrity: 0.0, seed: 1));
		self::assertCount(0, [...$none->blocks()]);
	}

	/**
	 * A 3x3x3 build saved and loaded rotated and mirrored: positions and facings.
	 */
	public function testBuildRotatedAndMirrored() : void{
		$builder = new StructureTemplateBuilder(3, 3, 3);
		$stone = VanillaBlocks::STONE()->getStateId();
		for($x = 0; $x < 3; ++$x){
			for($z = 0; $z < 3; ++$z){
				$builder->setBlock($x, 0, $z, $stone);
			}
		}
		$builder->setBlock(0, 1, 0, VanillaBlocks::FURNACE()->setFacing(Facing::EAST)->getStateId());
		$builder->setBlock(2, 1, 0, VanillaBlocks::OAK_STAIRS()->setFacing(Facing::NORTH)->setShape(StairShape::OUTER_LEFT)->getStateId());
		$builder->setBlock(1, 2, 2, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH)->getStateId());
		$template = $builder->build();
		self::assertCount(12, array_filter($template->getBlocks(), fn(int $i) => $i >= 0));

		$placer = new StructurePlacer($template, 0, 0, 0, new StructurePlaceSettings(StructureMirror::Z, StructureRotation::CLOCKWISE_90));
		$blocks = [];
		foreach($placer->blocks() as [$x, $y, $z, $paletteIndex]){
			$blocks["$x:$y:$z"] = $placer->getBlock($paletteIndex);
		}
		self::assertCount(12, $blocks);
		//mirror Z flips X (x -> 2 - x), then 90 degrees clockwise: (x, z) -> (2 - z, x)
		$furnace = $blocks["2:1:2"] ?? null;
		self::assertInstanceOf(Furnace::class, $furnace);
		self::assertSame(Facing::NORTH, $furnace->getFacing(), "east, mirrored to west, turned clockwise to north");
		$stairs = $blocks["2:1:0"] ?? null;
		self::assertInstanceOf(Stair::class, $stairs);
		self::assertSame(Facing::EAST, $stairs->getFacing());
		self::assertSame(StairShape::OUTER_RIGHT, $stairs->getShape(), "a mirror swaps left and right");
		$chest = $blocks["0:2:1"] ?? null;
		self::assertInstanceOf(Chest::class, $chest);
		self::assertSame(Facing::WEST, $chest->getFacing());
	}

	public function testMobHeadRotationMoves() : void{
		$builder = new StructureTemplateBuilder(1, 1, 1);
		$builder->setBlock(0, 0, 0, VanillaBlocks::MOB_HEAD()->setFacing(Facing::UP)->setRotation(0)->getStateId());
		$builder->setBlockEntity(0, 0, 0, CompoundTag::create()->setString("id", "Skull")->setFloat("Rotation", 0.0)->setInt("x", 0)->setInt("y", 0)->setInt("z", 0));
		$placer = new StructurePlacer($builder->build(), 0, 0, 0, new StructurePlaceSettings(StructureMirror::NONE, StructureRotation::CLOCKWISE_180));
		foreach($placer->blocks() as [, , , , , $blockEntity]){
			self::assertNotNull($blockEntity);
			$rotation = $blockEntity->getTag("Rotation");
			self::assertInstanceOf(FloatTag::class, $rotation);
			self::assertEqualsWithDelta(180.0, $rotation->getValue(), 0.0001);
		}
	}
}
