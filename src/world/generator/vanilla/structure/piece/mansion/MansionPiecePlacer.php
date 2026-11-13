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

namespace pocketmine\world\generator\vanilla\structure\piece\mansion;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function count;

/**
 * vanilla's WoodlandMansionPieces.MansionPiecePlacer (and generateMansion()): turns the grid layout into template
 * pieces: outer walls, roofs, corridors, inner walls and doors, and the rooms of each floor.
 *
 * Positions are [x, y, z] (vanilla's BlockPos), rotations the Rotations constants.
 */
final class MansionPiecePlacer{
	private int $startX = 0;
	private int $startY = 0;
	/**
	 * @var WoodlandMansionPiece[]
	 * @phpstan-var list<WoodlandMansionPiece>
	 */
	private array $pieces = [];

	public function __construct(
		private StructureTemplates $templates,
		private LegacyRandom $random
	){}

	/**
	 * WoodlandMansionPieces.generateMansion()
	 * @phpstan-param array{int, int, int} $origin
	 * @phpstan-return list<WoodlandMansionPiece>
	 */
	public static function generateMansion(StructureTemplates $templates, array $origin, int $rotation, LegacyRandom $random) : array{
		$grid = new MansionGrid($random);
		$placer = new MansionPiecePlacer($templates, $random);
		$placer->createMansion($origin, $rotation, $grid);
		return $placer->pieces;
	}

	/**
	 * BlockPos.relative(direction, steps)
	 * @phpstan-param array{int, int, int} $pos
	 * @phpstan-return array{int, int, int}
	 */
	private static function relative(array $pos, int $direction, int $steps) : array{
		return [$pos[0] + Direction::STEP_X[$direction] * $steps, $pos[1] + Direction::STEP_Y[$direction] * $steps, $pos[2] + Direction::STEP_Z[$direction] * $steps];
	}

	/**
	 * @phpstan-param array{int, int, int} $pos
	 * @phpstan-return array{int, int, int}
	 */
	private static function above(array $pos, int $steps) : array{
		return [$pos[0], $pos[1] + $steps, $pos[2]];
	}

	/**
	 * Rotation.rotate(direction)
	 */
	private static function rot(int $rotation, int $direction) : int{
		return Rotations::rotateDirection($direction, $rotation);
	}

	/**
	 * @phpstan-param array{int, int, int} $pos
	 */
	private function add(string $templateName, array $pos, int $rotation, int $mirror = Rotations::MIRROR_NONE) : void{
		$this->pieces[] = new WoodlandMansionPiece($this->templates, $templateName, $pos[0], $pos[1], $pos[2], $rotation, $mirror);
	}

	/**
	 * The position of a grid cell relative to an origin (the repeated relative(SOUTH, 8 + (y - startY) * 8)
	 * .relative(EAST, (x - startX) * 8 + eastOffset) of vanilla).
	 * @phpstan-param array{int, int, int} $origin
	 * @phpstan-return array{int, int, int}
	 */
	private function cellPos(array $origin, int $rotation, int $x, int $y, int $eastOffset = 0) : array{
		$pos = self::relative($origin, self::rot($rotation, Direction::SOUTH), 8 + ($y - $this->startY) * 8);
		return self::relative($pos, self::rot($rotation, Direction::EAST), $eastOffset + ($x - $this->startX) * 8);
	}

