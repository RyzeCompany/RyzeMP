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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use function str_ends_with;

/**
 * Shape updates after structure placement, for the blocks whose state depends on their neighbours: fences, walls,
 * iron bars / glass panes, stairs and wall banners (vanilla's BlockState.updateShape() of FenceBlock, WallBlock,
 * IronBarsBlock, StairBlock and WallBannerBlock, and Block.updateFromNeighbourShapes()). Vanilla runs them:
 *  - for every block of a template placed without a known shape (StructureTemplate.placeInWorld(): all templates
 *    except jigsaw elements), after updateShapeAtEdge() on the faces of the placed blocks;
 *  - for the fences, iron bars, torches and ladders placed by code-built pieces (StructurePiece.SHAPE_CHECK_BLOCKS,
 *    post-processed when the chunk becomes full).
 * Other blocks (torches, ladders, chests, redstone...) are left as placed. "Sturdy face" uses the collision shape.
 */
final class ShapeUpdates{
	private const KIND_NONE = 0;
	private const KIND_FENCE = 1;
	private const KIND_WALL = 2;
	private const KIND_BARS = 3;
	private const KIND_STAIRS = 4;
	private const KIND_WALL_BANNER = 5;

	/** Block.UPDATE_SHAPE_ORDER */
	private const UPDATE_SHAPE_ORDER = [Direction::WEST, Direction::EAST, Direction::NORTH, Direction::SOUTH, Direction::DOWN, Direction::UP];
	private const HORIZONTAL_PROPERTY = [Direction::NORTH => "north", Direction::SOUTH => "south", Direction::WEST => "west", Direction::EAST => "east"];
	private const COUNTER_CLOCKWISE = [Direction::NORTH => Direction::WEST, Direction::WEST => Direction::SOUTH, Direction::SOUTH => Direction::EAST, Direction::EAST => Direction::NORTH];
	/** WallBlock.TEST_SHAPES_WALL (xz rectangles in 1/16): north, south, west, east */
	private const WALL_TEST = [
		Direction::NORTH => [7, 0, 9, 9],
		Direction::SOUTH => [7, 7, 9, 16],
		Direction::WEST => [0, 7, 9, 9],
		Direction::EAST => [7, 7, 16, 9],
	];
	/** WallBlock.TEST_SHAPE_POST */
	private const POST_TEST = [7, 7, 9, 9];

	/** @phpstan-var array<int, int> state => kind */
	private array $kind = [];
	/** @phpstan-var array<int, bool> */
	private array $exception = [];

	public function __construct(
		private JavaBlocks $blocks
	){}

	private function kind(int $state) : int{
		if(isset($this->kind[$state])){
			return $this->kind[$state];
		}
		$b = $this->blocks;
		$name = $b->blockName($state);
		return $this->kind[$state] = match(true){
			$b->isTagged($state, "minecraft:fences") => self::KIND_FENCE,
			$b->isTagged($state, "minecraft:walls") => self::KIND_WALL,
			str_ends_with($name, "_bars") || str_ends_with($name, "glass_pane") => self::KIND_BARS,
			str_ends_with($name, "_stairs") && $b->hasProperty($state, "shape") => self::KIND_STAIRS,
			str_ends_with($name, "_wall_banner") => self::KIND_WALL_BANNER,
			default => self::KIND_NONE
		};
	}

	/** Whether the block's state can change with its neighbours (fences, walls, bars, panes, stairs). */
	public function isShapeDependent(int $state) : bool{
		return $this->kind($state) !== self::KIND_NONE;
	}

	/** Block.isExceptionForConnection() */
	private function isException(int $state) : bool{
		if(isset($this->exception[$state])){
			return $this->exception[$state];
		}
		$b = $this->blocks;
		$name = $b->blockName($state);
		return $this->exception[$state] = $b->isTagged($state, "minecraft:leaves") || $b->isTagged($state, "minecraft:shulker_boxes") ||
			$name === "minecraft:barrier" || $name === "minecraft:carved_pumpkin" || $name === "minecraft:jack_o_lantern" ||
			$name === "minecraft:melon" || $name === "minecraft:pumpkin";
	}

