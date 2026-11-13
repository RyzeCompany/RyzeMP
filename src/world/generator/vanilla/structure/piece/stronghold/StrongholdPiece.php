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

namespace pocketmine\world\generator\vanilla\structure\piece\stronghold;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * Base of the stronghold pieces (vanilla's StrongholdPieces.StrongholdPiece): the small doors between pieces and the
 * creation of the children behind them.
 */
abstract class StrongholdPiece extends OrientedPiece{
	//StrongholdPiece.SmallDoorType
	public const DOOR_OPENING = 0;
	public const DOOR_WOOD = 1;
	public const DOOR_GRATES = 2;
	public const DOOR_IRON = 3;

	private static ?SmoothStoneSelector $selector = null;

	protected int $entryDoor = self::DOOR_OPENING;

	public function __construct(int $genDepth, BoundingBox $box, ?int $direction){
		parent::__construct($box, $genDepth);
		$this->setOrientation($direction);
	}

	/**
	 * StrongholdPieces.SMOOTH_STONE_SELECTOR (stateless, shared).
	 */
	protected static function selector() : SmoothStoneSelector{
		return self::$selector ??= new SmoothStoneSelector();
	}

	/**
	 * StructurePiece.addChildren(): creates the pieces behind this piece's doors (start time).
	 */
	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		//NOOP
	}

	/**
	 * StrongholdPiece.isOkBox()
	 */
	protected static function isOkBox(BoundingBox $box) : bool{
		return $box->minY > 10;
	}

	/**
	 * StrongholdPiece.randomSmallDoor()
	 */
	protected static function randomSmallDoor(LegacyRandom $random) : int{
		return match($random->nextBoundedInt(5)){
			2 => self::DOOR_WOOD,
			3 => self::DOOR_GRATES,
			4 => self::DOOR_IRON,
			default => self::DOOR_OPENING
		};
	}

	/**
	 * StrongholdPiece.generateSmallDoor()
	 */
	protected function generateSmallDoor(StructurePlaceContext $c, BoundingBox $box, int $doorType, int $x, int $y, int $z) : void{
		switch($doorType){
			case self::DOOR_OPENING:
				$caveAir = $c->blocks->caveAir;
				$this->generateBox($c, $box, $x, $y, $z, $x + 3 - 1, $y + 3 - 1, $z, $caveAir, $caveAir, false);
				break;
			case self::DOOR_WOOD:
				$this->generateDoorFrame($c, $box, $x, $y, $z);
				$this->placeBlock($c, $this->state($c, "minecraft:oak_door"), $x + 1, $y, $z, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:oak_door[half=upper]"), $x + 1, $y + 1, $z, $box);
				break;
			case self::DOOR_GRATES:
				$caveAir = $c->blocks->caveAir;
				$west = $this->state($c, "minecraft:iron_bars[west=true]");
				$eastWest = $this->state($c, "minecraft:iron_bars[east=true,west=true]");
				$east = $this->state($c, "minecraft:iron_bars[east=true]");
				$this->placeBlock($c, $caveAir, $x + 1, $y, $z, $box);
				$this->placeBlock($c, $caveAir, $x + 1, $y + 1, $z, $box);
				$this->placeBlock($c, $west, $x, $y, $z, $box);
				$this->placeBlock($c, $west, $x, $y + 1, $z, $box);
				$this->placeBlock($c, $eastWest, $x, $y + 2, $z, $box);
				$this->placeBlock($c, $eastWest, $x + 1, $y + 2, $z, $box);
				$this->placeBlock($c, $eastWest, $x + 2, $y + 2, $z, $box);
				$this->placeBlock($c, $east, $x + 2, $y + 1, $z, $box);
				$this->placeBlock($c, $east, $x + 2, $y, $z, $box);
				break;
			case self::DOOR_IRON:
				$this->generateDoorFrame($c, $box, $x, $y, $z);
				$this->placeBlock($c, $this->state($c, "minecraft:iron_door"), $x + 1, $y, $z, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:iron_door[half=upper]"), $x + 1, $y + 1, $z, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:stone_button[facing=north]"), $x + 2, $y + 1, $z + 1, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:stone_button[facing=south]"), $x + 2, $y + 1, $z - 1, $box);
				break;
		}
	}

	/**
	 * The stone brick frame of the wooden and iron doors.
	 */
	private function generateDoorFrame(StructurePlaceContext $c, BoundingBox $box, int $x, int $y, int $z) : void{
		$bricks = $this->state($c, "minecraft:stone_bricks");
		$this->placeBlock($c, $bricks, $x, $y, $z, $box);
		$this->placeBlock($c, $bricks, $x, $y + 1, $z, $box);
		$this->placeBlock($c, $bricks, $x, $y + 2, $z, $box);
		$this->placeBlock($c, $bricks, $x + 1, $y + 2, $z, $box);
		$this->placeBlock($c, $bricks, $x + 2, $y + 2, $z, $box);
		$this->placeBlock($c, $bricks, $x + 2, $y + 1, $z, $box);
		$this->placeBlock($c, $bricks, $x + 2, $y, $z, $box);
	}

	/**
	 * generateBox() with the smooth stone selector.
	 */
	protected function generateSmoothStoneBox(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, bool $skipAir, FeatureRandom $random) : void{
		$this->generateBoxWithSelector($c, $box, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $skipAir, $random, self::selector());
	}

	/**
	 * generateBox() with one state for the edges and the inside.
	 */
	protected function fill(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, int $state) : void{
		$this->generateBox($c, $box, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $state, $state, false);
	}

	/**
	 * StrongholdPiece.generateSmallDoorChildForward()
	 */
	protected function generateSmallDoorChildForward(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $xOff, int $yOff) : ?StrongholdPiece{
		$b = $this->box;
		return match($this->orientation){
			Direction::NORTH => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX + $xOff, $b->minY + $yOff, $b->minZ - 1, Direction::NORTH, $this->genDepth),
			Direction::SOUTH => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX + $xOff, $b->minY + $yOff, $b->maxZ + 1, Direction::SOUTH, $this->genDepth),
			Direction::WEST => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX - 1, $b->minY + $yOff, $b->minZ + $xOff, Direction::WEST, $this->genDepth),
			Direction::EAST => $start->generation->generateAndAddPiece($start, $builder, $random, $b->maxX + 1, $b->minY + $yOff, $b->minZ + $xOff, Direction::EAST, $this->genDepth),
			default => null
		};
	}

	/**
	 * StrongholdPiece.generateSmallDoorChildLeft()
	 */
	protected function generateSmallDoorChildLeft(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $yOff, int $zOff) : ?StrongholdPiece{
		$b = $this->box;
		return match($this->orientation){
			Direction::NORTH, Direction::SOUTH => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX - 1, $b->minY + $yOff, $b->minZ + $zOff, Direction::WEST, $this->genDepth),
			Direction::WEST, Direction::EAST => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX + $zOff, $b->minY + $yOff, $b->minZ - 1, Direction::NORTH, $this->genDepth),
			default => null
		};
	}

	/**
	 * StrongholdPiece.generateSmallDoorChildRight()
	 */
	protected function generateSmallDoorChildRight(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $yOff, int $zOff) : ?StrongholdPiece{
		$b = $this->box;
		return match($this->orientation){
			Direction::NORTH, Direction::SOUTH => $start->generation->generateAndAddPiece($start, $builder, $random, $b->maxX + 1, $b->minY + $yOff, $b->minZ + $zOff, Direction::EAST, $this->genDepth),
			Direction::WEST, Direction::EAST => $start->generation->generateAndAddPiece($start, $builder, $random, $b->minX + $zOff, $b->minY + $yOff, $b->maxZ + 1, Direction::SOUTH, $this->genDepth),
			default => null
		};
	}
}
