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

namespace pocketmine\world\generator\vanilla\structure\piece;

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use function count;

/**
 * StructurePiecesBuilder (+ StructurePieceAccessor): the pieces of a code-built structure start while they are
 * generated.
 */
final class StructurePiecesBuilder{
	/**
	 * @var StructurePiece[]
	 * @phpstan-var list<StructurePiece>
	 */
	public array $pieces = [];

	public function addPiece(StructurePiece $piece) : void{
		$this->pieces[] = $piece;
	}

	/**
	 * StructurePiece.findCollisionPiece(): the first piece whose box intersects the given box.
	 */
	public function findCollisionPiece(BoundingBox $box) : ?StructurePiece{
		foreach($this->pieces as $piece){
			if($piece->box->intersects($box)){
				return $piece;
			}
		}
		return null;
	}

	public function offsetPiecesVertically(int $dy) : void{
		foreach($this->pieces as $piece){
			$piece->move(0, $dy, 0);
		}
	}

	/**
	 * Moves the pieces down to a random height below sea level - offset; returns the offset applied.
	 */
	public function moveBelowSeaLevel(int $seaLevel, int $minY, LegacyRandom|FeatureRandom $random, int $offset) : int{
		$maxY = $seaLevel - $offset;
		$box = $this->getBoundingBox();
		$y1 = $box->getYSpan() + $minY + 1;
		if($y1 < $maxY){
			$y1 += $random->nextBoundedInt($maxY - $y1);
		}
		$dy = $y1 - $box->maxY;
		$this->offsetPiecesVertically($dy);
		return $dy;
	}

	public function moveInsideHeights(LegacyRandom|FeatureRandom $random, int $lowestAllowed, int $highestAllowed) : void{
		$box = $this->getBoundingBox();
		$heightSpan = $highestAllowed - $lowestAllowed + 1 - $box->getYSpan();
		$y0 = $heightSpan > 1 ? $lowestAllowed + $random->nextBoundedInt($heightSpan) : $lowestAllowed;
		$this->offsetPiecesVertically($y0 - $box->minY);
	}

	public function getBoundingBox() : BoundingBox{
		$box = null;
		foreach($this->pieces as $piece){
			$box = $box === null ? $piece->box->copy() : $box->encapsulate($piece->box);
		}
		if($box === null){
			throw new \LogicException("Unable to calculate boundingbox without pieces");
		}
		return $box;
	}

	public function clear() : void{
		$this->pieces = [];
	}

	public function isEmpty() : bool{
		return count($this->pieces) === 0;
	}
}
