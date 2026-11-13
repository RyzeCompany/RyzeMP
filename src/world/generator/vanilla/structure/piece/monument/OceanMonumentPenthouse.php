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

use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * OceanMonumentPieces.OceanMonumentPenthouse: the top room, with an elder guardian.
 */
final class OceanMonumentPenthouse extends OceanMonumentPiece{

	public function __construct(int $orientation, BoundingBox $box){
		parent::__construct($orientation, 1, $box);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, $black, $lamp] = $this->palette($c);
		$this->fillBox($c, $box, 2, -1, 2, 11, -1, 11, $light);
		$this->fillBox($c, $box, 0, -1, 0, 1, -1, 11, $gray);
		$this->fillBox($c, $box, 12, -1, 0, 13, -1, 11, $gray);
		$this->fillBox($c, $box, 2, -1, 0, 11, -1, 1, $gray);
		$this->fillBox($c, $box, 2, -1, 12, 11, -1, 13, $gray);
		$this->fillBox($c, $box, 0, 0, 0, 0, 0, 13, $light);
		$this->fillBox($c, $box, 13, 0, 0, 13, 0, 13, $light);
		$this->fillBox($c, $box, 1, 0, 0, 12, 0, 0, $light);
		$this->fillBox($c, $box, 1, 0, 13, 12, 0, 13, $light);
		for($i = 2; $i <= 11; $i += 3){
			$this->placeBlock($c, $lamp, 0, 0, $i, $box);
			$this->placeBlock($c, $lamp, 13, 0, $i, $box);
			$this->placeBlock($c, $lamp, $i, 0, 0, $box);
		}
		$this->fillBox($c, $box, 2, 0, 3, 4, 0, 9, $light);
		$this->fillBox($c, $box, 9, 0, 3, 11, 0, 9, $light);
		$this->fillBox($c, $box, 4, 0, 9, 9, 0, 11, $light);
		$this->placeBlock($c, $light, 5, 0, 8, $box);
		$this->placeBlock($c, $light, 8, 0, 8, $box);
		$this->placeBlock($c, $light, 10, 0, 10, $box);
		$this->placeBlock($c, $light, 3, 0, 10, $box);
		$this->fillBox($c, $box, 3, 0, 3, 3, 0, 7, $black);
		$this->fillBox($c, $box, 10, 0, 3, 10, 0, 7, $black);
		$this->fillBox($c, $box, 6, 0, 10, 7, 0, 10, $black);
		$x = 3;
		for($i = 0; $i < 2; ++$i){
			for($z = 2; $z <= 8; $z += 3){
				$this->fillBox($c, $box, $x, 0, $z, $x, 2, $z, $light);
			}
			$x = 10;
		}
		$this->fillBox($c, $box, 5, 0, 10, 5, 2, 10, $light);
		$this->fillBox($c, $box, 8, 0, 10, 8, 2, 10, $light);
		$this->fillBox($c, $box, 6, -1, 7, 7, -1, 8, $black);
		$this->generateWaterBox($c, $box, 6, -1, 3, 7, -1, 4);
	}
}
