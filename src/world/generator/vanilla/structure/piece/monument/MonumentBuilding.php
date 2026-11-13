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
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use function max;

/**
 * OceanMonumentPieces.MonumentBuilding: the 58x23x58 monument shell (wings, entrance, roof, walls, pillars) and its
 * child rooms, laid out from a random room graph at start time.
 */
final class MonumentBuilding extends OceanMonumentPiece{
	public const WIDTH = 58;
	public const HEIGHT = 22;
	public const DEPTH = 58;
	public const BIOME_RANGE_CHECK = 29;
	public const TOP_POSITION = 61;

	private RoomDefinition $sourceRoom;
	private RoomDefinition $coreRoom;
	/** @phpstan-var list<OceanMonumentPiece> */
	private array $childPieces = [];

	public function __construct(LegacyRandom $random, int $west, int $north, int $direction){
		parent::__construct($direction, 0, self::makeBoundingBox($west, 39, $north, $direction, 58, 23, 58));
		$roomDefinitions = $this->generateRoomGraph($random);
		$this->sourceRoom->claimed = true;
		$this->childPieces[] = new OceanMonumentEntryRoom($direction, $this->sourceRoom);
		$this->childPieces[] = new OceanMonumentCoreRoom($direction, $this->coreRoom);
		foreach($roomDefinitions as $definition){
			if($definition->claimed || $definition->isSpecial()){
				continue;
			}
			foreach(MonumentRoomFitter::ALL as $fitter){
				if(MonumentRoomFitter::fits($fitter, $definition)){
					$this->childPieces[] = MonumentRoomFitter::create($fitter, $direction, $definition, $random);
					break;
				}
			}
		}
		[$ox, $oy, $oz] = $this->getWorldPos(9, 0, 22);
		foreach($this->childPieces as $child){
			$child->box->move($ox, $oy, $oz);
		}
		$leftWing = BoundingBox::fromCorners(...$this->getWorldPos(1, 1, 1), ...$this->getWorldPos(23, 8, 21));
		$rightWing = BoundingBox::fromCorners(...$this->getWorldPos(34, 1, 1), ...$this->getWorldPos(56, 8, 21));
		$penthouse = BoundingBox::fromCorners(...$this->getWorldPos(22, 13, 22), ...$this->getWorldPos(35, 17, 35));
		$wingRandom = $random->nextInt();
		$this->childPieces[] = new OceanMonumentWingRoom($direction, $leftWing, $wingRandom++);
		$this->childPieces[] = new OceanMonumentWingRoom($direction, $rightWing, $wingRandom++);
		$this->childPieces[] = new OceanMonumentPenthouse($direction, $penthouse);
	}

	/**
	 * @phpstan-return list<OceanMonumentPiece>
	 */
	public function getChildPieces() : array{
		return $this->childPieces;
	}

