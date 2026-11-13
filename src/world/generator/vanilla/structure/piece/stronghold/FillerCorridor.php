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
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * StrongholdPieces.FillerCorridor: a short dead-end corridor filling the gap up to the piece in front of a door.
 */
final class FillerCorridor extends StrongholdPiece{
	private readonly int $steps;

	public function __construct(int $genDepth, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->steps = $direction === Direction::NORTH || $direction === Direction::SOUTH ? $box->getZSpan() : $box->getXSpan();
	}

	/**
	 * FillerCorridor.findPieceBox() (its random argument is unused)
	 */
	public static function findPieceBox(StructurePiecesBuilder $builder, int $x, int $y, int $z, int $direction) : ?BoundingBox{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 5, 5, 4, $direction);
		$collision = $builder->findCollisionPiece($box);
		if($collision === null || $collision->box->minY !== $box->minY){
			return null;
		}
		for($depth = 2; $depth >= 1; --$depth){
			$box = BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 5, 5, $depth, $direction);
			if(!$collision->box->intersects($box)){
				return BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 5, 5, $depth + 1, $direction);
			}
		}
		return null;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->state($c, "minecraft:stone_bricks");
		$caveAir = $c->blocks->caveAir;
		for($i = 0; $i < $this->steps; ++$i){
			for($x = 0; $x <= 4; ++$x){
				$this->placeBlock($c, $bricks, $x, 0, $i, $box);
			}
			for($y = 1; $y <= 3; ++$y){
				$this->placeBlock($c, $bricks, 0, $y, $i, $box);
				$this->placeBlock($c, $caveAir, 1, $y, $i, $box);
				$this->placeBlock($c, $caveAir, 2, $y, $i, $box);
				$this->placeBlock($c, $caveAir, 3, $y, $i, $box);
				$this->placeBlock($c, $bricks, 4, $y, $i, $box);
			}
			for($x = 0; $x <= 4; ++$x){
				$this->placeBlock($c, $bricks, $x, 4, $i, $box);
			}
		}
	}
}
