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
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use function count;

/**
 * vanilla's WoodlandMansionPieces.MansionGrid: the random layout of the mansion's floors (corridors and rooms) on an
 * 11x11 grid of 8x8 cells.
 */
final class MansionGrid{
	public const CLEAR = 0;
	public const CORRIDOR = 1;
	public const ROOM = 2;
	public const START_ROOM = 3;
	public const TEST_ROOM = 4;
	public const BLOCKED = 5;
	public const ROOM_1x1 = 65536;
	public const ROOM_1x2 = 131072;
	public const ROOM_2x2 = 262144;
	public const ROOM_ORIGIN_FLAG = 1048576;
	public const ROOM_DOOR_FLAG = 2097152;
	public const ROOM_STAIRS_FLAG = 4194304;
	public const ROOM_CORRIDOR_FLAG = 8388608;
	public const ROOM_TYPE_MASK = 983040;
	public const ROOM_ID_MASK = 65535;

	public readonly MansionSimpleGrid $baseGrid;
	public readonly MansionSimpleGrid $thirdFloorGrid;
	/**
	 * @var MansionSimpleGrid[]
	 * @phpstan-var array{MansionSimpleGrid, MansionSimpleGrid, MansionSimpleGrid}
	 */
	public readonly array $floorRooms;
	public readonly int $entranceX;
	public readonly int $entranceY;

	public function __construct(private LegacyRandom $random){
		$this->entranceX = $entranceX = 7;
		$this->entranceY = $entranceY = 4;
		$baseGrid = new MansionSimpleGrid(11, 11, self::BLOCKED);
		$this->baseGrid = $baseGrid;
		$baseGrid->setArea($entranceX, $entranceY, $entranceX + 1, $entranceY + 1, self::START_ROOM);
		$baseGrid->setArea($entranceX - 1, $entranceY, $entranceX - 1, $entranceY + 1, self::ROOM);
		$baseGrid->setArea($entranceX + 2, $entranceY - 2, $entranceX + 3, $entranceY + 3, self::BLOCKED);
		$baseGrid->setArea($entranceX + 1, $entranceY - 2, $entranceX + 1, $entranceY - 1, self::CORRIDOR);
		$baseGrid->setArea($entranceX + 1, $entranceY + 2, $entranceX + 1, $entranceY + 3, self::CORRIDOR);
		$baseGrid->set($entranceX - 1, $entranceY - 1, self::CORRIDOR);
		$baseGrid->set($entranceX - 1, $entranceY + 2, self::CORRIDOR);
		$baseGrid->setArea(0, 0, 11, 1, self::BLOCKED);
		$baseGrid->setArea(0, 9, 11, 11, self::BLOCKED);
		$this->recursiveCorridor($baseGrid, $entranceX, $entranceY - 2, Direction::WEST, 6);
		$this->recursiveCorridor($baseGrid, $entranceX, $entranceY + 3, Direction::WEST, 6);
		$this->recursiveCorridor($baseGrid, $entranceX - 2, $entranceY - 1, Direction::WEST, 3);
		$this->recursiveCorridor($baseGrid, $entranceX - 2, $entranceY + 2, Direction::WEST, 3);
		while($this->cleanEdges($baseGrid)){
			//NOOP
		}
		$floorRooms = [
			new MansionSimpleGrid(11, 11, self::BLOCKED),
			new MansionSimpleGrid(11, 11, self::BLOCKED),
			new MansionSimpleGrid(11, 11, self::BLOCKED)
		];
		$this->floorRooms = $floorRooms;
		$this->identifyRooms($baseGrid, $floorRooms[0]);
		$this->identifyRooms($baseGrid, $floorRooms[1]);
		$floorRooms[0]->setArea($entranceX + 1, $entranceY, $entranceX + 1, $entranceY + 1, self::ROOM_CORRIDOR_FLAG);
		$floorRooms[1]->setArea($entranceX + 1, $entranceY, $entranceX + 1, $entranceY + 1, self::ROOM_CORRIDOR_FLAG);
		$this->thirdFloorGrid = new MansionSimpleGrid($baseGrid->width, $baseGrid->height, self::BLOCKED);
		$this->setupThirdFloor();
		$this->identifyRooms($this->thirdFloorGrid, $floorRooms[2]);
	}