	/**
	 * MonumentBuilding.generateRoomGraph()
	 * @phpstan-return list<RoomDefinition>
	 */
	private function generateRoomGraph(LegacyRandom $random) : array{
		/** @phpstan-var array<int, RoomDefinition> $roomGrid */
		$roomGrid = [];
		for($x = 0; $x < 5; ++$x){
			for($z = 0; $z < 4; ++$z){
				$pos = self::getRoomIndex($x, 0, $z);
				$roomGrid[$pos] = new RoomDefinition($pos);
			}
		}
		for($x = 0; $x < 5; ++$x){
			for($z = 0; $z < 4; ++$z){
				$pos = self::getRoomIndex($x, 1, $z);
				$roomGrid[$pos] = new RoomDefinition($pos);
			}
		}
		for($x = 1; $x < 4; ++$x){
			for($z = 0; $z < 2; ++$z){
				$pos = self::getRoomIndex($x, 2, $z);
				$roomGrid[$pos] = new RoomDefinition($pos);
			}
		}
		$this->sourceRoom = $roomGrid[self::GRIDROOM_SOURCE_INDEX];
		for($x = 0; $x < 5; ++$x){
			for($z = 0; $z < 5; ++$z){
				for($y = 0; $y < 3; ++$y){
					$pos = self::getRoomIndex($x, $y, $z);
					if(!isset($roomGrid[$pos])){
						continue;
					}
					//Direction.values(): DOWN, UP, NORTH, SOUTH, WEST, EAST
					for($direction = 0; $direction < 6; ++$direction){
						$neighX = $x + Direction::STEP_X[$direction];
						$neighY = $y + Direction::STEP_Y[$direction];
						$neighZ = $z + Direction::STEP_Z[$direction];
						if($neighX < 0 || $neighX >= 5 || $neighZ < 0 || $neighZ >= 5 || $neighY < 0 || $neighY >= 3){
							continue;
						}
						$neighPos = self::getRoomIndex($neighX, $neighY, $neighZ);
						if(!isset($roomGrid[$neighPos])){
							continue;
						}
						if($neighZ === $z){
							$roomGrid[$pos]->setConnection($direction, $roomGrid[$neighPos]);
						}else{
							$roomGrid[$pos]->setConnection(Direction::OPPOSITE[$direction], $roomGrid[$neighPos]);
						}
					}
				}
			}
		}
		$roofRoom = new RoomDefinition(self::PENTHOUSE_INDEX);
		$leftWing = new RoomDefinition(self::LEFTWING_INDEX);
		$rightWing = new RoomDefinition(self::RIGHTWING_INDEX);
		$roomGrid[self::GRIDROOM_TOP_CONNECT_INDEX]->setConnection(Direction::UP, $roofRoom);
		$roomGrid[self::GRIDROOM_LEFTWING_CONNECT_INDEX]->setConnection(Direction::SOUTH, $leftWing);
		$roomGrid[self::GRIDROOM_RIGHTWING_CONNECT_INDEX]->setConnection(Direction::SOUTH, $rightWing);
		$roofRoom->claimed = true;
		$leftWing->claimed = true;
		$rightWing->claimed = true;
		$this->sourceRoom->isSource = true;
		$this->coreRoom = $roomGrid[self::getRoomIndex($random->nextBoundedInt(4), 0, 2)];
		$core = $this->coreRoom;
		$core->claimed = true;
		$core->get(Direction::EAST)->claimed = true;
		$core->get(Direction::NORTH)->claimed = true;
		$core->get(Direction::EAST)->get(Direction::NORTH)->claimed = true;
		$core->get(Direction::UP)->claimed = true;
		$core->get(Direction::EAST)->get(Direction::UP)->claimed = true;
		$core->get(Direction::NORTH)->get(Direction::UP)->claimed = true;
		$core->get(Direction::EAST)->get(Direction::NORTH)->get(Direction::UP)->claimed = true;
		//the grid array in index order (null cells skipped)
		$roomDefs = [];
		for($i = 0; $i < self::GRID_SIZE; ++$i){
			if(isset($roomGrid[$i])){
				$roomGrid[$i]->updateOpenings();
				$roomDefs[] = $roomGrid[$i];
			}
		}
		$roofRoom->updateOpenings();
		StructureRandom::shuffle($roomDefs, $random);
		$scanIndex = 1;
		foreach($roomDefs as $definition){
			$closeCount = 0;
			$attemptCount = 0;
			while($closeCount < 2 && $attemptCount < 5){
				++$attemptCount;
				$f = $random->nextBoundedInt(6);
				if(!$definition->hasOpening[$f]){
					continue;
				}
				$of = Direction::OPPOSITE[$f];
				$neighbour = $definition->get($f);
				$definition->hasOpening[$f] = false;
				$neighbour->hasOpening[$of] = false;
				if($definition->findSource($scanIndex++) && $neighbour->findSource($scanIndex++)){
					++$closeCount;
				}else{
					$definition->hasOpening[$f] = true;
					$neighbour->hasOpening[$of] = true;
				}
			}
		}
		$roomDefs[] = $roofRoom;
		$roomDefs[] = $leftWing;
		$roomDefs[] = $rightWing;
		return $roomDefs;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$waterHeight = max($c->env->seaLevel, 64) - $this->box->minY;
		$this->generateWaterBox($c, $box, 0, 0, 0, 58, $waterHeight, 58);
		$this->generateWing($c, false, 0, $box);
		$this->generateWing($c, true, 33, $box);
		$this->generateEntranceArchs($c, $box);
		$this->generateEntranceWall($c, $box);
		$this->generateRoofPiece($c, $box);
		$this->generateLowerWall($c, $box);
		$this->generateMiddleWall($c, $box);
		$this->generateUpperWall($c, $box);
		$light = $this->state($c, "minecraft:prismarine_bricks");
		for($pillarX = 0; $pillarX < 7; ++$pillarX){
			$pillarZ = 0;
			while($pillarZ < 7){
				if($pillarZ === 0 && $pillarX === 3){
					$pillarZ = 6;
				}
				$bx = $pillarX * 9;
				$bz = $pillarZ * 9;
				for($w = 0; $w < 4; ++$w){
					for($d = 0; $d < 4; ++$d){
						$this->placeBlock($c, $light, $bx + $w, 0, $bz + $d, $box);
						$this->fillColumnDown($c, $light, $bx + $w, -1, $bz + $d, $box);
					}
				}
				if($pillarX === 0 || $pillarX === 6){
					++$pillarZ;
				}else{
					$pillarZ += 6;
				}
			}
		}
		for($i = 0; $i < 5; ++$i){
			$this->generateWaterBox($c, $box, -1 - $i, 0 + $i * 2, -1 - $i, -1 - $i, 23, 58 + $i);
			$this->generateWaterBox($c, $box, 58 + $i, 0 + $i * 2, -1 - $i, 58 + $i, 23, 58 + $i);
			$this->generateWaterBox($c, $box, 0 - $i, 0 + $i * 2, -1 - $i, 57 + $i, 23, -1 - $i);
			$this->generateWaterBox($c, $box, 0 - $i, 0 + $i * 2, 58 + $i, 57 + $i, 23, 58 + $i);
		}
		foreach($this->childPieces as $child){
			if($child->box->intersects($box)){
				$child->postProcess($c, $box, $chunkX, $chunkZ, $pivot);
			}
		}
	}

