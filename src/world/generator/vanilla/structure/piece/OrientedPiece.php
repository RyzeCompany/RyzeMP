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

namespace pocketmine\world\generator\vanilla\structure\piece;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureBlockEntities;

/**
 * A piece built by code in piece-local coordinates, rotated and mirrored by its orientation (vanilla's
 * StructurePiece helpers: getWorldX/Y/Z, placeBlock, generateBox, fillColumnDown, createChest...).
 */
abstract class OrientedPiece extends StructurePiece{
	/**
	 * StructurePiece.SHAPE_CHECK_BLOCKS that ShapeUpdates handles (torches and ladders are left as placed).
	 */
	private const SHAPE_CHECK_BLOCKS = [
		"minecraft:nether_brick_fence" => true,
		"minecraft:oak_fence" => true,
		"minecraft:spruce_fence" => true,
		"minecraft:dark_oak_fence" => true,
		"minecraft:pale_oak_fence" => true,
		"minecraft:acacia_fence" => true,
		"minecraft:birch_fence" => true,
		"minecraft:jungle_fence" => true,
		"minecraft:iron_bars" => true,
	];

	protected ?int $orientation = null;
	protected int $rotation = Rotations::NONE;
	protected int $mirror = Rotations::MIRROR_NONE;

	/** @phpstan-var array<string, int> parsed states */
	private array $states = [];

	/**
	 * StructurePiece.makeBoundingBox()
	 */
	public static function makeBoundingBox(int $x, int $y, int $z, int $direction, int $width, int $height, int $depth) : BoundingBox{
		return Direction::AXIS[$direction] === 2 ?
			new BoundingBox($x, $y, $z, $x + $width - 1, $y + $height - 1, $z + $depth - 1) :
			new BoundingBox($x, $y, $z, $x + $depth - 1, $y + $height - 1, $z + $width - 1);
	}

	/**
	 * Direction.Plane.HORIZONTAL.getRandomDirection(random): N, E, S, W.
	 */
	public static function randomHorizontalDirection(LegacyRandom|FeatureRandom $random) : int{
		return Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
	}

	protected function setOrientation(?int $direction) : void{
		$this->orientation = $direction;
		[$this->mirror, $this->rotation] = match($direction){
			Direction::SOUTH => [Rotations::MIRROR_LEFT_RIGHT, Rotations::NONE],
			Direction::WEST => [Rotations::MIRROR_LEFT_RIGHT, Rotations::CLOCKWISE_90],
			Direction::EAST => [Rotations::MIRROR_NONE, Rotations::CLOCKWISE_90],
			default => [Rotations::MIRROR_NONE, Rotations::NONE]
		};
	}

	protected function state(StructurePlaceContext $c, string $state) : int{
		return $this->states[$state] ??= $c->blocks->parseState($state);
	}

	protected function getWorldX(int $x, int $z) : int{
		return match($this->orientation){
			Direction::NORTH, Direction::SOUTH => $this->box->minX + $x,
			Direction::WEST => $this->box->maxX - $z,
			Direction::EAST => $this->box->minX + $z,
			default => $x
		};
	}

	protected function getWorldY(int $y) : int{
		return $this->orientation === null ? $y : $y + $this->box->minY;
	}

	protected function getWorldZ(int $x, int $z) : int{
		return match($this->orientation){
			Direction::NORTH => $this->box->maxZ - $z,
			Direction::SOUTH => $this->box->minZ + $z,
			Direction::WEST, Direction::EAST => $this->box->minZ + $x,
			default => $z
		};
	}

	/**
	 * @phpstan-return array{int, int, int}
	 */
	protected function getWorldPos(int $x, int $y, int $z) : array{
		return [$this->getWorldX($x, $z), $this->getWorldY($y), $this->getWorldZ($x, $z)];
	}

	public function getOrientation() : ?int{
		return $this->orientation;
	}

	/**
	 * StructurePiece.canBeReplaced(): whether placeBlock() may replace the block at a piece-local position.
	 */
	protected function canBeReplaced(StructurePlaceContext $c, int $x, int $y, int $z, BoundingBox $box) : bool{
		return true;
	}