	public static function isHouse(MansionSimpleGrid $grid, int $x, int $y) : bool{
		$value = $grid->get($x, $y);
		return $value === self::CORRIDOR || $value === self::ROOM || $value === self::START_ROOM || $value === self::TEST_ROOM;
	}

	public function isRoomId(MansionSimpleGrid $grid, int $x, int $y, int $floor, int $roomId) : bool{
		return ($this->floorRooms[$floor]->get($x, $y) & self::ROOM_ID_MASK) === $roomId;
	}

	/**
	 * The direction of the other cell of a 1x2 room, or null.
	 */
	public function get1x2RoomDirection(MansionSimpleGrid $grid, int $x, int $y, int $floorNum, int $roomId) : ?int{
		foreach(Direction::PLANE_HORIZONTAL as $direction){
			if($this->isRoomId($grid, $x + Direction::STEP_X[$direction], $y + Direction::STEP_Z[$direction], $floorNum, $roomId)){
				return $direction;
			}
		}
		return null;
	}

	private function recursiveCorridor(MansionSimpleGrid $grid, int $x, int $y, int $heading, int $depth) : void{
		if($depth <= 0){
			return;
		}
		$grid->set($x, $y, self::CORRIDOR);
		$grid->setIf($x + Direction::STEP_X[$heading], $y + Direction::STEP_Z[$heading], self::CLEAR, self::CORRIDOR);
		for($attempts = 0; $attempts < 8; ++$attempts){
			$nextDir = Direction::HORIZONTAL[$this->random->nextBoundedInt(4)];
			if($nextDir === Direction::OPPOSITE[$heading] || ($nextDir === Direction::EAST && $this->random->nextBoolean())){
				continue;
			}
			$nx = $x + Direction::STEP_X[$heading];
			$ny = $y + Direction::STEP_Z[$heading];
			if($grid->get($nx + Direction::STEP_X[$nextDir], $ny + Direction::STEP_Z[$nextDir]) !== 0 || $grid->get($nx + Direction::STEP_X[$nextDir] * 2, $ny + Direction::STEP_Z[$nextDir] * 2) !== 0){
				continue;
			}
			$this->recursiveCorridor($grid, $x + Direction::STEP_X[$heading] + Direction::STEP_X[$nextDir], $y + Direction::STEP_Z[$heading] + Direction::STEP_Z[$nextDir], $nextDir, $depth - 1);
			break;
		}
		$cw = Direction::clockWise($heading);
		$ccw = Direction::counterClockWise($heading);
		$hx = Direction::STEP_X[$heading];
		$hz = Direction::STEP_Z[$heading];
		$grid->setIf($x + Direction::STEP_X[$cw], $y + Direction::STEP_Z[$cw], self::CLEAR, self::ROOM);
		$grid->setIf($x + Direction::STEP_X[$ccw], $y + Direction::STEP_Z[$ccw], self::CLEAR, self::ROOM);
		$grid->setIf($x + $hx + Direction::STEP_X[$cw], $y + $hz + Direction::STEP_Z[$cw], self::CLEAR, self::ROOM);
		$grid->setIf($x + $hx + Direction::STEP_X[$ccw], $y + $hz + Direction::STEP_Z[$ccw], self::CLEAR, self::ROOM);
		$grid->setIf($x + $hx * 2, $y + $hz * 2, self::CLEAR, self::ROOM);
		$grid->setIf($x + Direction::STEP_X[$cw] * 2, $y + Direction::STEP_Z[$cw] * 2, self::CLEAR, self::ROOM);
		$grid->setIf($x + Direction::STEP_X[$ccw] * 2, $y + Direction::STEP_Z[$ccw] * 2, self::CLEAR, self::ROOM);
	}

