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
 * OceanMonumentPieces.OceanMonumentDoubleZRoom: two grid cells joined along z.
 */
final class OceanMonumentDoubleZRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 1, 2), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, , $lamp] = $this->palette($c);
		$south = $this->room();
		$north = $south->get(Direction::NORTH);
		if(intdiv($south->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 0, 8, $north->hasOpening[Direction::DOWN]);
			$this->generateDefaultFloor($c, $box, 0, 0, $south->hasOpening[Direction::DOWN]);
		}
		if($south->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 4, 1, 6, 4, 7, $gray);
		}
		if($north->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 4, 8, 6, 4, 14, $gray);
		}
		$this->fillBox($c, $box, 0, 3, 0, 0, 3, 15, $light);
		$this->fillBox($c, $box, 7, 3, 0, 7, 3, 15, $light);
		$this->fillBox($c, $box, 1, 3, 0, 7, 3, 0, $light);
		$this->fillBox($c, $box, 1, 3, 15, 6, 3, 15, $light);
		$this->fillBox($c, $box, 0, 2, 0, 0, 2, 15, $gray);
		$this->fillBox($c, $box, 7, 2, 0, 7, 2, 15, $gray);
		$this->fillBox($c, $box, 1, 2, 0, 7, 2, 0, $gray);
		$this->fillBox($c, $box, 1, 2, 15, 6, 2, 15, $gray);
		$this->fillBox($c, $box, 0, 1, 0, 0, 1, 15, $light);
		$this->fillBox($c, $box, 7, 1, 0, 7, 1, 15, $light);
		$this->fillBox($c, $box, 1, 1, 0, 7, 1, 0, $light);
		$this->fillBox($c, $box, 1, 1, 15, 6, 1, 15, $light);
		$this->fillBox($c, $box, 1, 1, 1, 1, 1, 2, $light);
		$this->fillBox($c, $box, 6, 1, 1, 6, 1, 2, $light);
		$this->fillBox($c, $box, 1, 3, 1, 1, 3, 2, $light);
		$this->fillBox($c, $box, 6, 3, 1, 6, 3, 2, $light);
		$this->fillBox($c, $box, 1, 1, 13, 1, 1, 14, $light);
		$this->fillBox($c, $box, 6, 1, 13, 6, 1, 14, $light);
		$this->fillBox($c, $box, 1, 3, 13, 1, 3, 14, $light);
		$this->fillBox($c, $box, 6, 3, 13, 6, 3, 14, $light);
		$this->fillBox($c, $box, 2, 1, 6, 2, 3, 6, $light);
		$this->fillBox($c, $box, 5, 1, 6, 5, 3, 6, $light);
		$this->fillBox($c, $box, 2, 1, 9, 2, 3, 9, $light);
		$this->fillBox($c, $box, 5, 1, 9, 5, 3, 9, $light);
		$this->fillBox($c, $box, 3, 2, 6, 4, 2, 6, $light);
		$this->fillBox($c, $box, 3, 2, 9, 4, 2, 9, $light);
		$this->fillBox($c, $box, 2, 2, 7, 2, 2, 8, $light);
		$this->fillBox($c, $box, 5, 2, 7, 5, 2, 8, $light);
		$this->placeBlock($c, $lamp, 2, 2, 5, $box);
		$this->placeBlock($c, $lamp, 5, 2, 5, $box);
		$this->placeBlock($c, $lamp, 2, 2, 10, $box);
		$this->placeBlock($c, $lamp, 5, 2, 10, $box);
		$this->placeBlock($c, $light, 2, 3, 5, $box);
		$this->placeBlock($c, $light, 5, 3, 5, $box);
		$this->placeBlock($c, $light, 2, 3, 10, $box);
		$this->placeBlock($c, $light, 5, 3, 10, $box);
		if($south->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 3, 1, 0, 4, 2, 0);
		}
		if($south->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 7, 1, 3, 7, 2, 4);
		}
		if($south->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 1, 3, 0, 2, 4);
		}
		if($north->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 3, 1, 15, 4, 2, 15);
		}
		if($north->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 1, 11, 0, 2, 12);
		}
		if($north->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 7, 1, 11, 7, 2, 12);
		}
	}
}
