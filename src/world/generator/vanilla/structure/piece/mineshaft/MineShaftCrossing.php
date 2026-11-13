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

namespace pocketmine\world\generator\vanilla\structure\piece\mineshaft;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * Vanilla's MineshaftPieces.MineShaftCrossing: a crossing of corridors (one or two floors) with four planks pillars.
 * It has no orientation: its coordinates are world coordinates.
 */
final class MineShaftCrossing extends MineShaftPiece{
	private bool $isTwoFloored;

	public function __construct(int $genDepth, BoundingBox $box, private int $direction, bool $mesa){
		parent::__construct($box, $genDepth, $mesa);
		$this->isTwoFloored = $box->getYSpan() > 3;
	}

	/**
	 * MineShaftCrossing.findCrossing()
	 */
	public static function findCrossing(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction) : ?BoundingBox{
		$y1 = $random->nextBoundedInt(4) === 0 ? 6 : 2;
		$box = match($direction){
			Direction::SOUTH => new BoundingBox(-1, 0, 0, 3, $y1, 4),
			Direction::WEST => new BoundingBox(-4, 0, -1, 0, $y1, 3),
			Direction::EAST => new BoundingBox(0, 0, -1, 4, $y1, 3),
			default => new BoundingBox(-1, 0, -4, 3, $y1, 0)
		};
		$box->move($footX, $footY, $footZ);
		return $builder->findCollisionPiece($box) !== null ? null : $box;
	}

	public function addChildren(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$depth = $this->genDepth;
		$box = $this->box;
		switch($this->direction){
			case Direction::SOUTH:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->maxZ + 1, Direction::SOUTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY, $box->minZ + 1, Direction::WEST, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY, $box->minZ + 1, Direction::EAST, $depth);
				break;
			case Direction::WEST:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->minZ - 1, Direction::NORTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->maxZ + 1, Direction::SOUTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY, $box->minZ + 1, Direction::WEST, $depth);
				break;
			case Direction::EAST:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->minZ - 1, Direction::NORTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->maxZ + 1, Direction::SOUTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY, $box->minZ + 1, Direction::EAST, $depth);
				break;
			default: //NORTH
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY, $box->minZ - 1, Direction::NORTH, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY, $box->minZ + 1, Direction::WEST, $depth);
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY, $box->minZ + 1, Direction::EAST, $depth);
		}

		if($this->isTwoFloored){
			if($random->nextBoolean()){
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY + 3 + 1, $box->minZ - 1, Direction::NORTH, $depth);
			}
			if($random->nextBoolean()){
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY + 3 + 1, $box->minZ + 1, Direction::WEST, $depth);
			}
			if($random->nextBoolean()){
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY + 3 + 1, $box->minZ + 1, Direction::EAST, $depth);
			}
			if($random->nextBoolean()){
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + 1, $box->minY + 3 + 1, $box->maxZ + 1, Direction::SOUTH, $depth);
			}
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		if($this->isInInvalidLocation($c, $chunkBox)){
			return;
		}
		$box = $this->box;
		$caveAir = $c->blocks->caveAir;
		$planks = $this->state($c, $this->planksName());
		if($this->isTwoFloored){
			$this->generateBox($c, $chunkBox, $box->minX + 1, $box->minY, $box->minZ, $box->maxX - 1, $box->minY + 3 - 1, $box->maxZ, $caveAir, $caveAir, false);
			$this->generateBox($c, $chunkBox, $box->minX, $box->minY, $box->minZ + 1, $box->maxX, $box->minY + 3 - 1, $box->maxZ - 1, $caveAir, $caveAir, false);
			$this->generateBox($c, $chunkBox, $box->minX + 1, $box->maxY - 2, $box->minZ, $box->maxX - 1, $box->maxY, $box->maxZ, $caveAir, $caveAir, false);
			$this->generateBox($c, $chunkBox, $box->minX, $box->maxY - 2, $box->minZ + 1, $box->maxX, $box->maxY, $box->maxZ - 1, $caveAir, $caveAir, false);
			$this->generateBox($c, $chunkBox, $box->minX + 1, $box->minY + 3, $box->minZ + 1, $box->maxX - 1, $box->minY + 3, $box->maxZ - 1, $caveAir, $caveAir, false);
		}else{
			$this->generateBox($c, $chunkBox, $box->minX + 1, $box->minY, $box->minZ, $box->maxX - 1, $box->maxY, $box->maxZ, $caveAir, $caveAir, false);
			$this->generateBox($c, $chunkBox, $box->minX, $box->minY, $box->minZ + 1, $box->maxX, $box->maxY, $box->maxZ - 1, $caveAir, $caveAir, false);
		}
		$this->placeSupportPillar($c, $chunkBox, $box->minX + 1, $box->minY, $box->minZ + 1, $box->maxY, $planks);
		$this->placeSupportPillar($c, $chunkBox, $box->minX + 1, $box->minY, $box->maxZ - 1, $box->maxY, $planks);
		$this->placeSupportPillar($c, $chunkBox, $box->maxX - 1, $box->minY, $box->minZ + 1, $box->maxY, $planks);
		$this->placeSupportPillar($c, $chunkBox, $box->maxX - 1, $box->minY, $box->maxZ - 1, $box->maxY, $planks);
		$y = $box->minY - 1;
		for($x = $box->minX; $x <= $box->maxX; ++$x){
			for($z = $box->minZ; $z <= $box->maxZ; ++$z){
				$this->setPlanksBlock($c, $chunkBox, $planks, $x, $y, $z);
			}
		}
	}

	/**
	 * MineShaftCrossing.placeSupportPillar(): a planks pillar under a ceiling that is not air.
	 */
	private function placeSupportPillar(StructurePlaceContext $c, BoundingBox $box, int $x, int $y0, int $z, int $y1, int $planks) : void{
		if(!$c->blocks->isAir($this->getBlock($c, $x, $y1 + 1, $z, $box))){
			$this->generateBox($c, $box, $x, $y0, $z, $x, $y1, $z, $planks, $c->blocks->caveAir, false);
		}
	}
}