	private function cleanEdges(MansionSimpleGrid $grid) : bool{
		$touched = false;
		for($y = 0; $y < $grid->height; ++$y){
			for($x = 0; $x < $grid->width; ++$x){
				if($grid->get($x, $y) !== 0){
					continue;
				}
				$directNeighbors = (self::isHouse($grid, $x + 1, $y) ? 1 : 0) + (self::isHouse($grid, $x - 1, $y) ? 1 : 0) +
					(self::isHouse($grid, $x, $y + 1) ? 1 : 0) + (self::isHouse($grid, $x, $y - 1) ? 1 : 0);
				if($directNeighbors >= 3){
					$grid->set($x, $y, self::ROOM);
					$touched = true;
				}elseif($directNeighbors === 2){
					$diagonalNeighbors = (self::isHouse($grid, $x + 1, $y + 1) ? 1 : 0) + (self::isHouse($grid, $x - 1, $y + 1) ? 1 : 0) +
						(self::isHouse($grid, $x + 1, $y - 1) ? 1 : 0) + (self::isHouse($grid, $x - 1, $y - 1) ? 1 : 0);
					if($diagonalNeighbors <= 1){
						$grid->set($x, $y, self::ROOM);
						$touched = true;
					}
				}
			}
		}
		return $touched;
	}

	private function setupThirdFloor() : void{
		$thirdFloorGrid = $this->thirdFloorGrid;
		/** @phpstan-var list<array{int, int}> $potentialRooms */
		$potentialRooms = [];
		$floor = $this->floorRooms[1];
		for($y = 0; $y < $thirdFloorGrid->height; ++$y){
			for($x = 0; $x < $thirdFloorGrid->width; ++$x){
				$roomData = $floor->get($x, $y);
				if(($roomData & self::ROOM_TYPE_MASK) === self::ROOM_1x2 && ($roomData & self::ROOM_DOOR_FLAG) === self::ROOM_DOOR_FLAG){
					$potentialRooms[] = [$x, $y];
				}
			}
		}
		if(count($potentialRooms) === 0){
			$thirdFloorGrid->setArea(0, 0, $thirdFloorGrid->width, $thirdFloorGrid->height, self::BLOCKED);
			return;
		}
		[$roomX, $roomY] = $potentialRooms[$this->random->nextBoundedInt(count($potentialRooms))];
		$roomData = $floor->get($roomX, $roomY);
		$floor->set($roomX, $roomY, $roomData | self::ROOM_STAIRS_FLAG);
		$roomDir = $this->get1x2RoomDirection($this->baseGrid, $roomX, $roomY, 1, $roomData & self::ROOM_ID_MASK);
		if($roomDir === null){
			//vanilla would throw a NullPointerException here; a 1x2 room always has its other cell
			throw new \LogicException("1x2 room without a second cell");
		}
		$roomEndX = $roomX + Direction::STEP_X[$roomDir];
		$roomEndY = $roomY + Direction::STEP_Z[$roomDir];
		for($y = 0; $y < $thirdFloorGrid->height; ++$y){
			for($x = 0; $x < $thirdFloorGrid->width; ++$x){
				if(!self::isHouse($this->baseGrid, $x, $y)){
					$thirdFloorGrid->set($x, $y, self::BLOCKED);
				}elseif($x === $roomX && $y === $roomY){
					$thirdFloorGrid->set($x, $y, self::START_ROOM);
				}elseif($x === $roomEndX && $y === $roomEndY){
					$thirdFloorGrid->set($x, $y, self::START_ROOM);
					$this->floorRooms[2]->set($x, $y, self::ROOM_CORRIDOR_FLAG);
				}
			}
		}
		/** @phpstan-var list<int> $potentialCorridors */
		$potentialCorridors = [];
		foreach(Direction::PLANE_HORIZONTAL as $direction){
			if($thirdFloorGrid->get($roomEndX + Direction::STEP_X[$direction], $roomEndY + Direction::STEP_Z[$direction]) === 0){
				$potentialCorridors[] = $direction;
			}
		}
		if(count($potentialCorridors) === 0){
			$thirdFloorGrid->setArea(0, 0, $thirdFloorGrid->width, $thirdFloorGrid->height, self::BLOCKED);
			$floor->set($roomX, $roomY, $roomData);
			return;
		}
		$corridorDir = $potentialCorridors[$this->random->nextBoundedInt(count($potentialCorridors))];
		$this->recursiveCorridor($thirdFloorGrid, $roomEndX + Direction::STEP_X[$corridorDir], $roomEndY + Direction::STEP_Z[$corridorDir], $corridorDir, 4);
		while($this->cleanEdges($thirdFloorGrid)){
			//NOOP
		}
	}