	private function generateWing(StructurePlaceContext $c, bool $isFlipped, int $xoff, BoundingBox $box) : void{
		if(!$this->chunkIntersects($box, $xoff, 0, $xoff + 23, 20)){
			return;
		}
		[$gray, $light] = $this->palette($c);
		$this->fillBox($c, $box, $xoff + 0, 0, 0, $xoff + 24, 0, 20, $gray);
		$this->generateWaterBox($c, $box, $xoff + 0, 1, 0, $xoff + 24, 10, 20);
		for($i = 0; $i < 4; ++$i){
			$this->fillBox($c, $box, $xoff + $i, $i + 1, $i, $xoff + $i, $i + 1, 20, $light);
			$this->fillBox($c, $box, $xoff + $i + 7, $i + 5, $i + 7, $xoff + $i + 7, $i + 5, 20, $light);
			$this->fillBox($c, $box, $xoff + 17 - $i, $i + 5, $i + 7, $xoff + 17 - $i, $i + 5, 20, $light);
			$this->fillBox($c, $box, $xoff + 24 - $i, $i + 1, $i, $xoff + 24 - $i, $i + 1, 20, $light);
			$this->fillBox($c, $box, $xoff + $i + 1, $i + 1, $i, $xoff + 23 - $i, $i + 1, $i, $light);
			$this->fillBox($c, $box, $xoff + $i + 8, $i + 5, $i + 7, $xoff + 16 - $i, $i + 5, $i + 7, $light);
		}
		$this->fillBox($c, $box, $xoff + 4, 4, 4, $xoff + 6, 4, 20, $gray);
		$this->fillBox($c, $box, $xoff + 7, 4, 4, $xoff + 17, 4, 6, $gray);
		$this->fillBox($c, $box, $xoff + 18, 4, 4, $xoff + 20, 4, 20, $gray);
		$this->fillBox($c, $box, $xoff + 11, 8, 11, $xoff + 13, 8, 20, $gray);
		$this->placeBlock($c, $light, $xoff + 12, 9, 12, $box);
		$this->placeBlock($c, $light, $xoff + 12, 9, 15, $box);
		$this->placeBlock($c, $light, $xoff + 12, 9, 18, $box);
		$leftPos = $xoff + ($isFlipped ? 19 : 5);
		$rightPos = $xoff + ($isFlipped ? 5 : 19);
		for($z = 20; $z >= 5; $z -= 3){
			$this->placeBlock($c, $light, $leftPos, 5, $z, $box);
		}
		for($z = 19; $z >= 7; $z -= 3){
			$this->placeBlock($c, $light, $rightPos, 5, $z, $box);
		}
		for($i = 0; $i < 4; ++$i){
			$pos = $isFlipped ? $xoff + 24 - (17 - $i * 3) : $xoff + 17 - $i * 3;
			$this->placeBlock($c, $light, $pos, 5, 5, $box);
		}
		$this->placeBlock($c, $light, $rightPos, 5, 5, $box);
		$this->fillBox($c, $box, $xoff + 11, 1, 12, $xoff + 13, 7, 12, $gray);
		$this->fillBox($c, $box, $xoff + 12, 1, 11, $xoff + 12, 7, 13, $gray);
	}

