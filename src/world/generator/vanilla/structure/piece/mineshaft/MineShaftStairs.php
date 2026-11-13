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
 * Vanilla's MineshaftPieces.MineShaftStairs: a corridor going 5 blocks down.
 */
final class MineShaftStairs extends MineShaftPiece{

	public function __construct(int $genDepth, BoundingBox $box, int $direction, bool $mesa){
		parent::__construct($box, $genDepth, $mesa);
		$this->setOrientation($direction);
	}

	/**
	 * MineShaftStairs.findStairs() (vanilla takes a random it does not use)
	 */
	public static function findStairs(StructurePiecesBuilder $builder, int $footX, int $footY, int $footZ, int $direction) : ?BoundingBox{
		$box = match($direction){
			Direction::SOUTH => new BoundingBox(0, -5, 0, 2, 2, 8),
			Direction::WEST => new BoundingBox(-8, -5, 0, 0, 2, 2),
			Direction::EAST => new BoundingBox(0, -5, 0, 8, 2, 2),
			default => new BoundingBox(0, -5, -8, 2, 2, 0)
		};
		$box->move($footX, $footY, $footZ);
		return $builder->findCollisionPiece($box) !== null ? null : $box;
	}

	public function addChildren(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$depth = $this->genDepth;
		$box = $this->box;
		$orientation = $this->getOrientation();
		if($orientation === null){
			return;
		}
		switch($orientation){
			case Direction::SOUTH:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY, $box->maxZ + 1, Direction::SOUTH, $depth);
				break;
			case Direction::WEST:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY, $box->minZ, Direction::WEST, $depth);
				break;
			case Direction::EAST:
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY, $box->minZ, Direction::EAST, $depth);
				break;
			default: //NORTH
				MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY, $box->minZ - 1, Direction::NORTH, $depth);
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		if($this->isInInvalidLocation($c, $chunkBox)){
			return;
		}
		$caveAir = $c->blocks->caveAir;
		$this->generateBox($c, $chunkBox, 0, 5, 0, 2, 7, 1, $caveAir, $caveAir, false);
		$this->generateBox($c, $chunkBox, 0, 0, 7, 2, 2, 8, $caveAir, $caveAir, false);
		for($i = 0; $i < 5; ++$i){
			$this->generateBox($c, $chunkBox, 0, 5 - $i - ($i < 4 ? 1 : 0), 2 + $i, 2, 7 - $i, 2 + $i, $caveAir, $caveAir, false);
		}
	}
}