	private function identifyRooms(MansionSimpleGrid $fromGrid, MansionSimpleGrid $roomGrid) : void{
		/** @phpstan-var list<array{int, int}> $roomPos */
		$roomPos = [];
		for($y = 0; $y < $fromGrid->height; ++$y){
			for($x = 0; $x < $fromGrid->width; ++$x){
				if($fromGrid->get($x, $y) === self::ROOM){
					$roomPos[] = [$x, $y];
				}
			}
		}
		StructureRandom::shuffle($roomPos, $this->random);
		$roomId = 10;
		foreach($roomPos as [$x, $y]){
			if($roomGrid->get($x, $y) !== 0){
				continue;
			}
			$x0 = $x;
			$x1 = $x;
			$y0 = $y;
			$y1 = $y;
			$type = self::ROOM_1x1;
			if($roomGrid->get($x + 1, $y) === 0 && $roomGrid->get($x, $y + 1) === 0 && $roomGrid->get($x + 1, $y + 1) === 0 &&
				$fromGrid->get($x + 1, $y) === self::ROOM && $fromGrid->get($x, $y + 1) === self::ROOM && $fromGrid->get($x + 1, $y + 1) === self::ROOM){
				++$x1;
				++$y1;
				$type = self::ROOM_2x2;
			}elseif($roomGrid->get($x - 1, $y) === 0 && $roomGrid->get($x, $y + 1) === 0 && $roomGrid->get($x - 1, $y + 1) === 0 &&
				$fromGrid->get($x - 1, $y) === self::ROOM && $fromGrid->get($x, $y + 1) === self::ROOM && $fromGrid->get($x - 1, $y + 1) === self::ROOM){
				--$x0;
				++$y1;
				$type = self::ROOM_2x2;
			}elseif($roomGrid->get($x - 1, $y) === 0 && $roomGrid->get($x, $y - 1) === 0 && $roomGrid->get($x - 1, $y - 1) === 0 &&
				$fromGrid->get($x - 1, $y) === self::ROOM && $fromGrid->get($x, $y - 1) === self::ROOM && $fromGrid->get($x - 1, $y - 1) === self::ROOM){
				--$x0;
				--$y0;
				$type = self::ROOM_2x2;
			}elseif($roomGrid->get($x + 1, $y) === 0 && $fromGrid->get($x + 1, $y) === self::ROOM){
				++$x1;
				$type = self::ROOM_1x2;
			}elseif($roomGrid->get($x, $y + 1) === 0 && $fromGrid->get($x, $y + 1) === self::ROOM){
				++$y1;
				$type = self::ROOM_1x2;
			}elseif($roomGrid->get($x - 1, $y) === 0 && $fromGrid->get($x - 1, $y) === self::ROOM){
				--$x0;
				$type = self::ROOM_1x2;
			}elseif($roomGrid->get($x, $y - 1) === 0 && $fromGrid->get($x, $y - 1) === self::ROOM){
				--$y0;
				$type = self::ROOM_1x2;
			}
			$doorX = $this->random->nextBoolean() ? $x0 : $x1;
			$doorY = $this->random->nextBoolean() ? $y0 : $y1;
			$doorFlag = self::ROOM_DOOR_FLAG;
			if(!$fromGrid->edgesTo($doorX, $doorY, self::CORRIDOR)){
				$doorX = $doorX === $x0 ? $x1 : $x0;
				$doorY = $doorY === $y0 ? $y1 : $y0;
				if(!$fromGrid->edgesTo($doorX, $doorY, self::CORRIDOR)){
					$doorY = $doorY === $y0 ? $y1 : $y0;
					if(!$fromGrid->edgesTo($doorX, $doorY, self::CORRIDOR)){
						$doorX = $doorX === $x0 ? $x1 : $x0;
						$doorY = $doorY === $y0 ? $y1 : $y0;
						if(!$fromGrid->edgesTo($doorX, $doorY, self::CORRIDOR)){
							$doorFlag = 0;
							$doorX = $x0;
							$doorY = $y0;
						}
					}
				}
			}
			for($ry = $y0; $ry <= $y1; ++$ry){
				for($rx = $x0; $rx <= $x1; ++$rx){
					if($rx === $doorX && $ry === $doorY){
						$roomGrid->set($rx, $ry, self::ROOM_ORIGIN_FLAG | $doorFlag | $type | $roomId);
					}else{
						$roomGrid->set($rx, $ry, $type | $roomId);
					}
				}
			}
			++$roomId;
		}
	}
}
