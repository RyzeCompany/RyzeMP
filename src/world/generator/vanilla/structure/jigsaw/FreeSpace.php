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

namespace pocketmine\world\generator\vanilla\structure\jigsaw;

use pocketmine\world\generator\vanilla\structure\BoundingBox;
use function count;

/**
 * The space where jigsaw pieces may still be placed: vanilla's VoxelShape (a box minus the boxes of placed pieces),
 * for block-aligned boxes. A piece fits when its box (deflated by 0.25 in vanilla) is inside the outer box and does
 * not overlap any placed box.
 *
 * The placed boxes are kept in a grid of 16-block cells, so a fit check only looks at the boxes near the candidate:
 * a trial chambers start places ~500 pieces after trying thousands of candidates, and checking every candidate
 * against every placed box took seconds.
 */
final class FreeSpace{
	private const CELL_SHIFT = 4;
	private const KEY_BITS = 21;
	private const KEY_MASK = (1 << self::KEY_BITS) - 1;

	/**
	 * @var BoundingBox[][] cell key => placed boxes overlapping the cell (a box spanning several cells is in each)
	 * @phpstan-var array<int, list<BoundingBox>>
	 */
	private array $cells = [];

	public function __construct(
		private BoundingBox $outer
	){}

	private static function key(int $cellX, int $cellY, int $cellZ) : int{
		return (($cellX & self::KEY_MASK) << (2 * self::KEY_BITS)) | (($cellY & self::KEY_MASK) << self::KEY_BITS) | ($cellZ & self::KEY_MASK);
	}

	public function fits(BoundingBox $box) : bool{
		if(!$this->outer->contains($box)){
			return false;
		}
		$cells = $this->cells;
		if(count($cells) === 0){
			return true;
		}
		$boxMinX = $box->minX;
		$boxMinY = $box->minY;
		$boxMinZ = $box->minZ;
		$boxMaxX = $box->maxX;
		$boxMaxY = $box->maxY;
		$boxMaxZ = $box->maxZ;
		$maxCellX = $boxMaxX >> self::CELL_SHIFT;
		$maxCellY = $boxMaxY >> self::CELL_SHIFT;
		$maxCellZ = $boxMaxZ >> self::CELL_SHIFT;
		for($cellX = $boxMinX >> self::CELL_SHIFT; $cellX <= $maxCellX; ++$cellX){
			for($cellY = $boxMinY >> self::CELL_SHIFT; $cellY <= $maxCellY; ++$cellY){
				for($cellZ = $boxMinZ >> self::CELL_SHIFT; $cellZ <= $maxCellZ; ++$cellZ){
					$taken = $cells[self::key($cellX, $cellY, $cellZ)] ?? null;
					if($taken === null){
						continue;
					}
					foreach($taken as $t){
						//inlined BoundingBox::intersects()
						if($t->maxX >= $boxMinX && $t->minX <= $boxMaxX && $t->maxZ >= $boxMinZ && $t->minZ <= $boxMaxZ && $t->maxY >= $boxMinY && $t->minY <= $boxMaxY){
							return false;
						}
					}
				}
			}
		}
		return true;
	}

	public function subtract(BoundingBox $box) : void{
		$copy = $box->copy();
		$maxCellX = $copy->maxX >> self::CELL_SHIFT;
		$maxCellY = $copy->maxY >> self::CELL_SHIFT;
		$maxCellZ = $copy->maxZ >> self::CELL_SHIFT;
		for($cellX = $copy->minX >> self::CELL_SHIFT; $cellX <= $maxCellX; ++$cellX){
			for($cellY = $copy->minY >> self::CELL_SHIFT; $cellY <= $maxCellY; ++$cellY){
				for($cellZ = $copy->minZ >> self::CELL_SHIFT; $cellZ <= $maxCellZ; ++$cellZ){
					$this->cells[self::key($cellX, $cellY, $cellZ)][] = $copy;
				}
			}
		}
	}
}