	/** FenceGateBlock.connectsToDirection() for a fence gate state, false for other blocks */
	private function gateConnects(int $state, int $direction) : bool{
		if(!str_ends_with($this->blocks->blockName($state), "_fence_gate")){
			return false;
		}
		$facing = Direction::fromName($this->blocks->getProperty($state, "facing") ?? "north");
		return Direction::AXIS[$facing] !== Direction::AXIS[$direction];
	}

	/**
	 * BlockState.updateShape(direction, neighbour) for the supported blocks; other states are returned unchanged.
	 */
	public function updateShape(FeatureLevel $level, int $state, int $x, int $y, int $z, int $direction, int $neighbour) : int{
		$kind = $this->kind($state);
		if($kind === self::KIND_NONE){
			return $state;
		}
		$b = $this->blocks;
		$horizontal = isset(self::HORIZONTAL_PROPERTY[$direction]);
		$opposite = Direction::OPPOSITE[$direction];
		switch($kind){
			case self::KIND_FENCE:
				if(!$horizontal){
					return $state;
				}
				//FenceBlock.connectsTo(): sturdy face (not an exception), same fence kind or connected gate
				$sameFence = $this->kind($neighbour) === self::KIND_FENCE &&
					$b->isTagged($neighbour, "minecraft:wooden_fences") === $b->isTagged($state, "minecraft:wooden_fences");
				$connects = (!$this->isException($neighbour) && $b->isFaceSturdy($neighbour, $opposite)) || $sameFence || $this->gateConnects($neighbour, $opposite);
				return $b->withBool($state, self::HORIZONTAL_PROPERTY[$direction], $connects);
			case self::KIND_BARS:
				if(!$horizontal){
					return $state;
				}
				//IronBarsBlock.attachsTo()
				$connects = (!$this->isException($neighbour) && $b->isFaceSturdy($neighbour, $opposite)) || $this->kind($neighbour) === self::KIND_BARS || $this->kind($neighbour) === self::KIND_WALL;
				return $b->withBool($state, self::HORIZONTAL_PROPERTY[$direction], $connects);
			case self::KIND_WALL_BANNER:
				//WallBannerBlock: falls without a solid block behind it
				$facing = Direction::fromName($b->getProperty($state, "facing") ?? "north");
				return $direction === Direction::OPPOSITE[$facing] && !$b->isSolid($neighbour) ? $b->air : $state;
			case self::KIND_STAIRS:
				return $horizontal ? $b->with($state, "shape", $this->stairsShape($level, $state, $x, $y, $z)) : $state;
			default:
				return $this->updateWall($level, $state, $x, $y, $z, $direction, $neighbour);
		}
	}

	/** WallBlock.connectsTo() */
	private function wallConnectsTo(int $neighbour, int $opposite) : bool{
		return $this->kind($neighbour) === self::KIND_WALL ||
			(!$this->isException($neighbour) && $this->blocks->isFaceSturdy($neighbour, $opposite)) ||
			$this->kind($neighbour) === self::KIND_BARS || $this->gateConnects($neighbour, $opposite);
	}