	private function generateEntranceArchs(StructurePlaceContext $c, BoundingBox $box) : void{
		if(!$this->chunkIntersects($box, 22, 5, 35, 17)){
			return;
		}
		[$gray, $light, , $lamp] = $this->palette($c);
		$this->generateWaterBox($c, $box, 25, 0, 0, 32, 8, 20);
		for($i = 0; $i < 4; ++$i){
			$z = 5 + $i * 4;
			$this->fillBox($c, $box, 24, 2, $z, 24, 4, $z, $light);
			$this->fillBox($c, $box, 22, 4, $z, 23, 4, $z, $light);
			$this->placeBlock($c, $light, 25, 5, $z, $box);
			$this->placeBlock($c, $light, 26, 6, $z, $box);
			$this->placeBlock($c, $lamp, 26, 5, $z, $box);
			$this->fillBox($c, $box, 33, 2, $z, 33, 4, $z, $light);
			$this->fillBox($c, $box, 34, 4, $z, 35, 4, $z, $light);
			$this->placeBlock($c, $light, 32, 5, $z, $box);
			$this->placeBlock($c, $light, 31, 6, $z, $box);
			$this->placeBlock($c, $lamp, 31, 5, $z, $box);
			$this->fillBox($c, $box, 27, 6, $z, 30, 6, $z, $gray);
		}
	}