	/**
	 * MansionPiecePlacer.createMansion()
	 * @phpstan-param array{int, int, int} $origin
	 */
	public function createMansion(array $origin, int $rotation, MansionGrid $mansion) : void{
		$data = new MansionPlacementData($origin, $rotation, "wall_flat");
		$secondData = new MansionPlacementData($origin, $rotation, "wall_window");
		$this->entrance($data);
		$secondData->position = self::above($data->position, 8);
		$secondData->rotation = $data->rotation;
		$baseGrid = $mansion->baseGrid;
		$thirdGrid = $mansion->thirdFloorGrid;
		$this->startX = $mansion->entranceX + 1;
		$this->startY = $mansion->entranceY + 1;
		$endX = $mansion->entranceX + 1;
		$endY = $mansion->entranceY;
		$this->traverseOuterWalls($data, $baseGrid, Direction::SOUTH, $this->startX, $this->startY, $endX, $endY);
		$this->traverseOuterWalls($secondData, $baseGrid, Direction::SOUTH, $this->startX, $this->startY, $endX, $endY);
		$thirdData = new MansionPlacementData(self::above($data->position, 19), $data->rotation, "wall_window");
		$done = false;
		for($y = 0; $y < $thirdGrid->height && !$done; ++$y){
			for($x = $thirdGrid->width - 1; $x >= 0 && !$done; --$x){
				if(MansionGrid::isHouse($thirdGrid, $x, $y)){
					$thirdData->position = self::relative($thirdData->position, self::rot($rotation, Direction::SOUTH), 8 + ($y - $this->startY) * 8);
					$thirdData->position = self::relative($thirdData->position, self::rot($rotation, Direction::EAST), ($x - $this->startX) * 8);
					$this->traverseWallPiece($thirdData);
					$this->traverseOuterWalls($thirdData, $thirdGrid, Direction::SOUTH, $x, $y, $x, $y);
					$done = true;
				}
			}
		}
		$this->createRoof(self::above($origin, 16), $rotation, $baseGrid, $thirdGrid);
		$this->createRoof(self::above($origin, 27), $rotation, $thirdGrid, null);

		$roomCollections = [new MansionRoomCollection(true), new MansionRoomCollection(false), new MansionRoomCollection(false)];
		/** @phpstan-var list<int> $doorDirs */
		$doorDirs = [];
		for($floorNum = 0; $floorNum < 3; ++$floorNum){
			$floorOrigin = self::above($origin, 8 * $floorNum + ($floorNum === 2 ? 3 : 0));
			$rooms = $mansion->floorRooms[$floorNum];
			$grid = $floorNum === 2 ? $thirdGrid : $baseGrid;
			$southPiece = $floorNum === 0 ? "carpet_south_1" : "carpet_south_2";
			$westPiece = $floorNum === 0 ? "carpet_west_1" : "carpet_west_2";
			for($y = 0; $y < $grid->height; ++$y){
				for($x = 0; $x < $grid->width; ++$x){
					if($grid->get($x, $y) !== MansionGrid::CORRIDOR){
						continue;
					}
					$pos = $this->cellPos($floorOrigin, $rotation, $x, $y);
					$this->add("corridor_floor", $pos, $rotation);
					if($grid->get($x, $y - 1) === MansionGrid::CORRIDOR || ($rooms->get($x, $y - 1) & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG){
						$this->add("carpet_north", self::above(self::relative($pos, self::rot($rotation, Direction::EAST), 1), 1), $rotation);
					}
					if($grid->get($x + 1, $y) === MansionGrid::CORRIDOR || ($rooms->get($x + 1, $y) & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG){
						$this->add("carpet_east", self::above(self::relative(self::relative($pos, self::rot($rotation, Direction::SOUTH), 1), self::rot($rotation, Direction::EAST), 5), 1), $rotation);
					}
					if($grid->get($x, $y + 1) === MansionGrid::CORRIDOR || ($rooms->get($x, $y + 1) & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG){
						$this->add($southPiece, self::relative(self::relative($pos, self::rot($rotation, Direction::SOUTH), 5), self::rot($rotation, Direction::WEST), 1), $rotation);
					}
					if($grid->get($x - 1, $y) === MansionGrid::CORRIDOR || ($rooms->get($x - 1, $y) & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG){
						$this->add($westPiece, self::relative(self::relative($pos, self::rot($rotation, Direction::WEST), 1), self::rot($rotation, Direction::NORTH), 1), $rotation);
					}
				}
			}
			$wallPiece = $floorNum === 0 ? "indoors_wall_1" : "indoors_wall_2";
			$doorPiece = $floorNum === 0 ? "indoors_door_1" : "indoors_door_2";
			for($y = 0; $y < $grid->height; ++$y){
				for($x = 0; $x < $grid->width; ++$x){
					$thirdFloorStartRoom = $floorNum === 2 && $grid->get($x, $y) === MansionGrid::START_ROOM;
					if($grid->get($x, $y) !== MansionGrid::ROOM && !$thirdFloorStartRoom){
						continue;
					}
					$roomData = $rooms->get($x, $y);
					$roomType = $roomData & MansionGrid::ROOM_TYPE_MASK;
					$roomId = $roomData & MansionGrid::ROOM_ID_MASK;
					$thirdFloorStartRoom = $thirdFloorStartRoom && ($roomData & MansionGrid::ROOM_CORRIDOR_FLAG) === MansionGrid::ROOM_CORRIDOR_FLAG;
					$doorDirs = [];
					if(($roomData & MansionGrid::ROOM_DOOR_FLAG) === MansionGrid::ROOM_DOOR_FLAG){
						foreach(Direction::PLANE_HORIZONTAL as $direction){
							if($grid->get($x + Direction::STEP_X[$direction], $y + Direction::STEP_Z[$direction]) === MansionGrid::CORRIDOR){
								$doorDirs[] = $direction;
							}
						}
					}
					$doorDir = null;
					if(count($doorDirs) > 0){
						$doorDir = $doorDirs[$this->random->nextBoundedInt(count($doorDirs))];
					}elseif(($roomData & MansionGrid::ROOM_ORIGIN_FLAG) === MansionGrid::ROOM_ORIGIN_FLAG){
						$doorDir = Direction::UP;
					}
					$roomPos = $this->cellPos($floorOrigin, $rotation, $x, $y, -1);
					if(MansionGrid::isHouse($grid, $x - 1, $y) && !$mansion->isRoomId($grid, $x - 1, $y, $floorNum, $roomId)){
						$this->add($doorDir === Direction::WEST ? $doorPiece : $wallPiece, $roomPos, $rotation);
					}
					if($grid->get($x + 1, $y) === MansionGrid::CORRIDOR && !$thirdFloorStartRoom){
						$pos = self::relative($roomPos, self::rot($rotation, Direction::EAST), 8);
						$this->add($doorDir === Direction::EAST ? $doorPiece : $wallPiece, $pos, $rotation);
					}
					if(MansionGrid::isHouse($grid, $x, $y + 1) && !$mansion->isRoomId($grid, $x, $y + 1, $floorNum, $roomId)){
						$pos = self::relative($roomPos, self::rot($rotation, Direction::SOUTH), 7);
						$pos = self::relative($pos, self::rot($rotation, Direction::EAST), 7);
						$this->add($doorDir === Direction::SOUTH ? $doorPiece : $wallPiece, $pos, Rotations::compose($rotation, Rotations::CLOCKWISE_90));
					}
					if($grid->get($x, $y - 1) === MansionGrid::CORRIDOR && !$thirdFloorStartRoom){
						$pos = self::relative($roomPos, self::rot($rotation, Direction::NORTH), 1);
						$pos = self::relative($pos, self::rot($rotation, Direction::EAST), 7);
						$this->add($doorDir === Direction::NORTH ? $doorPiece : $wallPiece, $pos, Rotations::compose($rotation, Rotations::CLOCKWISE_90));
					}
					if($roomType === MansionGrid::ROOM_1x1){
						$this->addRoom1x1($roomPos, $rotation, $doorDir, $roomCollections[$floorNum]);
					}elseif($roomType === MansionGrid::ROOM_1x2 && $doorDir !== null){
						$roomDir = $mansion->get1x2RoomDirection($grid, $x, $y, $floorNum, $roomId);
						$isStairsRoom = ($roomData & MansionGrid::ROOM_STAIRS_FLAG) === MansionGrid::ROOM_STAIRS_FLAG;
						$this->addRoom1x2($roomPos, $rotation, $roomDir, $doorDir, $roomCollections[$floorNum], $isStairsRoom);
					}elseif($roomType === MansionGrid::ROOM_2x2 && $doorDir !== null && $doorDir !== Direction::UP){
						$roomDir = Direction::clockWise($doorDir);
						if(!$mansion->isRoomId($grid, $x + Direction::STEP_X[$roomDir], $y + Direction::STEP_Z[$roomDir], $floorNum, $roomId)){
							$roomDir = Direction::OPPOSITE[$roomDir];
						}
						$this->addRoom2x2($roomPos, $rotation, $roomDir, $doorDir, $roomCollections[$floorNum]);
					}elseif($roomType === MansionGrid::ROOM_2x2 && $doorDir === Direction::UP){
						$this->addRoom2x2Secret($roomPos, $rotation, $roomCollections[$floorNum]);
					}
				}
			}
		}
	}

	private function traverseOuterWalls(MansionPlacementData $data, MansionSimpleGrid $grid, int $gridDirection, int $startX, int $startY, int $endX, int $endY) : void{
		$gridX = $startX;
		$gridY = $startY;
		$startDirection = $gridDirection;
		do{
			if(!MansionGrid::isHouse($grid, $gridX + Direction::STEP_X[$gridDirection], $gridY + Direction::STEP_Z[$gridDirection])){
				$this->traverseTurn($data);
				$gridDirection = Direction::clockWise($gridDirection);
				if($gridX !== $endX || $gridY !== $endY || $startDirection !== $gridDirection){
					$this->traverseWallPiece($data);
				}
			}else{
				$ccw = Direction::counterClockWise($gridDirection);
				if(MansionGrid::isHouse($grid, $gridX + Direction::STEP_X[$gridDirection], $gridY + Direction::STEP_Z[$gridDirection]) &&
					MansionGrid::isHouse($grid, $gridX + Direction::STEP_X[$gridDirection] + Direction::STEP_X[$ccw], $gridY + Direction::STEP_Z[$gridDirection] + Direction::STEP_Z[$ccw])){
					$this->traverseInnerTurn($data);
					$gridX += Direction::STEP_X[$gridDirection];
					$gridY += Direction::STEP_Z[$gridDirection];
					$gridDirection = $ccw;
				}else{
					$gridX += Direction::STEP_X[$gridDirection];
					$gridY += Direction::STEP_Z[$gridDirection];
					if($gridX !== $endX || $gridY !== $endY || $startDirection !== $gridDirection){
						$this->traverseWallPiece($data);
					}
				}
			}
		}while($gridX !== $endX || $gridY !== $endY || $startDirection !== $gridDirection);
	}

	/**
	 * @phpstan-param array{int, int, int} $roofOrigin
	 */
	private function createRoof(array $roofOrigin, int $rotation, MansionSimpleGrid $grid, ?MansionSimpleGrid $aboveGrid) : void{
		$east = self::rot($rotation, Direction::EAST);
		$west = self::rot($rotation, Direction::WEST);
		$south = self::rot($rotation, Direction::SOUTH);
		$north = self::rot($rotation, Direction::NORTH);
		$cw90 = Rotations::compose($rotation, Rotations::CLOCKWISE_90);
		$cw180 = Rotations::compose($rotation, Rotations::CLOCKWISE_180);
		$ccw90 = Rotations::compose($rotation, Rotations::COUNTERCLOCKWISE_90);
		for($y = 0; $y < $grid->height; ++$y){
			for($x = 0; $x < $grid->width; ++$x){
				$position = $this->cellPos($roofOrigin, $rotation, $x, $y);
				$isAbove = $aboveGrid !== null && MansionGrid::isHouse($aboveGrid, $x, $y);
				if(!MansionGrid::isHouse($grid, $x, $y) || $isAbove){
					continue;
				}
				$this->add("roof", self::above($position, 3), $rotation);
				if(!MansionGrid::isHouse($grid, $x + 1, $y)){
					$this->add("roof_front", self::relative($position, $east, 6), $rotation);
				}
				if(!MansionGrid::isHouse($grid, $x - 1, $y)){
					$p2 = self::relative($position, $east, 0);
					$p2 = self::relative($p2, $south, 7);
					$this->add("roof_front", $p2, $cw180);
				}
				if(!MansionGrid::isHouse($grid, $x, $y - 1)){
					$this->add("roof_front", self::relative($position, $west, 1), $ccw90);
				}
				if(!MansionGrid::isHouse($grid, $x, $y + 1)){
					$p2 = self::relative($position, $east, 6);
					$p2 = self::relative($p2, $south, 6);
					$this->add("roof_front", $p2, $cw90);
				}
			}
		}
		if($aboveGrid !== null){
			for($y = 0; $y < $grid->height; ++$y){
				for($x = 0; $x < $grid->width; ++$x){
					$position = $this->cellPos($roofOrigin, $rotation, $x, $y);
					$isAbove = MansionGrid::isHouse($aboveGrid, $x, $y);
					if(!MansionGrid::isHouse($grid, $x, $y) || !$isAbove){
						continue;
					}
					if(!MansionGrid::isHouse($grid, $x + 1, $y)){
						$this->add("small_wall", self::relative($position, $east, 7), $rotation);
					}
					if(!MansionGrid::isHouse($grid, $x - 1, $y)){
						$p2 = self::relative($position, $west, 1);
						$p2 = self::relative($p2, $south, 6);
						$this->add("small_wall", $p2, $cw180);
					}
					if(!MansionGrid::isHouse($grid, $x, $y - 1)){
						$p2 = self::relative($position, $west, 0);
						$p2 = self::relative($p2, $north, 1);
						$this->add("small_wall", $p2, $ccw90);
					}
					if(!MansionGrid::isHouse($grid, $x, $y + 1)){
						$p2 = self::relative($position, $east, 6);
						$p2 = self::relative($p2, $south, 7);
						$this->add("small_wall", $p2, $cw90);
					}
					if(!MansionGrid::isHouse($grid, $x + 1, $y)){
						if(!MansionGrid::isHouse($grid, $x, $y - 1)){
							$p2 = self::relative($position, $east, 7);
							$p2 = self::relative($p2, $north, 2);
							$this->add("small_wall_corner", $p2, $rotation);
						}
						if(!MansionGrid::isHouse($grid, $x, $y + 1)){
							$p2 = self::relative($position, $east, 8);
							$p2 = self::relative($p2, $south, 7);
							$this->add("small_wall_corner", $p2, $cw90);
						}
					}
					if(!MansionGrid::isHouse($grid, $x - 1, $y)){
						if(!MansionGrid::isHouse($grid, $x, $y - 1)){
							$p2 = self::relative($position, $west, 2);
							$p2 = self::relative($p2, $north, 1);
							$this->add("small_wall_corner", $p2, $ccw90);
						}
						if(!MansionGrid::isHouse($grid, $x, $y + 1)){
							$p2 = self::relative($position, $west, 1);
							$p2 = self::relative($p2, $south, 8);
							$this->add("small_wall_corner", $p2, $cw180);
						}
					}
				}
			}
		}
		for($y = 0; $y < $grid->height; ++$y){
			for($x = 0; $x < $grid->width; ++$x){
				$position = $this->cellPos($roofOrigin, $rotation, $x, $y);
				$isAbove = $aboveGrid !== null && MansionGrid::isHouse($aboveGrid, $x, $y);
				if(!MansionGrid::isHouse($grid, $x, $y) || $isAbove){
					continue;
				}
				if(!MansionGrid::isHouse($grid, $x + 1, $y)){
					$p2 = self::relative($position, $east, 6);
					if(!MansionGrid::isHouse($grid, $x, $y + 1)){
						$this->add("roof_corner", self::relative($p2, $south, 6), $rotation);
					}elseif(MansionGrid::isHouse($grid, $x + 1, $y + 1)){
						$this->add("roof_inner_corner", self::relative($p2, $south, 5), $rotation);
					}
					if(!MansionGrid::isHouse($grid, $x, $y - 1)){
						$this->add("roof_corner", $p2, $ccw90);
					}elseif(MansionGrid::isHouse($grid, $x + 1, $y - 1)){
						$p3 = self::relative($position, $east, 9);
						$p3 = self::relative($p3, $north, 2);
						$this->add("roof_inner_corner", $p3, $cw90);
					}
				}
				if(!MansionGrid::isHouse($grid, $x - 1, $y)){
					$p2 = self::relative($position, $east, 0);
					$p2 = self::relative($p2, $south, 0);
					if(!MansionGrid::isHouse($grid, $x, $y + 1)){
						$this->add("roof_corner", self::relative($p2, $south, 6), $cw90);
					}elseif(MansionGrid::isHouse($grid, $x - 1, $y + 1)){
						$p3 = self::relative($p2, $south, 8);
						$p3 = self::relative($p3, $west, 3);
						$this->add("roof_inner_corner", $p3, $ccw90);
					}
					if(!MansionGrid::isHouse($grid, $x, $y - 1)){
						$this->add("roof_corner", $p2, $cw180);
					}elseif(MansionGrid::isHouse($grid, $x - 1, $y - 1)){
						$this->add("roof_inner_corner", self::relative($p2, $south, 1), $cw180);
					}
				}
			}
		}
	}

	private function entrance(MansionPlacementData $data) : void{
		$west = self::rot($data->rotation, Direction::WEST);
		$this->add("entrance", self::relative($data->position, $west, 9), $data->rotation);
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::SOUTH), 16);
	}

	private function traverseWallPiece(MansionPlacementData $data) : void{
		$this->add($data->wallType, self::relative($data->position, self::rot($data->rotation, Direction::EAST), 7), $data->rotation);
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::SOUTH), 8);
	}

