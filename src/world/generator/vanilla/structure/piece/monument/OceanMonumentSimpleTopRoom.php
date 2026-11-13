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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function intdiv;

/**
 * OceanMonumentPieces.OceanMonumentSimpleTopRoom: a closed grid cell (only a south opening at most) holding the wet
 * sponges.
 */
final class OceanMonumentSimpleTopRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 1, 1), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, $black] = $this->palette($c);
		$room = $this->room();
		$random = $c->random;
		if(intdiv($room->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 0, 0, $room->hasOpening[Direction::DOWN]);
		}
		if($room->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 4, 1, 6, 4, 6, $gray);
		}
		$wetSponge = $this->state($c, "minecraft:wet_sponge");
		for($x = 1; $x <= 6; ++$x){
			for($z = 1; $z <= 6; ++$z){
				if($random->nextBoundedInt(3) !== 0){
					$y0 = 2 + ($random->nextBoundedInt(4) === 0 ? 0 : 1);
					$this->fillBox($c, $box, $x, $y0, $z, $x, 3, $z, $wetSponge);
				}
			}
		}
		$this->fillBox($c, $box, 0, 1, 0, 0, 1, 7, $light);
		$this->fillBox($c, $box, 7, 1, 0, 7, 1, 7, $light);
		$this->fillBox($c, $box, 1, 1, 0, 6, 1, 0, $light);
		$this->fillBox($c, $box, 1, 1, 7, 6, 1, 7, $light);
		$this->fillBox($c, $box, 0, 2, 0, 0, 2, 7, $black);
		$this->fillBox($c, $box, 7, 2, 0, 7, 2, 7, $black);
		$this->fillBox($c, $box, 1, 2, 0, 6, 2, 0, $black);
		$this->fillBox($c, $box, 1, 2, 7, 6, 2, 7, $black);
		$this->fillBox($c, $box, 0, 3, 0, 0, 3, 7, $light);
		$this->fillBox($c, $box, 7, 3, 0, 7, 3, 7, $light);
		$this->fillBox($c, $box, 1, 3, 0, 6, 3, 0, $light);
		$this->fillBox($c, $box, 1, 3, 7, 6, 3, 7, $light);
		$this->fillBox($c, $box, 0, 1, 3, 0, 2, 4, $black);
		$this->fillBox($c, $box, 7, 1, 3, 7, 2, 4, $black);
		$this->fillBox($c, $box, 3, 1, 0, 4, 2, 0, $black);
		$this->fillBox($c, $box, 3, 1, 7, 4, 2, 7, $black);
		if($room->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 3, 1, 0, 4, 2, 0);
		}
	}
}
