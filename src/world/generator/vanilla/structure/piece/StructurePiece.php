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

use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * A piece of a structure start (vanilla's StructurePiece): a bounding box and the code that places the piece's blocks
 * inside the box of the chunk being populated.
 */
abstract class StructurePiece{

	public function __construct(
		public BoundingBox $box,
		public int $genDepth = 0
	){}

	/**
	 * StructurePiece.postProcess(): places the part of the piece inside the chunk box.
	 *
	 * @param int[] $pivot the structure's reference position (center of the first piece's box at its min y)
	 * @phpstan-param array{int, int, int} $pivot
	 */
	abstract public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void;

	/**
	 * Whether the terrain is adapted to this piece (beardifier): jigsaw pieces with terrain matching projection are
	 * not.
	 */
	public function isRigid() : bool{
		return true;
	}

	/**
	 * Height above the piece's minimum y of the ground the piece stands on (jigsaw pieces' ground level delta).
	 */
	public function getGroundLevelDelta() : int{
		return 0;
	}

	public function move(int $dx, int $dy, int $dz) : void{
		$this->box->move($dx, $dy, $dz);
	}
}
