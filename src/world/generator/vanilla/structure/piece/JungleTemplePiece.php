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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * The jungle temple (vanilla's JungleTemplePiece): mossy cobblestone temple with a lever puzzle, two arrow traps and
 * two loot chests. Checked against vanilla's bytecode.
 */
final class JungleTemplePiece extends OrientedPiece{
	private const WIDTH = 12;
	private const DEPTH = 15;

	public function __construct(LegacyRandom $random, int $x, int $z){
		$direction = self::randomHorizontalDirection($random);
		parent::__construct(self::makeBoundingBox($x, 64, $z, $direction, self::WIDTH, 10, self::DEPTH));
		$this->setOrientation($direction);
	}

	/**
	 * generateBox() with the mossy stone selector (40 % cobblestone, else mossy cobblestone).
	 */
	private function stoneBox(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : void{
		$cobble = $this->state($c, "minecraft:cobblestone");
		$mossy = $this->state($c, "minecraft:mossy_cobblestone");
		for($y = $minY; $y <= $maxY; ++$y){
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					$this->placeBlock($c, $random->nextFloat() < 0.4 ? $cobble : $mossy, $x, $y, $z, $box);
				}
			}
		}
	}

	private function airBox(StructurePlaceContext $c, BoundingBox $box, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : void{
		$air = $c->blocks->air;
		$this->generateBox($c, $box, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $air, $air, false);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$air = $c->blocks->air;
		$mossy = $this->state($c, "minecraft:mossy_cobblestone");
		$this->stoneBox($c, $box, $random, 0, -4, 0, self::WIDTH - 1, 0, self::DEPTH - 1);
		$this->stoneBox($c, $box, $random, 2, 1, 2, 9, 2, 2);
		$this->stoneBox($c, $box, $random, 2, 1, 12, 9, 2, 12);
		$this->stoneBox($c, $box, $random, 2, 1, 3, 2, 2, 11);
		$this->stoneBox($c, $box, $random, 9, 1, 3, 9, 2, 11);
		$this->stoneBox($c, $box, $random, 1, 3, 1, 10, 6, 1);
		$this->stoneBox($c, $box, $random, 1, 3, 13, 10, 6, 13);
		$this->stoneBox($c, $box, $random, 1, 3, 2, 1, 6, 12);
		$this->stoneBox($c, $box, $random, 10, 3, 2, 10, 6, 12);
		$this->stoneBox($c, $box, $random, 2, 3, 2, 9, 3, 12);
		$this->stoneBox($c, $box, $random, 2, 6, 2, 9, 6, 12);
		$this->stoneBox($c, $box, $random, 3, 7, 3, 8, 7, 11);
		$this->stoneBox($c, $box, $random, 4, 8, 4, 7, 8, 10);
		$this->airBox($c, $box, 3, 1, 3, 8, 2, 11);
		$this->airBox($c, $box, 4, 3, 6, 7, 3, 9);
		$this->airBox($c, $box, 2, 4, 2, 9, 5, 12);
		$this->airBox($c, $box, 4, 6, 5, 7, 6, 9);
		$this->airBox($c, $box, 5, 7, 6, 6, 7, 8);
		$this->airBox($c, $box, 5, 1, 2, 6, 2, 2);
		$this->airBox($c, $box, 5, 2, 12, 6, 2, 12);
		$this->airBox($c, $box, 5, 5, 1, 6, 5, 1);
		$this->airBox($c, $box, 5, 5, 13, 6, 5, 13);
		$this->placeBlock($c, $air, 1, 5, 5, $box);
		$this->placeBlock($c, $air, 10, 5, 5, $box);
		$this->placeBlock($c, $air, 1, 5, 9, $box);
		$this->placeBlock($c, $air, 10, 5, 9, $box);
		for($z = 0; $z <= 14; $z += 14){
			$this->stoneBox($c, $box, $random, 2, 4, $z, 2, 5, $z);
			$this->stoneBox($c, $box, $random, 4, 4, $z, 4, 5, $z);
			$this->stoneBox($c, $box, $random, 7, 4, $z, 7, 5, $z);
			$this->stoneBox($c, $box, $random, 9, 4, $z, 9, 5, $z);
		}
		$this->stoneBox($c, $box, $random, 5, 6, 0, 6, 6, 0);
		for($x = 0; $x <= 11; $x += 11){
			for($z = 2; $z <= 12; $z += 2){
				$this->stoneBox($c, $box, $random, $x, 4, $z, $x, 5, $z);
			}
			$this->stoneBox($c, $box, $random, $x, 6, 5, $x, 6, 5);
			$this->stoneBox($c, $box, $random, $x, 6, 9, $x, 6, 9);
		}
		$this->stoneBox($c, $box, $random, 2, 7, 2, 2, 9, 2);
		$this->stoneBox($c, $box, $random, 9, 7, 2, 9, 9, 2);
		$this->stoneBox($c, $box, $random, 2, 7, 12, 2, 9, 12);
		$this->stoneBox($c, $box, $random, 9, 7, 12, 9, 9, 12);
		$this->stoneBox($c, $box, $random, 4, 9, 4, 4, 9, 4);
		$this->stoneBox($c, $box, $random, 7, 9, 4, 7, 9, 4);
		$this->stoneBox($c, $box, $random, 4, 9, 10, 4, 9, 10);
		$this->stoneBox($c, $box, $random, 7, 9, 10, 7, 9, 10);
		$this->stoneBox($c, $box, $random, 5, 9, 7, 6, 9, 7);
		$east = $this->state($c, "minecraft:cobblestone_stairs[facing=east]");
		$west = $this->state($c, "minecraft:cobblestone_stairs[facing=west]");
		$south = $this->state($c, "minecraft:cobblestone_stairs[facing=south]");
		$north = $this->state($c, "minecraft:cobblestone_stairs[facing=north]");
		$this->placeBlock($c, $north, 5, 9, 6, $box);
		$this->placeBlock($c, $north, 6, 9, 6, $box);
		$this->placeBlock($c, $south, 5, 9, 8, $box);
		$this->placeBlock($c, $south, 6, 9, 8, $box);
		$this->placeBlock($c, $north, 4, 0, 0, $box);
		$this->placeBlock($c, $north, 5, 0, 0, $box);
		$this->placeBlock($c, $north, 6, 0, 0, $box);
		$this->placeBlock($c, $north, 7, 0, 0, $box);
		$this->placeBlock($c, $north, 4, 1, 8, $box);
		$this->placeBlock($c, $north, 4, 2, 9, $box);
		$this->placeBlock($c, $north, 4, 3, 10, $box);
		$this->placeBlock($c, $north, 7, 1, 8, $box);
		$this->placeBlock($c, $north, 7, 2, 9, $box);
		$this->placeBlock($c, $north, 7, 3, 10, $box);
		$this->stoneBox($c, $box, $random, 4, 1, 9, 4, 1, 9);
		$this->stoneBox($c, $box, $random, 7, 1, 9, 7, 1, 9);
		$this->stoneBox($c, $box, $random, 4, 1, 10, 7, 2, 10);
		$this->stoneBox($c, $box, $random, 5, 4, 5, 6, 4, 5);
		$this->placeBlock($c, $east, 4, 4, 5, $box);
		$this->placeBlock($c, $west, 7, 4, 5, $box);
		for($i = 0; $i < 4; ++$i){
			$this->placeBlock($c, $south, 5, -$i, 6 + $i, $box);
			$this->placeBlock($c, $south, 6, -$i, 6 + $i, $box);
			$this->airBox($c, $box, 5, -$i, 7 + $i, 6, -$i, 9 + $i);
		}
		$this->airBox($c, $box, 1, -3, 12, 10, -1, 13);
		$this->airBox($c, $box, 1, -3, 1, 3, -1, 13);
		$this->airBox($c, $box, 1, -3, 1, 9, -1, 5);
		for($z = 1; $z <= 13; $z += 2){
			$this->stoneBox($c, $box, $random, 1, -3, $z, 1, -2, $z);
		}
		for($z = 2; $z <= 12; $z += 2){
			$this->stoneBox($c, $box, $random, 1, -1, $z, 3, -1, $z);
		}
		$this->stoneBox($c, $box, $random, 2, -2, 1, 5, -2, 1);
		$this->stoneBox($c, $box, $random, 7, -2, 1, 9, -2, 1);
		$this->stoneBox($c, $box, $random, 6, -3, 1, 6, -3, 1);
		$this->stoneBox($c, $box, $random, 6, -1, 1, 6, -1, 1);
		//first trap: tripwire across the corridor, dispenser behind the wall
		$this->placeBlock($c, $this->state($c, "minecraft:tripwire_hook[facing=east,attached=true]"), 1, -3, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:tripwire_hook[facing=west,attached=true]"), 4, -3, 8, $box);
		$wireX = $this->state($c, "minecraft:tripwire[east=true,west=true,attached=true]");
		$this->placeBlock($c, $wireX, 2, -3, 8, $box);
		$this->placeBlock($c, $wireX, 3, -3, 8, $box);
		$redstoneNS = $this->state($c, "minecraft:redstone_wire[north=side,south=side]");
		for($z = 7; $z >= 2; --$z){
			$this->placeBlock($c, $redstoneNS, 5, -3, $z, $box);
		}
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[north=side,west=side]"), 5, -3, 1, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[east=side,west=side]"), 4, -3, 1, $box);
		$this->placeBlock($c, $mossy, 3, -3, 1, $box);
		$this->createDispenser($c, $box, $random, 3, -2, 1, Direction::NORTH, "minecraft:chests/jungle_temple_dispenser");
		$this->placeBlock($c, $this->state($c, "minecraft:vine[south=true]"), 3, -2, 2, $box);
		//second trap
		$this->placeBlock($c, $this->state($c, "minecraft:tripwire_hook[facing=north,attached=true]"), 7, -3, 1, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:tripwire_hook[facing=south,attached=true]"), 7, -3, 5, $box);
		$wireZ = $this->state($c, "minecraft:tripwire[north=true,south=true,attached=true]");
		$this->placeBlock($c, $wireZ, 7, -3, 2, $box);
		$this->placeBlock($c, $wireZ, 7, -3, 3, $box);
		$this->placeBlock($c, $wireZ, 7, -3, 4, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[east=side,west=side]"), 8, -3, 6, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[west=side,south=side]"), 9, -3, 6, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[north=side,south=up]"), 9, -3, 5, $box);
		$this->placeBlock($c, $mossy, 9, -3, 4, $box);
		$this->placeBlock($c, $redstoneNS, 9, -2, 4, $box);
		$this->createDispenser($c, $box, $random, 9, -2, 3, Direction::WEST, "minecraft:chests/jungle_temple_dispenser");
		$vineEast = $this->state($c, "minecraft:vine[east=true]");
		$this->placeBlock($c, $vineEast, 8, -1, 3, $box);
		$this->placeBlock($c, $vineEast, 8, -2, 3, $box);
		$this->createChest($c, $box, $random, 8, -3, 3, "minecraft:chests/jungle_temple");
		$this->placeBlock($c, $mossy, 9, -3, 2, $box);
		$this->placeBlock($c, $mossy, 8, -3, 1, $box);
		$this->placeBlock($c, $mossy, 4, -3, 5, $box);
		$this->placeBlock($c, $mossy, 5, -2, 5, $box);
		$this->placeBlock($c, $mossy, 5, -1, 5, $box);
		$this->placeBlock($c, $mossy, 6, -3, 5, $box);
		$this->placeBlock($c, $mossy, 7, -2, 5, $box);
		$this->placeBlock($c, $mossy, 7, -1, 5, $box);
		$this->placeBlock($c, $mossy, 8, -3, 5, $box);
		$this->stoneBox($c, $box, $random, 9, -1, 1, 9, -1, 5);
		//lever puzzle and hidden chest
		$this->airBox($c, $box, 8, -3, 8, 10, -1, 10);
		$chiseled = $this->state($c, "minecraft:chiseled_stone_bricks");
		$this->placeBlock($c, $chiseled, 8, -2, 11, $box);
		$this->placeBlock($c, $chiseled, 9, -2, 11, $box);
		$this->placeBlock($c, $chiseled, 10, -2, 11, $box);
		$lever = $this->state($c, "minecraft:lever[facing=north,face=wall]");
		$this->placeBlock($c, $lever, 8, -2, 12, $box);
		$this->placeBlock($c, $lever, 9, -2, 12, $box);
		$this->placeBlock($c, $lever, 10, -2, 12, $box);
		$this->stoneBox($c, $box, $random, 8, -3, 8, 8, -3, 10);
		$this->stoneBox($c, $box, $random, 10, -3, 8, 10, -3, 10);
		$this->placeBlock($c, $mossy, 10, -2, 9, $box);
		$this->placeBlock($c, $redstoneNS, 8, -2, 9, $box);
		$this->placeBlock($c, $redstoneNS, 8, -2, 10, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:redstone_wire[north=side,south=side,east=side,west=side]"), 10, -1, 9, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:sticky_piston[facing=up]"), 9, -2, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:sticky_piston[facing=west]"), 10, -2, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:sticky_piston[facing=west]"), 10, -1, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:repeater[facing=north]"), 10, -2, 10, $box);
		$this->createChest($c, $box, $random, 9, -3, 10, "minecraft:chests/jungle_temple");
	}
}
