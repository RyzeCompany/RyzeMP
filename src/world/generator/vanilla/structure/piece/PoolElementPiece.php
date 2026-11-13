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
use pocketmine\world\generator\vanilla\structure\jigsaw\PoolElement;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * A placed jigsaw element (vanilla's PoolElementStructurePiece).
 */
final class PoolElementPiece extends StructurePiece{
	/**
	 * Junctions with neighbouring pieces: [x, groundY, z, deltaY, destination projection].
	 * @phpstan-var list<array{int, int, int, int, int}>
	 */
	public array $junctions = [];

	public function __construct(
		public readonly PoolElement $element,
		public int $x,
		public int $y,
		public int $z,
		private int $groundLevelDelta,
		public readonly int $rotation,
		BoundingBox $box,
		public readonly bool $applyWaterlogging
	){
		parent::__construct($box, 0);
	}

	public function getGroundLevelDelta() : int{
		return $this->groundLevelDelta;
	}

	public function isRigid() : bool{
		return $this->element->projection === PoolElement::PROJECTION_RIGID;
	}

	public function move(int $dx, int $dy, int $dz) : void{
		parent::move($dx, $dy, $dz);
		$this->x += $dx;
		$this->y += $dy;
		$this->z += $dz;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		$this->element->place($c, $this->x, $this->y, $this->z, $pivot, $this->rotation, $chunkBox, $this->applyWaterlogging, false);
	}
}
