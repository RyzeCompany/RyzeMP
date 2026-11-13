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
use function min;

/**
 * Vanilla's MineshaftPieces.MineShaftRoom: the start room, a cave air box with a dome, with corridors on its sides.
 */
final class MineShaftRoom extends MineShaftPiece{
	/**
	 * @var BoundingBox[]
	 * @phpstan-var list<BoundingBox>
	 */
	private array $childEntranceBoxes = [];

	public function __construct(int $genDepth, LegacyRandom $random, int $west, int $north, bool $mesa){
		$maxX = $west + 7 + $random->nextBoundedInt(6);
		$maxY = 54 + $random->nextBoundedInt(6);
		$maxZ = $north + 7 + $random->nextBoundedInt(6);
		parent::__construct(new BoundingBox($west, 50, $north, $maxX, $maxY, $maxZ), $genDepth, $mesa);
	}

	public function addChildren(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$depth = $this->genDepth;
		$box = $this->box;
		$heightSpace = $box->getYSpan() - 3 - 1;
		if($heightSpace <= 0){
			$heightSpace = 1;
		}

		for($pos = 0; $pos < $box->getXSpan(); $pos += 4){
			$pos += $random->nextBoundedInt($box->getXSpan());
			if($pos + 3 > $box->getXSpan()){
				break;
			}
			$child = MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + $pos, $box->minY + $random->nextBoundedInt($heightSpace) + 1, $box->minZ - 1, Direction::NORTH, $depth);
			if($child !== null){
				$childBox = $child->box;
				$this->childEntranceBoxes[] = new BoundingBox($childBox->minX, $childBox->minY, $box->minZ, $childBox->maxX, $childBox->maxY, $box->minZ + 1);
			}
		}

		for($pos = 0; $pos < $box->getXSpan(); $pos += 4){
			$pos += $random->nextBoundedInt($box->getXSpan());
			if($pos + 3 > $box->getXSpan()){
				break;
			}
			$child = MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX + $pos, $box->minY + $random->nextBoundedInt($heightSpace) + 1, $box->maxZ + 1, Direction::SOUTH, $depth);
			if($child !== null){
				$childBox = $child->box;
				$this->childEntranceBoxes[] = new BoundingBox($childBox->minX, $childBox->minY, $box->maxZ - 1, $childBox->maxX, $childBox->maxY, $box->maxZ);
			}
		}

		for($pos = 0; $pos < $box->getZSpan(); $pos += 4){
			$pos += $random->nextBoundedInt($box->getZSpan());
			if($pos + 3 > $box->getZSpan()){
				break;
			}
			$child = MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY + $random->nextBoundedInt($heightSpace) + 1, $box->minZ + $pos, Direction::WEST, $depth);
			if($child !== null){
				$childBox = $child->box;
				$this->childEntranceBoxes[] = new BoundingBox($box->minX, $childBox->minY, $childBox->minZ, $box->minX + 1, $childBox->maxY, $childBox->maxZ);
			}
		}

		for($pos = 0; $pos < $box->getZSpan(); $pos += 4){
			$pos += $random->nextBoundedInt($box->getZSpan());
			if($pos + 3 > $box->getZSpan()){
				break;
			}
			$child = MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY + $random->nextBoundedInt($heightSpace) + 1, $box->minZ + $pos, Direction::EAST, $depth);
			if($child !== null){
				$childBox = $child->box;
				$this->childEntranceBoxes[] = new BoundingBox($box->maxX - 1, $childBox->minY, $childBox->minZ, $box->maxX, $childBox->maxY, $childBox->maxZ);
			}
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		if($this->isInInvalidLocation($c, $chunkBox)){
			return;
		}
		$box = $this->box;
		$caveAir = $c->blocks->caveAir;
		$this->generateBox($c, $chunkBox, $box->minX, $box->minY + 1, $box->minZ, $box->maxX, min($box->minY + 3, $box->maxY), $box->maxZ, $caveAir, $caveAir, false);
		foreach($this->childEntranceBoxes as $entrance){
			$this->generateBox($c, $chunkBox, $entrance->minX, $entrance->maxY - 2, $entrance->minZ, $entrance->maxX, $entrance->maxY, $entrance->maxZ, $caveAir, $caveAir, false);
		}
		$this->generateUpperHalfSphere($c, $chunkBox, $box->minX, $box->minY + 4, $box->minZ, $box->maxX, $box->maxY, $box->maxZ, $caveAir, false);
	}

	public function move(int $dx, int $dy, int $dz) : void{
		parent::move($dx, $dy, $dz);
		foreach($this->childEntranceBoxes as $entrance){
			$entrance->move($dx, $dy, $dz);
		}
	}
}