	protected function placeBlock(StructurePlaceContext $c, int $state, int $x, int $y, int $z, BoundingBox $box) : void{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $wy, $wz) || !$this->canBeReplaced($c, $x, $y, $z, $box)){
			return;
		}
		$state = Rotations::transformState($c->blocks, $state, $this->mirror, $this->rotation);
		if($c->level->setState($wx, $wy, $wz, $state)){
			if(isset(self::SHAPE_CHECK_BLOCKS[$c->blocks->blockName($state)])){
				$c->postProcess[] = [$wx, $wy, $wz];
			}
			$tile = StructureBlockEntities::create($c->blocks, $state, null);
			if($tile !== null){
				$c->level->setBlockEntity($wx, $wy, $wz, $tile);
			}
		}
	}

	protected function getBlock(StructurePlaceContext $c, int $x, int $y, int $z, BoundingBox $box) : int{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		return $box->isInside($wx, $wy, $wz) ? $c->level->getState($wx, $wy, $wz) : $c->blocks->air;
	}

	protected function generateBox(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, int $edge, int $fill, bool $skipAir) : void{
		for($y = $minY; $y <= $maxY; ++$y){
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					if($skipAir && $c->blocks->isAir($this->getBlock($c, $x, $y, $z, $box))){
						continue;
					}
					if($y !== $minY && $y !== $maxY && $x !== $minX && $x !== $maxX && $z !== $minZ && $z !== $maxZ){
						$this->placeBlock($c, $fill, $x, $y, $z, $box);
					}else{
						$this->placeBlock($c, $edge, $x, $y, $z, $box);
					}
				}
			}
		}
	}

	/**
	 * generateBox() with a BlockSelector.
	 */
	protected function generateBoxWithSelector(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, bool $skipAir, FeatureRandom $random, BlockSelector $selector) : void{
		for($y = $minY; $y <= $maxY; ++$y){
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					if($skipAir && $c->blocks->isAir($this->getBlock($c, $x, $y, $z, $box))){
						continue;
					}
					$edge = $y === $minY || $y === $maxY || $x === $minX || $x === $maxX || $z === $minZ || $z === $maxZ;
					$this->placeBlock($c, $selector->next($c, $random, $x, $y, $z, $edge), $x, $y, $z, $box);
				}
			}
		}
	}

	/**
	 * generateAirBox()
	 */
	protected function generateAirBox(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : void{
		$air = $c->blocks->air;
		for($y = $minY; $y <= $maxY; ++$y){
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->placeBlock($c, $air, $x, $y, $z, $box);
				}
			}
		}
	}

	/**
	 * generateUpperHalfSphere() (float maths like vanilla)
	 */
	protected function generateUpperHalfSphere(StructurePlaceContext $c, BoundingBox $box, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $state, bool $skipAir) : void{
		$diagX = FloatMath::fround($x1 - $x0 + 1);
		$diagY = FloatMath::fround($y1 - $y0 + 1);
		$diagZ = FloatMath::fround($z1 - $z0 + 1);
		$cx = FloatMath::fround($x0 + $diagX / 2.0);
		$cz = FloatMath::fround($z0 + $diagZ / 2.0);
		for($y = $y0; $y <= $y1; ++$y){
			$ny = FloatMath::fround(($y - $y0) / $diagY);
			for($x = $x0; $x <= $x1; ++$x){
				$nx = FloatMath::fround(FloatMath::fround($x - $cx) / FloatMath::fround($diagX * 0.5));
				for($z = $z0; $z <= $z1; ++$z){
					$nz = FloatMath::fround(FloatMath::fround($z - $cz) / FloatMath::fround($diagZ * 0.5));
					if($skipAir && $c->blocks->isAir($this->getBlock($c, $x, $y, $z, $box))){
						continue;
					}
					$dist = FloatMath::fround(FloatMath::fround(FloatMath::fround($nx * $nx) + FloatMath::fround($ny * $ny)) + FloatMath::fround($nz * $nz));
					if($dist <= 1.0499999523162842){
						$this->placeBlock($c, $state, $x, $y, $z, $box);
					}
				}
			}
		}
	}

	/**
	 * StructurePiece.isInterior(): whether the block above the piece-local position is below the ocean floor
	 * heightmap (only inside the chunk box).
	 */
	protected function isInterior(StructurePlaceContext $c, int $x, int $y, int $z, BoundingBox $box) : bool{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y + 1, $z);
		return $box->isInside($wx, $wy, $wz) && $wy < $c->level->getHeight(FeatureLevel::OCEAN_FLOOR_WG, $wx, $wz);
	}

	/**
	 * StructurePiece.createDispenser()
	 */
	protected function createDispenser(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, int $x, int $y, int $z, int $facing, string $lootTable) : bool{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $wy, $wz) || $c->blocks->blockName($c->level->getState($wx, $wy, $wz)) === "minecraft:dispenser"){
			return false;
		}
		$this->placeBlock($c, $c->blocks->with($c->blocks->defaultOf("minecraft:dispenser"), "facing", Direction::NAMES[$facing]), $x, $y, $z, $box);
		$c->level->setBlockEntity($wx, $wy, $wz, BlockEntityData::lootContainer("Dispenser", $lootTable, $random->nextLong()));
		return true;
	}

	/**
	 * Places a mob spawner of the given Java entity at a piece-local position (placeBlock + SpawnerBlockEntity
	 * .setEntityId(), which consumes no randomness).
	 */
	protected function placeSpawner(StructurePlaceContext $c, BoundingBox $box, int $x, int $y, int $z, string $javaEntity) : void{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $wy, $wz)){
			return;
		}
		$this->placeBlock($c, $c->blocks->defaultOf("minecraft:spawner"), $x, $y, $z, $box);
		$c->level->setBlockEntity($wx, $wy, $wz, BlockEntityData::spawner($javaEntity));
	}

	/**
	 * generateMaybeBox(): like generateBox, each block placed with a probability; $requireNonAir skips air blocks,
	 * $requireSkylight (vanilla hasToBeInside) skips blocks that are not interior (isInterior()).
	 */
	protected function generateMaybeBox(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, float $probability, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, int $edge, int $fill, bool $requireNonAir, bool $requireSkylight) : void{
		for($y = $minY; $y <= $maxY; ++$y){
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					if(
						$random->nextFloat() <= $probability &&
						(!$requireNonAir || !$c->blocks->isAir($this->getBlock($c, $x, $y, $z, $box))) &&
						(!$requireSkylight || $this->isInterior($c, $x, $y, $z, $box))
					){
						if($y !== $minY && $y !== $maxY && $x !== $minX && $x !== $maxX && $z !== $minZ && $z !== $maxZ){
							$this->placeBlock($c, $fill, $x, $y, $z, $box);
						}else{
							$this->placeBlock($c, $edge, $x, $y, $z, $box);
						}
					}
				}
			}
		}
	}

	protected function maybeGenerateBlock(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, float $probability, int $x, int $y, int $z, int $state) : void{
		if($random->nextFloat() < $probability){
			$this->placeBlock($c, $state, $x, $y, $z, $box);
		}
	}

	/**
	 * StructurePiece.isReplaceableByStructures()
	 */
	protected static function isReplaceableByStructures(JavaBlocks $blocks, int $state) : bool{
		$block = $blocks->blockOf[$state];
		if($blocks->isAir($state) || $block === $blocks->waterBlock || $block === $blocks->lavaBlock){
			return true;
		}
		$name = $blocks->blockName($state);
		return $name === "minecraft:glow_lichen" || $name === "minecraft:seagrass" || $name === "minecraft:tall_seagrass";
	}

	protected function fillColumnDown(StructurePlaceContext $c, int $state, int $x, int $y, int $z, BoundingBox $box) : void{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $wy, $wz)){
			return;
		}
		$level = $c->level;
		while(self::isReplaceableByStructures($c->blocks, $level->getState($wx, $wy, $wz)) && $wy > $level->minY + 1){
			$level->setState($wx, $wy, $wz, $state);
			--$wy;
		}
	}

	/**
	 * StructurePiece.reorient(): a chest facing away from the only solid neighbour.
	 */
	protected static function reorient(StructurePlaceContext $c, int $x, int $y, int $z, int $state) : int{
		$blocks = $c->blocks;
		$level = $c->level;
		$chest = $blocks->blockIndex("chest");
		$found = null;
		foreach(Direction::PLANE_HORIZONTAL as $direction){
			$neighbour = $level->getState($x + Direction::STEP_X[$direction], $y, $z + Direction::STEP_Z[$direction]);
			if($blocks->blockOf[$neighbour] === $chest){
				return $state;
			}
			if($blocks->isFullCube($neighbour) && $blocks->isSolid($neighbour)){
				if($found !== null){
					$found = null;
					break;
				}
				$found = $direction;
			}
		}
		if($found !== null){
			return $blocks->with($state, "facing", Direction::NAMES[Direction::OPPOSITE[$found]]);
		}
		$facing = Direction::fromName($blocks->getProperty($state, "facing") ?? "north");
		$solid = fn(int $d) : bool => $blocks->isFullCube($s = $level->getState($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d])) && $blocks->isSolid($s);
		if($solid($facing)){
			$facing = Direction::OPPOSITE[$facing];
		}
		if($solid($facing)){
			$facing = Direction::clockWise($facing);
		}
		if($solid($facing)){
			$facing = Direction::OPPOSITE[$facing];
		}
		return $blocks->with($state, "facing", Direction::NAMES[$facing]);
	}

	/**
	 * StructurePiece.createChest() at piece-local coordinates.
	 */
	protected function createChest(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, int $x, int $y, int $z, string $lootTable, ?int $state = null) : bool{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		return self::createChestAt($c, $box, $random, $wx, $wy, $wz, $lootTable, $state);
	}

	/**
	 * StructurePiece.createChest() at world coordinates.
	 */
	public static function createChestAt(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, int $x, int $y, int $z, string $lootTable, ?int $state = null) : bool{
		$blocks = $c->blocks;
		if(!$box->isInside($x, $y, $z) || $blocks->blockName($c->level->getState($x, $y, $z)) === "minecraft:chest"){
			return false;
		}
		$state ??= self::reorient($c, $x, $y, $z, $blocks->defaultOf("minecraft:chest"));
		$c->level->setState($x, $y, $z, $state);
		$c->level->setBlockEntity($x, $y, $z, BlockEntityData::lootContainer("Chest", $lootTable, $random->nextLong()));
		return true;
	}

}
