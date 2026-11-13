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
 * OceanMonumentPieces.OceanMonumentCoreRoom: the 2x2x2 treasure room with the eight gold blocks.
 */
final class OceanMonumentCoreRoom extends OceanMonumentPiece{

	public function __construct(int $orientation, RoomDefinition $definition){
		parent::__construct($orientation, 1, self::makeRoomBox($orientation, $definition, 2, 2, 2), $definition);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		[$gray, $light, $black, $lamp] = $this->palette($c);
		$this->generateBoxOnFillOnly($c, $box, 1, 8, 0, 14, 8, 14, $gray);
		$block = $light;
		$this->fillBox($c, $box, 0, 7, 0, 0, 7, 15, $block);
		$this->fillBox($c, $box, 15, 7, 0, 15, 7, 15, $block);
		$this->fillBox($c, $box, 1, 7, 0, 15, 7, 0, $block);
		$this->fillBox($c, $box, 1, 7, 15, 14, 7, 15, $block);
		for($y = 1; $y <= 6; ++$y){
			$block = $y === 2 || $y === 6 ? $gray : $light;
			for($x = 0; $x <= 15; $x += 15){
				$this->fillBox($c, $box, $x, $y, 0, $x, $y, 1, $block);
				$this->fillBox($c, $box, $x, $y, 6, $x, $y, 9, $block);
				$this->fillBox($c, $box, $x, $y, 14, $x, $y, 15, $block);
			}
			$this->fillBox($c, $box, 1, $y, 0, 1, $y, 0, $block);
			$this->fillBox($c, $box, 6, $y, 0, 9, $y, 0, $block);
			$this->fillBox($c, $box, 14, $y, 0, 14, $y, 0, $block);
			$this->fillBox($c, $box, 1, $y, 15, 14, $y, 15, $block);
		}
		$this->fillBox($c, $box, 6, 3, 6, 9, 6, 9, $black);
		$this->fillBox($c, $box, 7, 4, 7, 8, 5, 8, $this->state($c, "minecraft:gold_block"));
		for($y = 3; $y <= 6; $y += 3){
			for($x = 6; $x <= 9; $x += 3){
				$this->placeBlock($c, $lamp, $x, $y, 6, $box);
				$this->placeBlock($c, $lamp, $x, $y, 9, $box);
			}
		}
		$this->fillBox($c, $box, 5, 1, 6, 5, 2, 6, $light);
		$this->fillBox($c, $box, 5, 1, 9, 5, 2, 9, $light);
		$this->fillBox($c, $box, 10, 1, 6, 10, 2, 6, $light);
		$this->fillBox($c, $box, 10, 1, 9, 10, 2, 9, $light);
		$this->fillBox($c, $box, 6, 1, 5, 6, 2, 5, $light);
		$this->fillBox($c, $box, 9, 1, 5, 9, 2, 5, $light);
		$this->fillBox($c, $box, 6, 1, 10, 6, 2, 10, $light);
		$this->fillBox($c, $box, 9, 1, 10, 9, 2, 10, $light);
		$this->fillBox($c, $box, 5, 2, 5, 5, 6, 5, $light);
		$this->fillBox($c, $box, 5, 2, 10, 5, 6, 10, $light);
		$this->fillBox($c, $box, 10, 2, 5, 10, 6, 5, $light);
		$this->fillBox($c, $box, 10, 2, 10, 10, 6, 10, $light);
		$this->fillBox($c, $box, 5, 7, 1, 5, 7, 6, $light);
		$this->fillBox($c, $box, 10, 7, 1, 10, 7, 6, $light);
		$this->fillBox($c, $box, 5, 7, 9, 5, 7, 14, $light);
		$this->fillBox($c, $box, 10, 7, 9, 10, 7, 14, $light);
		$this->fillBox($c, $box, 1, 7, 5, 6, 7, 5, $light);
		$this->fillBox($c, $box, 1, 7, 10, 6, 7, 10, $light);
		$this->fillBox($c, $box, 9, 7, 5, 14, 7, 5, $light);
		$this->fillBox($c, $box, 9, 7, 10, 14, 7, 10, $light);
		$this->fillBox($c, $box, 2, 1, 2, 2, 1, 3, $light);
		$this->fillBox($c, $box, 3, 1, 2, 3, 1, 2, $light);
		$this->fillBox($c, $box, 13, 1, 2, 13, 1, 3, $light);
		$this->fillBox($c, $box, 12, 1, 2, 12, 1, 2, $light);
		$this->fillBox($c, $box, 2, 1, 12, 2, 1, 13, $light);
		$this->fillBox($c, $box, 3, 1, 13, 3, 1, 13, $light);
		$this->fillBox($c, $box, 13, 1, 12, 13, 1, 13, $light);
		$this->fillBox($c, $box, 12, 1, 13, 12, 1, 13, $light);
	}
}
