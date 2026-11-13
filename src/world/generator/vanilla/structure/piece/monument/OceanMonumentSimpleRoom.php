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
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function intdiv;

/**
 * OceanMonumentPieces.OceanMonumentSimpleRoom: one grid cell in one of three designs (chosen at start time), with a
 * random centre pillar.
 */
final class OceanMonumentSimpleRoom extends OceanMonumentPiece{
	private int $mainDesign;

	public function __construct(int $orientation, RoomDefinition $definition, LegacyRandom $random){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 1, 1), $definition);
		$this->mainDesign = $random->nextBoundedInt(3);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, $black, $lamp] = $this->palette($c);
		$room = $this->room();
		if(intdiv($room->index, 25) > 0){
			$this->generateDefaultFloor($c, $box, 0, 0, $room->hasOpening[Direction::DOWN]);
		}
		if($room->connections[Direction::UP] === null){
			$this->generateBoxOnFillOnly($c, $box, 1, 4, 1, 6, 4, 6, $gray);
		}
		$centerPillar = $this->mainDesign !== 0 && $c->random->nextBoolean() && !$room->hasOpening[Direction::DOWN] && !$room->hasOpening[Direction::UP] && $room->countOpenings() > 1;
		if($this->mainDesign === 0){
			$this->fillBox($c, $box, 0, 1, 0, 2, 1, 2, $light);
			$this->fillBox($c, $box, 0, 3, 0, 2, 3, 2, $light);
			$this->fillBox($c, $box, 0, 2, 0, 0, 2, 2, $gray);
			$this->fillBox($c, $box, 1, 2, 0, 2, 2, 0, $gray);
			$this->placeBlock($c, $lamp, 1, 2, 1, $box);
			$this->fillBox($c, $box, 5, 1, 0, 7, 1, 2, $light);
			$this->fillBox($c, $box, 5, 3, 0, 7, 3, 2, $light);
			$this->fillBox($c, $box, 7, 2, 0, 7, 2, 2, $gray);
			$this->fillBox($c, $box, 5, 2, 0, 6, 2, 0, $gray);
			$this->placeBlock($c, $lamp, 6, 2, 1, $box);
			$this->fillBox($c, $box, 0, 1, 5, 2, 1, 7, $light);
			$this->fillBox($c, $box, 0, 3, 5, 2, 3, 7, $light);
			$this->fillBox($c, $box, 0, 2, 5, 0, 2, 7, $gray);
			$this->fillBox($c, $box, 1, 2, 7, 2, 2, 7, $gray);
			$this->placeBlock($c, $lamp, 1, 2, 6, $box);
			$this->fillBox($c, $box, 5, 1, 5, 7, 1, 7, $light);
			$this->fillBox($c, $box, 5, 3, 5, 7, 3, 7, $light);
			$this->fillBox($c, $box, 7, 2, 5, 7, 2, 7, $gray);
			$this->fillBox($c, $box, 5, 2, 7, 6, 2, 7, $gray);
			$this->placeBlock($c, $lamp, 6, 2, 6, $box);
			if($room->hasOpening[Direction::SOUTH]){
				$this->fillBox($c, $box, 3, 3, 0, 4, 3, 0, $light);
			}else{
				$this->fillBox($c, $box, 3, 3, 0, 4, 3, 1, $light);
				$this->fillBox($c, $box, 3, 2, 0, 4, 2, 0, $gray);
				$this->fillBox($c, $box, 3, 1, 0, 4, 1, 1, $light);
			}
			if($room->hasOpening[Direction::NORTH]){
				$this->fillBox($c, $box, 3, 3, 7, 4, 3, 7, $light);
			}else{
				$this->fillBox($c, $box, 3, 3, 6, 4, 3, 7, $light);
				$this->fillBox($c, $box, 3, 2, 7, 4, 2, 7, $gray);
				$this->fillBox($c, $box, 3, 1, 6, 4, 1, 7, $light);
			}
			if($room->hasOpening[Direction::WEST]){
				$this->fillBox($c, $box, 0, 3, 3, 0, 3, 4, $light);
			}else{
				$this->fillBox($c, $box, 0, 3, 3, 1, 3, 4, $light);
				$this->fillBox($c, $box, 0, 2, 3, 0, 2, 4, $gray);
				$this->fillBox($c, $box, 0, 1, 3, 1, 1, 4, $light);
			}
			if($room->hasOpening[Direction::EAST]){
				$this->fillBox($c, $box, 7, 3, 3, 7, 3, 4, $light);
			}else{
				$this->fillBox($c, $box, 6, 3, 3, 7, 3, 4, $light);
				$this->fillBox($c, $box, 7, 2, 3, 7, 2, 4, $gray);
				$this->fillBox($c, $box, 6, 1, 3, 7, 1, 4, $light);
			}
		}elseif($this->mainDesign === 1){
			$this->fillBox($c, $box, 2, 1, 2, 2, 3, 2, $light);
			$this->fillBox($c, $box, 2, 1, 5, 2, 3, 5, $light);
			$this->fillBox($c, $box, 5, 1, 5, 5, 3, 5, $light);
			$this->fillBox($c, $box, 5, 1, 2, 5, 3, 2, $light);
			$this->placeBlock($c, $lamp, 2, 2, 2, $box);
			$this->placeBlock($c, $lamp, 2, 2, 5, $box);
			$this->placeBlock($c, $lamp, 5, 2, 5, $box);
			$this->placeBlock($c, $lamp, 5, 2, 2, $box);
			$this->fillBox($c, $box, 0, 1, 0, 1, 3, 0, $light);
			$this->fillBox($c, $box, 0, 1, 1, 0, 3, 1, $light);
			$this->fillBox($c, $box, 0, 1, 7, 1, 3, 7, $light);
			$this->fillBox($c, $box, 0, 1, 6, 0, 3, 6, $light);
			$this->fillBox($c, $box, 6, 1, 7, 7, 3, 7, $light);
			$this->fillBox($c, $box, 7, 1, 6, 7, 3, 6, $light);
			$this->fillBox($c, $box, 6, 1, 0, 7, 3, 0, $light);
			$this->fillBox($c, $box, 7, 1, 1, 7, 3, 1, $light);
			$this->placeBlock($c, $gray, 1, 2, 0, $box);
			$this->placeBlock($c, $gray, 0, 2, 1, $box);
			$this->placeBlock($c, $gray, 1, 2, 7, $box);
			$this->placeBlock($c, $gray, 0, 2, 6, $box);
			$this->placeBlock($c, $gray, 6, 2, 7, $box);
			$this->placeBlock($c, $gray, 7, 2, 6, $box);
			$this->placeBlock($c, $gray, 6, 2, 0, $box);
			$this->placeBlock($c, $gray, 7, 2, 1, $box);
			if(!$room->hasOpening[Direction::SOUTH]){
				$this->fillBox($c, $box, 1, 3, 0, 6, 3, 0, $light);
				$this->fillBox($c, $box, 1, 2, 0, 6, 2, 0, $gray);
				$this->fillBox($c, $box, 1, 1, 0, 6, 1, 0, $light);
			}
			if(!$room->hasOpening[Direction::NORTH]){
				$this->fillBox($c, $box, 1, 3, 7, 6, 3, 7, $light);
				$this->fillBox($c, $box, 1, 2, 7, 6, 2, 7, $gray);
				$this->fillBox($c, $box, 1, 1, 7, 6, 1, 7, $light);
			}
			if(!$room->hasOpening[Direction::WEST]){
				$this->fillBox($c, $box, 0, 3, 1, 0, 3, 6, $light);
				$this->fillBox($c, $box, 0, 2, 1, 0, 2, 6, $gray);
				$this->fillBox($c, $box, 0, 1, 1, 0, 1, 6, $light);
			}
			if(!$room->hasOpening[Direction::EAST]){
				$this->fillBox($c, $box, 7, 3, 1, 7, 3, 6, $light);
				$this->fillBox($c, $box, 7, 2, 1, 7, 2, 6, $gray);
				$this->fillBox($c, $box, 7, 1, 1, 7, 1, 6, $light);
			}
		}elseif($this->mainDesign === 2){
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
			if($room->hasOpening[Direction::NORTH]){
				$this->generateWaterBox($c, $box, 3, 1, 7, 4, 2, 7);
			}
			if($room->hasOpening[Direction::WEST]){
				$this->generateWaterBox($c, $box, 0, 1, 3, 0, 2, 4);
			}
			if($room->hasOpening[Direction::EAST]){
				$this->generateWaterBox($c, $box, 7, 1, 3, 7, 2, 4);
			}
		}
		if($centerPillar){
			$this->fillBox($c, $box, 3, 1, 3, 4, 1, 4, $light);
			$this->fillBox($c, $box, 3, 2, 3, 4, 2, 4, $gray);
			$this->fillBox($c, $box, 3, 3, 3, 4, 3, 4, $light);
		}
	}
}
