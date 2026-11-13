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
 * OceanMonumentPieces.OceanMonumentDoubleXRoom: two grid cells joined along x.
 */
final class OceanMonumentDoubleXRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 2, 1, 1), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, , $lamp] = $this->palette($c);
		$west = $this->room();
		$east = $west->get(Direction::EAST);
		if(intdiv($west->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 8, 0, $east->hasOpening[Direction::DOWN]);
			$this->generateDefaultFloor($c, $box, 0, 0, $west->hasOpening[Direction::DOWN]);
		}
		if($west->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 4, 1, 7, 4, 6, $gray);
		}
		if($east->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 8, 4, 1, 14, 4, 6, $gray);
		}
		$this->fillBox($c, $box, 0, 3, 0, 0, 3, 7, $light);
		$this->fillBox($c, $box, 15, 3, 0, 15, 3, 7, $light);
		$this->fillBox($c, $box, 1, 3, 0, 15, 3, 0, $light);
		$this->fillBox($c, $box, 1, 3, 7, 14, 3, 7, $light);
		$this->fillBox($c, $box, 0, 2, 0, 0, 2, 7, $gray);
		$this->fillBox($c, $box, 15, 2, 0, 15, 2, 7, $gray);
		$this->fillBox($c, $box, 1, 2, 0, 15, 2, 0, $gray);
		$this->fillBox($c, $box, 1, 2, 7, 14, 2, 7, $gray);
		$this->fillBox($c, $box, 0, 1, 0, 0, 1, 7, $light);
		$this->fillBox($c, $box, 15, 1, 0, 15, 1, 7, $light);
		$this->fillBox($c, $box, 1, 1, 0, 15, 1, 0, $light);
		$this->fillBox($c, $box, 1, 1, 7, 14, 1, 7, $light);
		$this->fillBox($c, $box, 5, 1, 0, 10, 1, 4, $light);
		$this->fillBox($c, $box, 6, 2, 0, 9, 2, 3, $gray);
		$this->fillBox($c, $box, 5, 3, 0, 10, 3, 4, $light);
		$this->placeBlock($c, $lamp, 6, 2, 3, $box);
		$this->placeBlock($c, $lamp, 9, 2, 3, $box);
		if($west->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 3, 1, 0, 4, 2, 0);
		}
		if($west->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 3, 1, 7, 4, 2, 7);
		}
		if($west->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 1, 3, 0, 2, 4);
		}
		if($east->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 11, 1, 0, 12, 2, 0);
		}
		if($east->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 11, 1, 7, 12, 2, 7);
		}
		if($east->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 15, 1, 3, 15, 2, 4);
		}
	}
}