	/** WallBlock.updateShape(): side and top updates */
	private function updateWall(FeatureLevel $level, int $state, int $x, int $y, int $z, int $direction, int $neighbour) : int{
		if($direction === Direction::DOWN){
			return $state;
		}
		$b = $this->blocks;
		$connected = [];
		foreach(self::HORIZONTAL_PROPERTY as $side => $property){
			$connected[$side] = ($b->getProperty($state, $property) ?? "none") !== "none";
		}
		if($direction === Direction::UP){
			$top = $neighbour;
		}elseif(isset(self::HORIZONTAL_PROPERTY[$direction])){
			$connected[$direction] = $this->wallConnectsTo($neighbour, Direction::OPPOSITE[$direction]);
			$top = $level->getState($x, $y + 1, $z);
		}else{
			return $state;
		}
		//the parts of the top neighbour's collision shape touching its bottom face (getFaceShape(DOWN)), in 1/16
		$above = [];
		foreach($b->collisionBoxes($top) as [$x0, $y0, $z0, $x1, , $z1]){
			if($y0 <= 0.0){
				$above[] = [$x0 * 16, $z0 * 16, $x1 * 16, $z1 * 16];
			}
		}
		$sides = [];
		foreach(self::HORIZONTAL_PROPERTY as $side => $property){
			$sides[$side] = $connected[$side] ? (self::covered($above, self::WALL_TEST[$side]) ? "tall" : "low") : "none";
			$state = $b->with($state, $property, $sides[$side]);
		}
		//shouldRaisePost()
		if($this->kind($top) === self::KIND_WALL && $b->getProperty($top, "up") === "true"){
			$up = true;
		}else{
			$northNone = $sides[Direction::NORTH] === "none";
			$southNone = $sides[Direction::SOUTH] === "none";
			$westNone = $sides[Direction::WEST] === "none";
			$eastNone = $sides[Direction::EAST] === "none";
			if(($northNone && $southNone && $westNone && $eastNone) || $northNone !== $southNone || $westNone !== $eastNone){
				$up = true;
			}elseif(($sides[Direction::NORTH] === "tall" && $sides[Direction::SOUTH] === "tall") || ($sides[Direction::EAST] === "tall" && $sides[Direction::WEST] === "tall")){
				$up = false;
			}else{
				$up = ($b->hasTag("minecraft:wall_post_override") && $b->isTagged($top, "minecraft:wall_post_override")) || self::covered($above, self::POST_TEST);
			}
		}
		return $b->withBool($state, "up", $up);
	}

	/**
	 * WallBlock.isCovered(): whether the rectangle is inside the face shape (one box covering it; the face shapes of
	 * the blocks found above walls are single boxes or unions of boxes that each cover these small rectangles).
	 * @phpstan-param list<array{float, float, float, float}> $face
	 * @phpstan-param array{int, int, int, int} $rect
	 */
	private static function covered(array $face, array $rect) : bool{
		foreach($face as [$x0, $z0, $x1, $z1]){
			if($x0 <= $rect[0] && $z0 <= $rect[1] && $x1 >= $rect[2] && $z1 >= $rect[3]){
				return true;
			}
		}
		return false;
	}

	/**
	 * StairBlock.getStairsShape(). Stairs whose shape depends on a block outside the chunk being populated keep their
	 * placed shape (reading that block as air would straighten corner stairs at chunk edges).
	 */
	private function stairsShape(FeatureLevel $level, int $state, int $x, int $y, int $z) : string{
		$b = $this->blocks;
		$facing = Direction::fromName($b->getProperty($state, "facing") ?? "north");
		foreach(self::HORIZONTAL_PROPERTY as $side => $_){
			if(!self::inChunk($level, $x + Direction::STEP_X[$side], $z + Direction::STEP_Z[$side])){
				return $b->getProperty($state, "shape") ?? "straight";
			}
		}
		$half = $b->getProperty($state, "half");
		$behind = $this->getState($level, $x + Direction::STEP_X[$facing], $y, $z + Direction::STEP_Z[$facing]);
		if($this->kind($behind) === self::KIND_STAIRS && $b->getProperty($behind, "half") === $half){
			$behindFacing = Direction::fromName($b->getProperty($behind, "facing") ?? "north");
			if(Direction::AXIS[$behindFacing] !== Direction::AXIS[$facing] && $this->canTakeShape($level, $state, $x, $y, $z, Direction::OPPOSITE[$behindFacing])){
				return $behindFacing === self::COUNTER_CLOCKWISE[$facing] ? "outer_left" : "outer_right";
			}
		}
		$opposite = Direction::OPPOSITE[$facing];
		$front = $this->getState($level, $x + Direction::STEP_X[$opposite], $y, $z + Direction::STEP_Z[$opposite]);
		if($this->kind($front) === self::KIND_STAIRS && $b->getProperty($front, "half") === $half){
			$frontFacing = Direction::fromName($b->getProperty($front, "facing") ?? "north");
			if(Direction::AXIS[$frontFacing] !== Direction::AXIS[$facing] && $this->canTakeShape($level, $state, $x, $y, $z, $frontFacing)){
				return $frontFacing === self::COUNTER_CLOCKWISE[$facing] ? "inner_left" : "inner_right";
			}
		}
		return "straight";
	}