	private function generateEntranceWall(StructurePlaceContext $c, BoundingBox $box) : void{
		if(!$this->chunkIntersects($box, 15, 20, 42, 21)){
			return;
		}
		[$gray, $light, $black] = $this->palette($c);
		$this->fillBox($c, $box, 15, 0, 21, 42, 0, 21, $gray);
		$this->generateWaterBox($c, $box, 26, 1, 21, 31, 3, 21);
		$this->fillBox($c, $box, 21, 12, 21, 36, 12, 21, $gray);
		$this->fillBox($c, $box, 17, 11, 21, 40, 11, 21, $gray);
		$this->fillBox($c, $box, 16, 10, 21, 41, 10, 21, $gray);
		$this->fillBox($c, $box, 15, 7, 21, 42, 9, 21, $gray);
		$this->fillBox($c, $box, 16, 6, 21, 41, 6, 21, $gray);
		$this->fillBox($c, $box, 17, 5, 21, 40, 5, 21, $gray);
		$this->fillBox($c, $box, 21, 4, 21, 36, 4, 21, $gray);
		$this->fillBox($c, $box, 22, 3, 21, 26, 3, 21, $gray);
		$this->fillBox($c, $box, 31, 3, 21, 35, 3, 21, $gray);
		$this->fillBox($c, $box, 23, 2, 21, 25, 2, 21, $gray);
		$this->fillBox($c, $box, 32, 2, 21, 34, 2, 21, $gray);
		$this->fillBox($c, $box, 28, 4, 20, 29, 4, 21, $light);
		$this->placeBlock($c, $light, 27, 3, 21, $box);
		$this->placeBlock($c, $light, 30, 3, 21, $box);
		$this->placeBlock($c, $light, 26, 2, 21, $box);
		$this->placeBlock($c, $light, 31, 2, 21, $box);
		$this->placeBlock($c, $light, 25, 1, 21, $box);
		$this->placeBlock($c, $light, 32, 1, 21, $box);
		for($i = 0; $i < 7; ++$i){
			$this->placeBlock($c, $black, 28 - $i, 6 + $i, 21, $box);
			$this->placeBlock($c, $black, 29 + $i, 6 + $i, 21, $box);
		}
		for($i = 0; $i < 4; ++$i){
			$this->placeBlock($c, $black, 28 - $i, 9 + $i, 21, $box);
			$this->placeBlock($c, $black, 29 + $i, 9 + $i, 21, $box);
		}
		$this->placeBlock($c, $black, 28, 12, 21, $box);
		$this->placeBlock($c, $black, 29, 12, 21, $box);
		for($i = 0; $i < 3; ++$i){
			$this->placeBlock($c, $black, 22 - $i * 2, 8, 21, $box);
			$this->placeBlock($c, $black, 22 - $i * 2, 9, 21, $box);
			$this->placeBlock($c, $black, 35 + $i * 2, 8, 21, $box);
			$this->placeBlock($c, $black, 35 + $i * 2, 9, 21, $box);
		}
		$this->generateWaterBox($c, $box, 15, 13, 21, 42, 15, 21);
		$this->generateWaterBox($c, $box, 15, 1, 21, 15, 6, 21);
		$this->generateWaterBox($c, $box, 16, 1, 21, 16, 5, 21);
		$this->generateWaterBox($c, $box, 17, 1, 21, 20, 4, 21);
		$this->generateWaterBox($c, $box, 21, 1, 21, 21, 3, 21);
		$this->generateWaterBox($c, $box, 22, 1, 21, 22, 2, 21);
		$this->generateWaterBox($c, $box, 23, 1, 21, 24, 1, 21);
		$this->generateWaterBox($c, $box, 42, 1, 21, 42, 6, 21);
		$this->generateWaterBox($c, $box, 41, 1, 21, 41, 5, 21);
		$this->generateWaterBox($c, $box, 37, 1, 21, 40, 4, 21);
		$this->generateWaterBox($c, $box, 36, 1, 21, 36, 3, 21);
		$this->generateWaterBox($c, $box, 33, 1, 21, 34, 1, 21);
		$this->generateWaterBox($c, $box, 35, 1, 21, 35, 2, 21);
	}

	private function generateRoofPiece(StructurePlaceContext $c, BoundingBox $box) : void{
		if(!$this->chunkIntersects($box, 21, 21, 36, 36)){
			return;
		}
		[$gray, $light, , $lamp] = $this->palette($c);
		$this->fillBox($c, $box, 21, 0, 22, 36, 0, 36, $gray);
		$this->generateWaterBox($c, $box, 21, 1, 22, 36, 23, 36);
		for($i = 0; $i < 4; ++$i){
			$this->fillBox($c, $box, 21 + $i, 13 + $i, 21 + $i, 36 - $i, 13 + $i, 21 + $i, $light);
			$this->fillBox($c, $box, 21 + $i, 13 + $i, 36 - $i, 36 - $i, 13 + $i, 36 - $i, $light);
			$this->fillBox($c, $box, 21 + $i, 13 + $i, 22 + $i, 21 + $i, 13 + $i, 35 - $i, $light);
			$this->fillBox($c, $box, 36 - $i, 13 + $i, 22 + $i, 36 - $i, 13 + $i, 35 - $i, $light);
		}
		$this->fillBox($c, $box, 25, 16, 25, 32, 16, 32, $gray);
		$this->fillBox($c, $box, 25, 17, 25, 25, 19, 25, $light);
		$this->fillBox($c, $box, 32, 17, 25, 32, 19, 25, $light);
		$this->fillBox($c, $box, 25, 17, 32, 25, 19, 32, $light);
		$this->fillBox($c, $box, 32, 17, 32, 32, 19, 32, $light);
		$this->placeBlock($c, $light, 26, 20, 26, $box);
		$this->placeBlock($c, $light, 27, 21, 27, $box);
		$this->placeBlock($c, $lamp, 27, 20, 27, $box);
		$this->placeBlock($c, $light, 26, 20, 31, $box);
		$this->placeBlock($c, $light, 27, 21, 30, $box);
		$this->placeBlock($c, $lamp, 27, 20, 30, $box);
		$this->placeBlock($c, $light, 31, 20, 31, $box);
		$this->placeBlock($c, $light, 30, 21, 30, $box);
		$this->placeBlock($c, $lamp, 30, 20, 30, $box);
		$this->placeBlock($c, $light, 31, 20, 26, $box);
		$this->placeBlock($c, $light, 30, 21, 27, $box);
		$this->placeBlock($c, $lamp, 30, 20, 27, $box);
		$this->fillBox($c, $box, 28, 21, 27, 29, 21, 27, $gray);
		$this->fillBox($c, $box, 27, 21, 28, 27, 21, 29, $gray);
		$this->fillBox($c, $box, 28, 21, 30, 29, 21, 30, $gray);
		$this->fillBox($c, $box, 30, 21, 28, 30, 21, 29, $gray);
	}

