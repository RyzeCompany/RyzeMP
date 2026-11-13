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

/**
 * OceanMonumentPieces.OceanMonumentEntryRoom: the source room behind the entrance.
 */
final class OceanMonumentEntryRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 1, 1, 1), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$light = $this->state($c, "minecraft:prismarine_bricks");
		$room = $this->room();
		$this->fillBox($c, $box, 0, 3, 0, 2, 3, 7, $light);
		$this->fillBox($c, $box, 5, 3, 0, 7, 3, 7, $light);
		$this->fillBox($c, $box, 0, 2, 0, 1, 2, 7, $light);
		$this->fillBox($c, $box, 6, 2, 0, 7, 2, 7, $light);
		$this->fillBox($c, $box, 0, 1, 0, 0, 1, 7, $light);
		$this->fillBox($c, $box, 7, 1, 0, 7, 1, 7, $light);
		$this->fillBox($c, $box, 0, 1, 7, 7, 3, 7, $light);
		$this->fillBox($c, $box, 1, 1, 0, 2, 3, 0, $light);
		$this->fillBox($c, $box, 5, 1, 0, 6, 3, 0, $light);
		if($room->hasOpening[Direction::NORTH]){
			$this->generateWaterBox($c, $box, 3, 1, 7, 4, 2, 7);
		}
		if($room->hasOpening[Direction::WEST]){
			$this->generateWaterBox($c, $box, 0, 1, 3, 1, 2, 4);
		}
		if($room->hasOpening[Direction::EAST]){
			$this->generateWaterBox($c, $box, 6, 1, 3, 7, 2, 4);
		}
	}
}