	/** StairBlock.canTakeShape() */
	private function canTakeShape(FeatureLevel $level, int $state, int $x, int $y, int $z, int $direction) : bool{
		$b = $this->blocks;
		$neighbour = $this->getState($level, $x + Direction::STEP_X[$direction], $y + Direction::STEP_Y[$direction], $z + Direction::STEP_Z[$direction]);
		return $this->kind($neighbour) !== self::KIND_STAIRS ||
			$b->getProperty($neighbour, "facing") !== $b->getProperty($state, "facing") ||
			$b->getProperty($neighbour, "half") !== $b->getProperty($state, "half");
	}

	/**
	 * Block.updateFromNeighbourShapes() + setBlock() if the state changed.
	 */
	public function updateFromNeighbourShapes(FeatureLevel $level, int $x, int $y, int $z) : void{
		$state = $level->getState($x, $y, $z);
		if($this->kind($state) === self::KIND_NONE){
			return;
		}
		$new = $state;
		foreach(self::UPDATE_SHAPE_ORDER as $direction){
			$nx = $x + Direction::STEP_X[$direction];
			$ny = $y + Direction::STEP_Y[$direction];
			$nz = $z + Direction::STEP_Z[$direction];
			if(!self::inChunk($level, $nx, $nz)){
				continue;
			}
			$new = $this->updateShape($level, $new, $x, $y, $z, $direction, $level->getState($nx, $ny, $nz));
		}
		if($new !== $state){
			$level->setState($x, $y, $z, $new);
		}
	}

	/**
	 * Shape updates only look at the chunk being populated: its neighbours may or may not be populated yet (worker
	 * threads populate in any order), so reading them would make the result depend on the generation order. Blocks at
	 * the chunk edges keep the connections they were placed with on that side.
	 */
	private static function inChunk(FeatureLevel $level, int $x, int $z) : bool{
		return ($x >> 4) === $level->centerChunkX && ($z >> 4) === $level->centerChunkZ;
	}

	/** The state at a position of the chunk being populated, air outside it. */
	private function getState(FeatureLevel $level, int $x, int $y, int $z) : int{
		return self::inChunk($level, $x, $z) ? $level->getState($x, $y, $z) : $this->blocks->air;
	}

	private static function key(int $x, int $y, int $z) : int{
		return (($x & 0xfffff) << 32) | (($z & 0xfffff) << 12) | ($y & 0xfff);
	}

	/**
	 * StructureTemplate.updateShapeAtEdge() + the updateFromNeighbourShapes() pass of placeInWorld() for the blocks a
	 * template placed (positions in placement order).
	 *
	 * @param int[][] $placed
	 * @phpstan-param list<array{int, int, int}> $placed
	 */
	public function afterTemplatePlacement(FeatureLevel $level, array $placed) : void{
		$set = [];
		foreach($placed as [$x, $y, $z]){
			$set[self::key($x, $y, $z)] = true;
		}
		//edges: the faces between placed blocks and the blocks around them, both sides updated
		foreach($placed as [$x, $y, $z]){
			foreach(self::UPDATE_SHAPE_ORDER as $direction){
				$nx = $x + Direction::STEP_X[$direction];
				$ny = $y + Direction::STEP_Y[$direction];
				$nz = $z + Direction::STEP_Z[$direction];
				if(isset($set[self::key($nx, $ny, $nz)]) || !self::inChunk($level, $nx, $nz)){
					continue;
				}
				$state = $level->getState($x, $y, $z);
				$neighbour = $level->getState($nx, $ny, $nz);
				if($this->kind($state) === self::KIND_NONE && $this->kind($neighbour) === self::KIND_NONE){
					continue;
				}
				$new = $this->updateShape($level, $state, $x, $y, $z, $direction, $neighbour);
				if($new !== $state){
					$level->setState($x, $y, $z, $new);
				}
				$newNeighbour = $this->updateShape($level, $neighbour, $nx, $ny, $nz, Direction::OPPOSITE[$direction], $new);
				if($newNeighbour !== $neighbour){
					$level->setState($nx, $ny, $nz, $newNeighbour);
				}
			}
		}
		foreach($placed as [$x, $y, $z]){
			$this->updateFromNeighbourShapes($level, $x, $y, $z);
		}
	}
}
