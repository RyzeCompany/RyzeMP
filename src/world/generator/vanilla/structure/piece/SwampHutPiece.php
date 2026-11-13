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

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * The swamp hut (vanilla's SwampHutPiece).
 */
final class SwampHutPiece extends OrientedPiece{

	public function __construct(LegacyRandom $random, int $x, int $z){
		$direction = self::randomHorizontalDirection($random);
		parent::__construct(self::makeBoundingBox($x, 64, $z, $direction, 7, 7, 9));
		$this->setOrientation($direction);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$planks = $this->state($c, "minecraft:spruce_planks");
		$log = $this->state($c, "minecraft:oak_log");
		$fence = $this->state($c, "minecraft:oak_fence");
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 1, 1, 1, 5, 1, 7, $planks, $planks, false);
		$this->generateBox($c, $box, 1, 4, 2, 5, 4, 7, $planks, $planks, false);
		$this->generateBox($c, $box, 2, 1, 0, 4, 1, 0, $planks, $planks, false);
		$this->generateBox($c, $box, 2, 2, 2, 3, 3, 2, $planks, $planks, false);
		$this->generateBox($c, $box, 1, 2, 3, 1, 3, 6, $planks, $planks, false);
		$this->generateBox($c, $box, 5, 2, 3, 5, 3, 6, $planks, $planks, false);
		$this->generateBox($c, $box, 2, 2, 7, 4, 3, 7, $planks, $planks, false);
		$this->generateBox($c, $box, 1, 0, 2, 1, 3, 2, $log, $log, false);
		$this->generateBox($c, $box, 5, 0, 2, 5, 3, 2, $log, $log, false);
		$this->generateBox($c, $box, 1, 0, 7, 1, 3, 7, $log, $log, false);
		$this->generateBox($c, $box, 5, 0, 7, 5, 3, 7, $log, $log, false);
		$this->placeBlock($c, $fence, 2, 3, 2, $box);
		$this->placeBlock($c, $fence, 3, 3, 7, $box);
		$this->placeBlock($c, $air, 1, 3, 4, $box);
		$this->placeBlock($c, $air, 5, 3, 4, $box);
		$this->placeBlock($c, $air, 5, 3, 5, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:potted_red_mushroom"), 1, 3, 5, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:crafting_table"), 3, 2, 6, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:cauldron"), 4, 2, 6, $box);
		$this->placeBlock($c, $fence, 1, 2, 1, $box);
		$this->placeBlock($c, $fence, 5, 2, 1, $box);
		$north = $this->state($c, "minecraft:spruce_stairs[facing=north]");
		$east = $this->state($c, "minecraft:spruce_stairs[facing=east]");
		$west = $this->state($c, "minecraft:spruce_stairs[facing=west]");
		$south = $this->state($c, "minecraft:spruce_stairs[facing=south]");
		$this->generateBox($c, $box, 0, 4, 1, 6, 4, 1, $north, $north, false);
		$this->generateBox($c, $box, 0, 4, 2, 0, 4, 7, $east, $east, false);
		$this->generateBox($c, $box, 6, 4, 2, 6, 4, 7, $west, $west, false);
		$this->generateBox($c, $box, 0, 4, 8, 6, 4, 8, $south, $south, false);
		$this->placeBlock($c, $this->state($c, "minecraft:spruce_stairs[facing=north,shape=outer_right]"), 0, 4, 1, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:spruce_stairs[facing=north,shape=outer_left]"), 6, 4, 1, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:spruce_stairs[facing=south,shape=outer_left]"), 0, 4, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:spruce_stairs[facing=south,shape=outer_right]"), 6, 4, 8, $box);
		for($z = 2; $z <= 7; $z += 5){
			for($x = 1; $x <= 5; $x += 4){
				$this->fillColumnDown($c, $log, $x, -1, $z, $box);
			}
		}
		//the witch and the cat stand at (2, 2, 5); the box check spawns them once, in the chunk holding that block. The
		//cat is an all black adult (Bedrock spawn_midnight_cat), see Cat::setSpawnReason()
	}
}
