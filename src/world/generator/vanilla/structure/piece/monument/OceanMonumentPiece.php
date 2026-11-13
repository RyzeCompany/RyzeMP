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

namespace pocketmine\world\generator\vanilla\structure\piece\monument;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function intdiv;
use function max;
use function min;

/**
 * OceanMonumentPieces.OceanMonumentPiece: the shared helpers of the ocean monument pieces (water boxes, room floors,
 * elder guardians).
 */
abstract class OceanMonumentPiece extends OrientedPiece{
	public const GRIDROOM_WIDTH = 8;
	public const GRIDROOM_DEPTH = 8;
	public const GRIDROOM_HEIGHT = 4;
	public const GRID_WIDTH = 5;
	public const GRID_DEPTH = 5;
	public const GRID_HEIGHT = 3;
	public const GRID_FLOOR_COUNT = 25;
	public const GRID_SIZE = 75;
	/** getRoomIndex(2, 0, 0) */
	public const GRIDROOM_SOURCE_INDEX = 2;
	/** getRoomIndex(2, 2, 0) */
	public const GRIDROOM_TOP_CONNECT_INDEX = 52;
	/** getRoomIndex(0, 1, 0) */
	public const GRIDROOM_LEFTWING_CONNECT_INDEX = 25;
	/** getRoomIndex(4, 1, 0) */
	public const GRIDROOM_RIGHTWING_CONNECT_INDEX = 29;
	public const LEFTWING_INDEX = 1001;
	public const RIGHTWING_INDEX = 1002;
	public const PENTHOUSE_INDEX = 1003;

	public function __construct(
		int $orientation,
		int $genDepth,
		BoundingBox $box,
		private ?RoomDefinition $roomDefinition = null
	){
		parent::__construct($box, $genDepth);
		$this->setOrientation($orientation);
	}

	public static function getRoomIndex(int $roomX, int $roomY, int $roomZ) : int{
		return $roomY * 25 + $roomZ * 5 + $roomX;
	}

	/**
	 * OceanMonumentPiece.makeBoundingBox(orientation, roomDefinition, roomWidth, roomHeight, roomDepth): the box of a
	 * grid room, relative to the building's room grid origin.
	 */
	public static function makeRoomBox(int $orientation, RoomDefinition $definition, int $roomWidth, int $roomHeight, int $roomDepth) : BoundingBox{
		$roomIndex = $definition->index;
		$roomX = $roomIndex % 5;
		$roomZ = intdiv($roomIndex, 5) % 5;
		$roomY = intdiv($roomIndex, 25);
		$box = self::makeBoundingBox(0, 0, 0, $orientation, $roomWidth * 8, $roomHeight * 4, $roomDepth * 8);
		match($orientation){
			Direction::NORTH => $box->move($roomX * 8, $roomY * 4, -($roomZ + $roomDepth) * 8 + 1),
			Direction::SOUTH => $box->move($roomX * 8, $roomY * 4, $roomZ * 8),
			Direction::WEST => $box->move(-($roomZ + $roomDepth) * 8 + 1, $roomY * 4, $roomX * 8),
			default => $box->move($roomZ * 8, $roomY * 4, $roomX * 8)
		};
		return $box;
	}

	/**
	 * The room definition of a grid room piece.
	 */
	protected function room() : RoomDefinition{
		return $this->roomDefinition ?? throw new \LogicException("Not a monument grid room");
	}

	/**
	 * BASE_GRAY (prismarine), BASE_LIGHT (prismarine bricks, also DOT_DECO_DATA), BASE_BLACK (dark prismarine),
	 * LAMP_BLOCK (sea lantern).
	 * @phpstan-return array{int, int, int, int}
	 */
	protected function palette(StructurePlaceContext $c) : array{
		return [
			$this->state($c, "minecraft:prismarine"),
			$this->state($c, "minecraft:prismarine_bricks"),
			$this->state($c, "minecraft:dark_prismarine"),
			$this->state($c, "minecraft:sea_lantern")
		];
	}

	/**
	 * generateBox() with the same edge and fill block, not skipping air.
	 */
	protected function fillBox(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, int $state) : void{
		$this->generateBox($c, $box, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $state, $state, false);
	}