	private function traverseTurn(MansionPlacementData $data) : void{
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::SOUTH), -1);
		$this->add("wall_corner", $data->position, $data->rotation);
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::SOUTH), -7);
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::WEST), -6);
		$data->rotation = Rotations::compose($data->rotation, Rotations::CLOCKWISE_90);
	}

	private function traverseInnerTurn(MansionPlacementData $data) : void{
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::SOUTH), 6);
		$data->position = self::relative($data->position, self::rot($data->rotation, Direction::EAST), 8);
		$data->rotation = Rotations::compose($data->rotation, Rotations::COUNTERCLOCKWISE_90);
	}

	/**
	 * @phpstan-param array{int, int, int} $roomPos
	 */
	private function addRoom1x1(array $roomPos, int $rotation, ?int $doorDir, MansionRoomCollection $rooms) : void{
		$pieceRot = Rotations::NONE;
		$roomType = $rooms->get1x1($this->random);
		if($doorDir === Direction::EAST){
			//NOOP
		}elseif($doorDir === Direction::NORTH){
			$pieceRot = Rotations::compose($pieceRot, Rotations::COUNTERCLOCKWISE_90);
		}elseif($doorDir === Direction::WEST){
			$pieceRot = Rotations::compose($pieceRot, Rotations::CLOCKWISE_180);
		}elseif($doorDir === Direction::SOUTH){
			$pieceRot = Rotations::compose($pieceRot, Rotations::CLOCKWISE_90);
		}else{
			$roomType = $rooms->get1x1Secret($this->random);
		}
		//StructureTemplate.getZeroPositionWithTransform(new BlockPos(1, 0, 0), Mirror.NONE, pieceRot, 7, 7)
		[$ox, $oz] = match($pieceRot){
			Rotations::COUNTERCLOCKWISE_90 => [1, 6],
			Rotations::CLOCKWISE_90 => [1 + 6, 0],
			Rotations::CLOCKWISE_180 => [1 + 6, 6],
			default => [1, 0]
		};
		$pieceRot = Rotations::compose($pieceRot, $rotation);
		//BlockPos.rotate(rotation)
		[$ox, $oz] = match($rotation){
			Rotations::CLOCKWISE_90 => [-$oz, $ox],
			Rotations::CLOCKWISE_180 => [-$ox, -$oz],
			Rotations::COUNTERCLOCKWISE_90 => [$oz, -$ox],
			default => [$ox, $oz]
		};
		$this->add($roomType, [$roomPos[0] + $ox, $roomPos[1], $roomPos[2] + $oz], $pieceRot);
	}

	/**
	 * @phpstan-param array{int, int, int} $roomPos
	 */
	private function addRoom1x2(array $roomPos, int $rotation, ?int $roomDir, int $doorDir, MansionRoomCollection $rooms, bool $isStairsRoom) : void{
		$east = self::rot($rotation, Direction::EAST);
		$west = self::rot($rotation, Direction::WEST);
		$south = self::rot($rotation, Direction::SOUTH);
		$north = self::rot($rotation, Direction::NORTH);
		$cw90 = Rotations::compose($rotation, Rotations::CLOCKWISE_90);
		$cw180 = Rotations::compose($rotation, Rotations::CLOCKWISE_180);
		$ccw90 = Rotations::compose($rotation, Rotations::COUNTERCLOCKWISE_90);
		if($doorDir === Direction::EAST && $roomDir === Direction::SOUTH){
			$pos = self::relative($roomPos, $east, 1);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $rotation);
		}elseif($doorDir === Direction::EAST && $roomDir === Direction::NORTH){
			$pos = self::relative(self::relative($roomPos, $east, 1), $south, 6);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $rotation, Rotations::MIRROR_LEFT_RIGHT);
		}elseif($doorDir === Direction::WEST && $roomDir === Direction::NORTH){
			$pos = self::relative(self::relative($roomPos, $east, 7), $south, 6);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $cw180);
		}elseif($doorDir === Direction::WEST && $roomDir === Direction::SOUTH){
			$pos = self::relative($roomPos, $east, 7);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $rotation, Rotations::MIRROR_FRONT_BACK);
		}elseif($doorDir === Direction::SOUTH && $roomDir === Direction::EAST){
			$pos = self::relative($roomPos, $east, 1);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $cw90, Rotations::MIRROR_LEFT_RIGHT);
		}elseif($doorDir === Direction::SOUTH && $roomDir === Direction::WEST){
			$pos = self::relative($roomPos, $east, 7);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $cw90);
		}elseif($doorDir === Direction::NORTH && $roomDir === Direction::WEST){
			$pos = self::relative(self::relative($roomPos, $east, 7), $south, 6);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $cw90, Rotations::MIRROR_FRONT_BACK);
		}elseif($doorDir === Direction::NORTH && $roomDir === Direction::EAST){
			$pos = self::relative(self::relative($roomPos, $east, 1), $south, 6);
			$this->add($rooms->get1x2SideEntrance($this->random, $isStairsRoom), $pos, $ccw90);
		}elseif($doorDir === Direction::SOUTH && $roomDir === Direction::NORTH){
			$pos = self::relative(self::relative($roomPos, $east, 1), $north, 8);
			$this->add($rooms->get1x2FrontEntrance($this->random, $isStairsRoom), $pos, $rotation);
		}elseif($doorDir === Direction::NORTH && $roomDir === Direction::SOUTH){
			$pos = self::relative(self::relative($roomPos, $east, 7), $south, 14);
			$this->add($rooms->get1x2FrontEntrance($this->random, $isStairsRoom), $pos, $cw180);
		}elseif($doorDir === Direction::WEST && $roomDir === Direction::EAST){
			$pos = self::relative($roomPos, $east, 15);
			$this->add($rooms->get1x2FrontEntrance($this->random, $isStairsRoom), $pos, $cw90);
		}elseif($doorDir === Direction::EAST && $roomDir === Direction::WEST){
			$pos = self::relative(self::relative($roomPos, $west, 7), $south, 6);
			$this->add($rooms->get1x2FrontEntrance($this->random, $isStairsRoom), $pos, $ccw90);
		}elseif($doorDir === Direction::UP && $roomDir === Direction::EAST){
			$pos = self::relative($roomPos, $east, 15);
			$this->add($rooms->get1x2Secret($this->random), $pos, $cw90);
		}elseif($doorDir === Direction::UP && $roomDir === Direction::SOUTH){
			$pos = self::relative(self::relative($roomPos, $east, 1), $north, 0);
			$this->add($rooms->get1x2Secret($this->random), $pos, $rotation);
		}
	}

	/**
	 * @phpstan-param array{int, int, int} $roomPos
	 */
	private function addRoom2x2(array $roomPos, int $rotation, int $roomDir, int $doorDir, MansionRoomCollection $rooms) : void{
		$east = 0;
		$south = 0;
		$rot = $rotation;
		$mirror = Rotations::MIRROR_NONE;
		if($doorDir === Direction::EAST && $roomDir === Direction::SOUTH){
			$east = -7;
		}elseif($doorDir === Direction::EAST && $roomDir === Direction::NORTH){
			$east = -7;
			$south = 6;
			$mirror = Rotations::MIRROR_LEFT_RIGHT;
		}elseif($doorDir === Direction::NORTH && $roomDir === Direction::EAST){
			$east = 1;
			$south = 14;
			$rot = Rotations::compose($rotation, Rotations::COUNTERCLOCKWISE_90);
		}elseif($doorDir === Direction::NORTH && $roomDir === Direction::WEST){
			$east = 7;
			$south = 14;
			$rot = Rotations::compose($rotation, Rotations::COUNTERCLOCKWISE_90);
			$mirror = Rotations::MIRROR_LEFT_RIGHT;
		}elseif($doorDir === Direction::SOUTH && $roomDir === Direction::WEST){
			$east = 7;
			$south = -8;
			$rot = Rotations::compose($rotation, Rotations::CLOCKWISE_90);
		}elseif($doorDir === Direction::SOUTH && $roomDir === Direction::EAST){
			$east = 1;
			$south = -8;
			$rot = Rotations::compose($rotation, Rotations::CLOCKWISE_90);
			$mirror = Rotations::MIRROR_LEFT_RIGHT;
		}elseif($doorDir === Direction::WEST && $roomDir === Direction::NORTH){
			$east = 15;
			$south = 6;
			$rot = Rotations::compose($rotation, Rotations::CLOCKWISE_180);
		}elseif($doorDir === Direction::WEST && $roomDir === Direction::SOUTH){
			$east = 15;
			$mirror = Rotations::MIRROR_FRONT_BACK;
		}
		$pos = self::relative($roomPos, self::rot($rotation, Direction::EAST), $east);
		$pos = self::relative($pos, self::rot($rotation, Direction::SOUTH), $south);
		$this->add($rooms->get2x2($this->random), $pos, $rot, $mirror);
	}

	/**
	 * @phpstan-param array{int, int, int} $roomPos
	 */
	private function addRoom2x2Secret(array $roomPos, int $rotation, MansionRoomCollection $rooms) : void{
		$pos = self::relative($roomPos, self::rot($rotation, Direction::EAST), 1);
		$this->add($rooms->get2x2Secret($this->random), $pos, $rotation, Rotations::MIRROR_NONE);
	}
}
