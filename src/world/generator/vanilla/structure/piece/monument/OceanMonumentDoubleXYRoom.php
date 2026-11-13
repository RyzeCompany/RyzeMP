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
 * OceanMonumentPieces.OceanMonumentDoubleXYRoom: four grid cells joined along x and y.
 */
final class OceanMonumentDoubleXYRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 2, 2, 1), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, , $lamp] = $this->palette($c);
		$west = $this->room();
		$east = $west->get(Direction::EAST);
		$westUp = $west->get(Direction::UP);
		$eastUp = $east->get(Direction::UP);
		if(intdiv($west->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 8, 0, $east->hasOpening[Direction::DOWN]);
			$this->generateDefaultFloor($c, $box, 0, 0, $west->hasOpening[Direction::DOWN]);
		}
		if($westUp->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 8, 1, 7, 8, 6, $gray);
		}
		if($eastUp->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 8, 8, 1, 14, 8, 6, $gray);
		}
		for($y = 1; $y <= 7; ++$y){
			$block = $y === 2 || $y === 6 ? $gray : $light;
			$this->fillBox($c, $box, 0, $y, 0, 0, $y, 7, $block);
			$this->fillBox($c, $box, 15, $y, 0, 15, $y, 7, $block);
			$this->fillBox($c, $box, 1, $y, 0, 15, $y, 0, $block);
			$this->fillBox($c, $box, 1, $y, 7, 14, $y, 7, $block);
		}
		$this->fillBox($c, $box, 2, 1, 3, 2, 7, 4, $light);
		$this->fillBox($c, $box, 3, 1, 2, 4, 7, 2, $light);
		$this->fillBox($c, $box, 3, 1, 5, 4, 7, 5, $light);
		$this->fillBox($c, $box, 13, 1, 3, 13, 7, 4, $light);
		$this->fillBox($c, $box, 11, 1, 2, 12, 7, 2, $light);
		$this->fillBox($c, $box, 11, 1, 5, 12, 7, 5, $light);
		$this->fillBox($c, $box, 5, 1, 3, 5, 3, 4, $light);
		$this->fillBox($c, $box, 10, 1, 3, 10, 3, 4, $light);
		$this->fillBox($c, $box, 5, 7, 2, 10, 7, 5, $light);
		$this->fillBox($c, $box, 5, 5, 2, 5, 7, 2, $light);
		$this->fillBox($c, $box, 10, 5, 2, 10, 7, 2, $light);
		$this->fillBox($c, $box, 5, 5, 5, 5, 7, 5, $light);
		$this->fillBox($c, $box, 10, 5, 5, 10, 7, 5, $light);
		$this->placeBlock($c, $light, 6, 6, 2, $box);
		$this->placeBlock($c, $light, 9, 6, 2, $box);
		$this->placeBlock($c, $light, 6, 6, 5, $box);
		$this->placeBlock($c, $light, 9, 6, 5, $box);
		$this->fillBox($c, $box, 5, 4, 3, 6, 4, 4, $light);
		$this->fillBox($c, $box, 9, 4, 3, 10, 4, 4, $light);
		$this->placeBlock($c, $lamp, 5, 4, 2, $box);
		$this->placeBlock($c, $lamp, 5, 4, 5, $box);
		$this->placeBlock($c, $lamp, 10, 4, 2, $box);
		$this->placeBlock($c, $lamp, 10, 4, 5, $box);
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
		if($westUp->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 3, 5, 0, 4, 6, 0);
		}
		if($westUp->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 3, 5, 7, 4, 6, 7);
		}
		if($westUp->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 5, 3, 0, 6, 4);
		}
		if($eastUp->hasOpening[Direction::SOUTH]){
			$this->generateWaterBox($c, $box, 11, 5, 0, 12, 6, 0);
		}
		if($eastUp->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 11, 5, 7, 12, 6, 7);
		}
		if($eastUp->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 15, 5, 3, 15, 6, 4);
		}
	}
}
