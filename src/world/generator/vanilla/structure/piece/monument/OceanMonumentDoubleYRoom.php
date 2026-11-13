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
 * OceanMonumentPieces.OceanMonumentDoubleYRoom: two grid cells stacked along y.
 */
final class OceanMonumentDoubleYRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 2, 1), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light] = $this->palette($c);
		$room = $this->room();
		if(intdiv($room->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 0, 0, $room->hasOpening[Direction::DOWN]);
		}
		$above = $room->get(Direction::UP);
		if($above->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 8, 1, 6, 8, 6, $gray);
		}
		$this->fillBox($c, $box, 0, 4, 0, 0, 4, 7, $light);
		$this->fillBox($c, $box, 7, 4, 0, 7, 4, 7, $light);
		$this->fillBox($c, $box, 1, 4, 0, 6, 4, 0, $light);
		$this->fillBox($c, $box, 1, 4, 7, 6, 4, 7, $light);
		$this->fillBox($c, $box, 2, 4, 1, 2, 4, 2, $light);
		$this->fillBox($c, $box, 1, 4, 2, 1, 4, 2, $light);
		$this->fillBox($c, $box, 5, 4, 1, 5, 4, 2, $light);
		$this->fillBox($c, $box, 6, 4, 2, 6, 4, 2, $light);
		$this->fillBox($c, $box, 2, 4, 5, 2, 4, 6, $light);
		$this->fillBox($c, $box, 1, 4, 5, 1, 4, 5, $light);
		$this->fillBox($c, $box, 5, 4, 5, 5, 4, 6, $light);
		$this->fillBox($c, $box, 6, 4, 5, 6, 4, 5, $light);
		$definition = $room;
		for($y = 1; $y <= 5; $y += 4){
			$z = 0;
			if($definition->hasOpening[Direction::SOUTH]){
				$this->fillBox($c, $box, 2, $y, $z, 2, $y + 2, $z, $light);
				$this->fillBox($c, $box, 5, $y, $z, 5, $y + 2, $z, $light);
				$this->fillBox($c, $box, 3, $y + 2, $z, 4, $y + 2, $z, $light);
			}else{
				$this->fillBox($c, $box, 0, $y, $z, 7, $y + 2, $z, $light);
				$this->fillBox($c, $box, 0, $y + 1, $z, 7, $y + 1, $z, $gray);
			}
			$z = 7;
			if($definition->hasOpening[Direction::NORTH]){
				$this->fillBox($c, $box, 2, $y, $z, 2, $y + 2, $z, $light);
				$this->fillBox($c, $box, 5, $y, $z, 5, $y + 2, $z, $light);
				$this->fillBox($c, $box, 3, $y + 2, $z, 4, $y + 2, $z, $light);
			}else{
				$this->fillBox($c, $box, 0, $y, $z, 7, $y + 2, $z, $light);
				$this->fillBox($c, $box, 0, $y + 1, $z, 7, $y + 1, $z, $gray);
			}
			$x = 0;
			if($definition->hasOpening[Direction::WEST]){
				$this->fillBox($c, $box, $x, $y, 2, $x, $y + 2, 2, $light);
				$this->fillBox($c, $box, $x, $y, 5, $x, $y + 2, 5, $light);
				$this->fillBox($c, $box, $x, $y + 2, 3, $x, $y + 2, 4, $light);
			}else{
				$this->fillBox($c, $box, $x, $y, 0, $x, $y + 2, 7, $light);
				$this->fillBox($c, $box, $x, $y + 1, 0, $x, $y + 1, 7, $gray);
			}
			$x = 7;
			if($definition->hasOpening[Direction::EAST]){
				$this->fillBox($c, $box, $x, $y, 2, $x, $y + 2, 2, $light);
				$this->fillBox($c, $box, $x, $y, 5, $x, $y + 2, 5, $light);
				$this->fillBox($c, $box, $x, $y + 2, 3, $x, $y + 2, 4, $light);
			}else{
				$this->fillBox($c, $box, $x, $y, 0, $x, $y + 2, 7, $light);
				$this->fillBox($c, $box, $x, $y + 1, 0, $x, $y + 1, 7, $gray);
			}
			$definition = $above;
		}
	}
}
