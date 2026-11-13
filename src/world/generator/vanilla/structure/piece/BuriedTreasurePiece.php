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

namespace pocketmine\world\generator\vanilla\structure\piece;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * vanilla's BuriedTreasurePieces.BuriedTreasurePiece: goes down from the ocean floor to the first sandstone or stone
 * and buries a chest there, surrounded by sand.
 */
final class BuriedTreasurePiece extends StructurePiece{

	public function __construct(int $x, int $y, int $z){
		parent::__construct(new BoundingBox($x, $y, $z, $x, $y, $z));
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		$level = $c->level;
		$blocks = $c->blocks;
		$x = $this->box->minX;
		$z = $this->box->minZ;
		$y = $c->env->terrain->getFirstFreeHeight($x, $z, TerrainSampler::OCEAN_FLOOR_WG);
		$targets = [];
		foreach(["sandstone", "stone", "andesite", "granite", "diorite"] as $name){
			$targets[$blocks->defaultOf("minecraft:" . $name)] = true;
		}
		$sand = $blocks->defaultOf("minecraft:sand");
		$isLiquid = fn(int $state) : bool => $state === $blocks->water || $state === $blocks->lava;
		while($y > $level->minY){
			$state = $level->getState($x, $y, $z);
			$below = $level->getState($x, $y - 1, $z);
			if(isset($targets[$below])){
				$cover = !$blocks->isAir($state) && !$isLiquid($state) ? $state : $sand;
				foreach([Direction::DOWN, Direction::UP, Direction::NORTH, Direction::SOUTH, Direction::WEST, Direction::EAST] as $direction){
					$nx = $x + Direction::STEP_X[$direction];
					$ny = $y + Direction::STEP_Y[$direction];
					$nz = $z + Direction::STEP_Z[$direction];
					$neighbour = $level->getState($nx, $ny, $nz);
					if($blocks->isAir($neighbour) || $isLiquid($neighbour)){
						$neighbourBelow = $level->getState($nx, $ny - 1, $nz);
						if(($blocks->isAir($neighbourBelow) || $isLiquid($neighbourBelow)) && $direction !== Direction::UP){
							$level->setState($nx, $ny, $nz, $below);
						}else{
							$level->setState($nx, $ny, $nz, $cover);
						}
					}
				}
				OrientedPiece::createChestAt($c, $chunkBox, $c->random, $x, $y, $z, "minecraft:chests/buried_treasure");
				return;
			}
			--$y;
		}
	}
}
