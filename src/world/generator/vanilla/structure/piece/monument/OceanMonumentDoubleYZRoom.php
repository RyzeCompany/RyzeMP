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
 * OceanMonumentPieces.OceanMonumentDoubleYZRoom: four grid cells joined along y and z.
 */
final class OceanMonumentDoubleYZRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 2, 2), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, $black, $lamp] = $this->palette($c);
		$south = $this->room();
		$north = $south->get(Direction::NORTH);
		$northUp = $north->get(Direction::UP);
		$southUp = $south->get(Direction::UP);
		if(intdiv($south->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 0, 8, $north->hasOpening[Direction::DOWN]);
			$this->generateDefaultFloor($c, $box, 0, 0, $south->hasOpening[Direction::DOWN]);
		}
		if($southUp->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 8, 1, 6, 8, 7, $gray);
		}
		if($northUp->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 8, 8, 6, 8, 14, $gray);
		}
		for($y = 1; $y <= 7; ++$y){
			$block = $y === 2 || $y === 6 ? $gray : $light;
			$this->fillBox($c, $box, 0, $y, 0, 0, $y, 15, $block);
			$this->fillBox($c, $box, 7, $y, 0, 7, $y, 15, $block);
			$this->fillBox($c, $box, 1, $y, 0, 6, $y, 0, $block);
			$this->fillBox($c, $box, 1, $y, 15, 6, $y, 15, $block);
		}
		for($y = 1; $y <= 7; ++$y){
			$block = $y === 2 || $y === 6 ? $lamp : $black;
			$this->fillBox($c, $box, 3, $y, 7, 4, $y, 8, $block);
		}
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
		if($southUp->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 3, 5, 0, 4, 6, 0);
		}
		if($southUp->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 7, 5, 3, 7, 6, 4);
			$this->fillBox($c, $box, 5, 4, 2, 6, 4, 5, $light);
			$this->fillBox($c, $box, 6, 1, 2, 6, 3, 2, $light);
			$this->fillBox($c, $box, 6, 1, 5, 6, 3, 5, $light);
		}
		if($southUp->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 5, 3, 0, 6, 4);
			$this->fillBox($c, $box, 1, 4, 2, 2, 4, 5, $light);
			$this->fillBox($c, $box, 1, 1, 2, 1, 3, 2, $light);
			$this->fillBox($c, $box, 1, 1, 5, 1, 3, 5, $light);
		}
		if($northUp->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 3, 5, 15, 4, 6, 15);
		}
		if($northUp->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 5, 11, 0, 6, 12);
			$this->fillBox($c, $box, 1, 4, 10, 2, 4, 13, $light);
			$this->fillBox($c, $box, 1, 1, 10, 1, 3, 10, $light);
			$this->fillBox($c, $box, 1, 1, 13, 1, 3, 13, $light);
		}
		if($northUp->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 7, 5, 11, 7, 6, 12);
			$this->fillBox($c, $box, 5, 4, 10, 6, 4, 13, $light);
			$this->fillBox($c, $box, 6, 1, 10, 6, 3, 10, $light);
			$this->fillBox($c, $box, 6, 1, 13, 6, 3, 13, $light);
		}
	}
}
