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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;
use function count;

/**
 * A generated structure (vanilla's StructureStart): the structure, its start chunk and its pieces. Starts are pure
 * functions of the seed and the start chunk, so every generator worker computes the same start independently.
 */
final class StructureStart{
	private ?BoundingBox $box = null;

	/**
	 * @param StructurePiece[] $pieces
	 * @phpstan-param list<StructurePiece> $pieces
	 */
	public function __construct(
		public readonly StructureDefinition $structure,
		public readonly int $chunkX,
		public readonly int $chunkZ,
		public readonly array $pieces
	){}

	public function isValid() : bool{
		return count($this->pieces) > 0;
	}

	/**
	 * StructureStart.getBoundingBox(): the box of all pieces, inflated by 12 blocks when the structure adapts the
	 * terrain (Structure.adjustBoundingBox()).
	 */
	public function getBoundingBox() : BoundingBox{
		if($this->box === null){
			$box = null;
			foreach($this->pieces as $piece){
				$box = $box === null ? $piece->box->copy() : $box->encapsulate($piece->box);
			}
			$box ??= new BoundingBox(0, 0, 0, 0, 0, 0);
			$this->box = $this->structure->terrainAdaptation !== StructureDefinition::ADAPTATION_NONE ? $box->inflatedBy(12) : $box;
		}
		return $this->box;
	}

	/**
	 * The box of the pieces only (without the terrain adaptation margin).
	 */
	public function getPiecesBoundingBox() : BoundingBox{
		$box = null;
		foreach($this->pieces as $piece){
			$box = $box === null ? $piece->box->copy() : $box->encapsulate($piece->box);
		}
		return $box ?? new BoundingBox(0, 0, 0, 0, 0, 0);
	}

	/**
	 * StructureStart.placeInChunk()
	 */
	public function placeInChunk(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		if(count($this->pieces) === 0){
			return;
		}
		$first = $this->pieces[0]->box;
		[$cx, , $cz] = $first->getCenter();
		$pivot = [$cx, $first->minY, $cz];
		foreach($this->pieces as $piece){
			if($piece->box->intersects($chunkBox)){
				$piece->postProcess($c, $chunkBox, $chunkX, $chunkZ, $pivot);
			}
		}
		$this->structure->type->afterPlace($c, $this, $chunkBox, $chunkX, $chunkZ);
	}
}
