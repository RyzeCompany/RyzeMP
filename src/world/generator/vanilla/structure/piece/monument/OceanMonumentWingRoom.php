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
 * OceanMonumentPieces.OceanMonumentWingRoom: the inside of a wing in one of two designs, each with an elder guardian.
 */
final class OceanMonumentWingRoom extends OceanMonumentPiece{
	private int $mainDesign;

	public function __construct(int $orientation, BoundingBox $box, int $randomValue){
		parent::__construct($orientation, 1, $box);
		$this->mainDesign = $randomValue & 1;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[, $light, $black, $lamp] = $this->palette($c);
		if($this->mainDesign === 0){
			for($i = 0; $i < 4; ++$i){
				$this->fillBox($c, $box, 10 - $i, 3 - $i, 20 - $i, 12 + $i, 3 - $i, 20, $light);
			}
			$this->fillBox($c, $box, 7, 0, 6, 15, 0, 16, $light);
			$this->fillBox($c, $box, 6, 0, 6, 6, 3, 20, $light);
			$this->fillBox($c, $box, 16, 0, 6, 16, 3, 20, $light);
			$this->fillBox($c, $box, 7, 1, 7, 7, 1, 20, $light);
			$this->fillBox($c, $box, 15, 1, 7, 15, 1, 20, $light);
			$this->fillBox($c, $box, 7, 1, 6, 9, 3, 6, $light);
			$this->fillBox($c, $box, 13, 1, 6, 15, 3, 6, $light);
			$this->fillBox($c, $box, 8, 1, 7, 9, 1, 7, $light);
			$this->fillBox($c, $box, 13, 1, 7, 14, 1, 7, $light);
			$this->fillBox($c, $box, 9, 0, 5, 13, 0, 5, $light);
			$this->fillBox($c, $box, 10, 0, 7, 12, 0, 7, $black);
			$this->fillBox($c, $box, 8, 0, 10, 8, 0, 12, $black);
			$this->fillBox($c, $box, 14, 0, 10, 14, 0, 12, $black);
			for($z = 18; $z >= 7; $z -= 3){
				$this->placeBlock($c, $lamp, 6, 3, $z, $box);
				$this->placeBlock($c, $lamp, 16, 3, $z, $box);
			}
			$this->placeBlock($c, $lamp, 10, 0, 10, $box);
			$this->placeBlock($c, $lamp, 12, 0, 10, $box);
			$this->placeBlock($c, $lamp, 10, 0, 12, $box);
			$this->placeBlock($c, $lamp, 12, 0, 12, $box);
			$this->placeBlock($c, $lamp, 8, 3, 6, $box);
			$this->placeBlock($c, $lamp, 14, 3, 6, $box);
			$this->placeBlock($c, $light, 4, 2, 4, $box);
			$this->placeBlock($c, $lamp, 4, 1, 4, $box);
			$this->placeBlock($c, $light, 4, 0, 4, $box);
			$this->placeBlock($c, $light, 18, 2, 4, $box);
			$this->placeBlock($c, $lamp, 18, 1, 4, $box);
			$this->placeBlock($c, $light, 18, 0, 4, $box);
			$this->placeBlock($c, $light, 4, 2, 18, $box);
			$this->placeBlock($c, $lamp, 4, 1, 18, $box);
			$this->placeBlock($c, $light, 4, 0, 18, $box);
			$this->placeBlock($c, $light, 18, 2, 18, $box);
			$this->placeBlock($c, $lamp, 18, 1, 18, $box);
			$this->placeBlock($c, $light, 18, 0, 18, $box);
			$this->placeBlock($c, $light, 9, 7, 20, $box);
			$this->placeBlock($c, $light, 13, 7, 20, $box);
			$this->fillBox($c, $box, 6, 0, 21, 7, 4, 21, $light);
			$this->fillBox($c, $box, 15, 0, 21, 16, 4, 21, $light);
		}elseif($this->mainDesign === 1){
			$this->fillBox($c, $box, 9, 3, 18, 13, 3, 20, $light);
			$this->fillBox($c, $box, 9, 0, 18, 9, 2, 18, $light);
			$this->fillBox($c, $box, 13, 0, 18, 13, 2, 18, $light);
			$x = 9;
			for($i = 0; $i < 2; ++$i){
				$this->placeBlock($c, $light, $x, 6, 20, $box);
				$this->placeBlock($c, $lamp, $x, 5, 20, $box);
				$this->placeBlock($c, $light, $x, 4, 20, $box);
				$x = 13;
			}
			$this->fillBox($c, $box, 7, 3, 7, 15, 3, 14, $light);
			$x = 10;
			for($i = 0; $i < 2; ++$i){
				$this->fillBox($c, $box, $x, 0, 10, $x, 6, 10, $light);
				$this->fillBox($c, $box, $x, 0, 12, $x, 6, 12, $light);
				$this->placeBlock($c, $lamp, $x, 0, 10, $box);
				$this->placeBlock($c, $lamp, $x, 0, 12, $box);
				$this->placeBlock($c, $lamp, $x, 4, 10, $box);
				$this->placeBlock($c, $lamp, $x, 4, 12, $box);
				$x = 12;
			}
			$x = 8;
			for($i = 0; $i < 2; ++$i){
				$this->fillBox($c, $box, $x, 0, 7, $x, 2, 7, $light);
				$this->fillBox($c, $box, $x, 0, 14, $x, 2, 14, $light);
				$x = 14;
			}
			$this->fillBox($c, $box, 8, 3, 8, 8, 3, 13, $black);
			$this->fillBox($c, $box, 14, 3, 8, 14, 3, 13, $black);
		}
	}
}