	/**
	 * OceanMonumentPiece.generateWaterBox(): water below sea level and air above it, keeping ice and water (FILL_KEEP).
	 * Positions outside the chunk box read as air and cannot be placed, so only the columns inside the chunk box are
	 * visited (same result as vanilla's full loop, every position being independent).
	 */
	protected function generateWaterBox(StructurePlaceContext $c, BoundingBox $box, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1) : void{
		$blocks = $c->blocks;
		$water = $blocks->water;
		$air = $blocks->air;
		$keep = [
			$blocks->blockIndex("minecraft:ice") => true,
			$blocks->blockIndex("minecraft:packed_ice") => true,
			$blocks->blockIndex("minecraft:blue_ice") => true,
			$blocks->waterBlock => true
		];
		$seaLevel = $c->env->seaLevel;
		$level = $c->level;
		for($x = $x0; $x <= $x1; ++$x){
			for($z = $z0; $z <= $z1; ++$z){
				$wx = $this->getWorldX($x, $z);
				$wz = $this->getWorldZ($x, $z);
				if($wx < $box->minX || $wx > $box->maxX || $wz < $box->minZ || $wz > $box->maxZ){
					continue;
				}
				for($y = $y0; $y <= $y1; ++$y){
					$wy = $this->getWorldY($y);
					if($wy < $box->minY || $wy > $box->maxY){
						continue;
					}
					if(isset($keep[$blocks->blockOf[$level->getState($wx, $wy, $wz)]])){
						continue;
					}
					$this->placeBlock($c, $wy < $seaLevel ? $water : $air, $x, $y, $z, $box);
				}
			}
		}
	}

	/**
	 * OceanMonumentPiece.generateDefaultFloor()
	 */
	protected function generateDefaultFloor(StructurePlaceContext $c, BoundingBox $box, int $xOff, int $zOff, bool $downOpening) : void{
		[$gray, $light] = $this->palette($c);
		if($downOpening){
			$this->fillBox($c, $box, $xOff + 0, 0, $zOff + 0, $xOff + 2, 0, $zOff + 8 - 1, $gray);
			$this->fillBox($c, $box, $xOff + 5, 0, $zOff + 0, $xOff + 8 - 1, 0, $zOff + 8 - 1, $gray);
			$this->fillBox($c, $box, $xOff + 3, 0, $zOff + 0, $xOff + 4, 0, $zOff + 2, $gray);
			$this->fillBox($c, $box, $xOff + 3, 0, $zOff + 5, $xOff + 4, 0, $zOff + 8 - 1, $gray);
			$this->fillBox($c, $box, $xOff + 3, 0, $zOff + 2, $xOff + 4, 0, $zOff + 2, $light);
			$this->fillBox($c, $box, $xOff + 3, 0, $zOff + 5, $xOff + 4, 0, $zOff + 5, $light);
			$this->fillBox($c, $box, $xOff + 2, 0, $zOff + 3, $xOff + 2, 0, $zOff + 4, $light);
			$this->fillBox($c, $box, $xOff + 5, 0, $zOff + 3, $xOff + 5, 0, $zOff + 4, $light);
		}else{
			$this->fillBox($c, $box, $xOff + 0, 0, $zOff + 0, $xOff + 8 - 1, 0, $zOff + 8 - 1, $gray);
		}
	}

	/**
	 * OceanMonumentPiece.generateBoxOnFillOnly(): replaces only the default water state.
	 */
	protected function generateBoxOnFillOnly(StructurePlaceContext $c, BoundingBox $box, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $state) : void{
		$water = $c->blocks->water;
		for($y = $y0; $y <= $y1; ++$y){
			for($x = $x0; $x <= $x1; ++$x){
				for($z = $z0; $z <= $z1; ++$z){
					if($this->getBlock($c, $x, $y, $z, $box) === $water){
						$this->placeBlock($c, $state, $x, $y, $z, $box);
					}
				}
			}
		}
	}

	/**
	 * OceanMonumentPiece.chunkIntersects(): whether the chunk box overlaps a piece-local XZ rectangle.
	 */
	protected function chunkIntersects(BoundingBox $box, int $x0, int $z0, int $x1, int $z1) : bool{
		$wx0 = $this->getWorldX($x0, $z0);
		$wz0 = $this->getWorldZ($x0, $z0);
		$wx1 = $this->getWorldX($x1, $z1);
		$wz1 = $this->getWorldZ($x1, $z1);
		return $box->intersectsXZ(min($wx0, $wx1), min($wz0, $wz1), max($wx0, $wx1), max($wz0, $wz1));
	}

}