	private function generateLowerWall(StructurePlaceContext $c, BoundingBox $box) : void{
		[$gray, $light] = $this->palette($c);
		if($this->chunkIntersects($box, 0, 21, 6, 58)){
			$this->fillBox($c, $box, 0, 0, 21, 6, 0, 57, $gray);
			$this->generateWaterBox($c, $box, 0, 1, 21, 6, 7, 57);
			$this->fillBox($c, $box, 4, 4, 21, 6, 4, 53, $gray);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, $i, $i + 1, 21, $i, $i + 1, 57 - $i, $light);
			}
			for($z = 23; $z < 53; $z += 3){
				$this->placeBlock($c, $light, 5, 5, $z, $box);
			}
			$this->placeBlock($c, $light, 5, 5, 52, $box);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, $i, $i + 1, 21, $i, $i + 1, 57 - $i, $light);
			}
			$this->fillBox($c, $box, 4, 1, 52, 6, 3, 52, $gray);
			$this->fillBox($c, $box, 5, 1, 51, 5, 3, 53, $gray);
		}
		if($this->chunkIntersects($box, 51, 21, 58, 58)){
			$this->fillBox($c, $box, 51, 0, 21, 57, 0, 57, $gray);
			$this->generateWaterBox($c, $box, 51, 1, 21, 57, 7, 57);
			$this->fillBox($c, $box, 51, 4, 21, 53, 4, 53, $gray);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, 57 - $i, $i + 1, 21, 57 - $i, $i + 1, 57 - $i, $light);
			}
			for($z = 23; $z < 53; $z += 3){
				$this->placeBlock($c, $light, 52, 5, $z, $box);
			}
			$this->placeBlock($c, $light, 52, 5, 52, $box);
			$this->fillBox($c, $box, 51, 1, 52, 53, 3, 52, $gray);
			$this->fillBox($c, $box, 52, 1, 51, 52, 3, 53, $gray);
		}
		if($this->chunkIntersects($box, 0, 51, 57, 57)){
			$this->fillBox($c, $box, 7, 0, 51, 50, 0, 57, $gray);
			$this->generateWaterBox($c, $box, 7, 1, 51, 50, 10, 57);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, $i + 1, $i + 1, 57 - $i, 56 - $i, $i + 1, 57 - $i, $light);
			}
		}
	}

	private function generateMiddleWall(StructurePlaceContext $c, BoundingBox $box) : void{
		[$gray, $light] = $this->palette($c);
		if($this->chunkIntersects($box, 7, 21, 13, 50)){
			$this->fillBox($c, $box, 7, 0, 21, 13, 0, 50, $gray);
			$this->generateWaterBox($c, $box, 7, 1, 21, 13, 10, 50);
			$this->fillBox($c, $box, 11, 8, 21, 13, 8, 53, $gray);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, $i + 7, $i + 5, 21, $i + 7, $i + 5, 54, $light);
			}
			for($z = 21; $z <= 45; $z += 3){
				$this->placeBlock($c, $light, 12, 9, $z, $box);
			}
		}
		if($this->chunkIntersects($box, 44, 21, 50, 54)){
			$this->fillBox($c, $box, 44, 0, 21, 50, 0, 50, $gray);
			$this->generateWaterBox($c, $box, 44, 1, 21, 50, 10, 50);
			$this->fillBox($c, $box, 44, 8, 21, 46, 8, 53, $gray);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, 50 - $i, $i + 5, 21, 50 - $i, $i + 5, 54, $light);
			}
			for($z = 21; $z <= 45; $z += 3){
				$this->placeBlock($c, $light, 45, 9, $z, $box);
			}
		}
		if($this->chunkIntersects($box, 8, 44, 49, 54)){
			$this->fillBox($c, $box, 14, 0, 44, 43, 0, 50, $gray);
			$this->generateWaterBox($c, $box, 14, 1, 44, 43, 10, 50);
			for($x = 12; $x <= 45; $x += 3){
				$this->placeBlock($c, $light, $x, 9, 45, $box);
				$this->placeBlock($c, $light, $x, 9, 52, $box);
				if($x === 12 || $x === 18 || $x === 24 || $x === 33 || $x === 39 || $x === 45){
					$this->placeBlock($c, $light, $x, 9, 47, $box);
					$this->placeBlock($c, $light, $x, 9, 50, $box);
					$this->placeBlock($c, $light, $x, 10, 45, $box);
					$this->placeBlock($c, $light, $x, 10, 46, $box);
					$this->placeBlock($c, $light, $x, 10, 51, $box);
					$this->placeBlock($c, $light, $x, 10, 52, $box);
					$this->placeBlock($c, $light, $x, 11, 47, $box);
					$this->placeBlock($c, $light, $x, 11, 50, $box);
					$this->placeBlock($c, $light, $x, 12, 48, $box);
					$this->placeBlock($c, $light, $x, 12, 49, $box);
				}
			}
			for($i = 0; $i < 3; ++$i){
				$this->fillBox($c, $box, 8 + $i, 5 + $i, 54, 49 - $i, 5 + $i, 54, $gray);
			}
			$this->fillBox($c, $box, 11, 8, 54, 46, 8, 54, $light);
			$this->fillBox($c, $box, 14, 8, 44, 43, 8, 53, $gray);
		}
	}

	private function generateUpperWall(StructurePlaceContext $c, BoundingBox $box) : void{
		[$gray, $light] = $this->palette($c);
		if($this->chunkIntersects($box, 14, 21, 20, 43)){
			$this->fillBox($c, $box, 14, 0, 21, 20, 0, 43, $gray);
			$this->generateWaterBox($c, $box, 14, 1, 22, 20, 14, 43);
			$this->fillBox($c, $box, 18, 12, 22, 20, 12, 39, $gray);
			$this->fillBox($c, $box, 18, 12, 21, 20, 12, 21, $light);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, $i + 14, $i + 9, 21, $i + 14, $i + 9, 43 - $i, $light);
			}
			for($z = 23; $z <= 39; $z += 3){
				$this->placeBlock($c, $light, 19, 13, $z, $box);
			}
		}
		if($this->chunkIntersects($box, 37, 21, 43, 43)){
			$this->fillBox($c, $box, 37, 0, 21, 43, 0, 43, $gray);
			$this->generateWaterBox($c, $box, 37, 1, 22, 43, 14, 43);
			$this->fillBox($c, $box, 37, 12, 22, 39, 12, 39, $gray);
			$this->fillBox($c, $box, 37, 12, 21, 39, 12, 21, $light);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, 43 - $i, $i + 9, 21, 43 - $i, $i + 9, 43 - $i, $light);
			}
			for($z = 23; $z <= 39; $z += 3){
				$this->placeBlock($c, $light, 38, 13, $z, $box);
			}
		}
		if($this->chunkIntersects($box, 15, 37, 42, 43)){
			$this->fillBox($c, $box, 21, 0, 37, 36, 0, 43, $gray);
			$this->generateWaterBox($c, $box, 21, 1, 37, 36, 14, 43);
			$this->fillBox($c, $box, 21, 12, 37, 36, 12, 39, $gray);
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, 15 + $i, $i + 9, 43 - $i, 42 - $i, $i + 9, 43 - $i, $light);
			}
			for($x = 21; $x <= 36; $x += 3){
				$this->placeBlock($c, $light, $x, 13, 38, $box);
			}
		}
	}
}
