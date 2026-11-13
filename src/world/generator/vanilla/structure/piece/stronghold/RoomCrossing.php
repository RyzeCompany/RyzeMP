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

namespace pocketmine\world\generator\vanilla\structure\piece\stronghold;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * StrongholdPieces.RoomCrossing: a room with exits on three sides and one of three centrepieces (stone pillar with
 * torches, fountain, or a cobblestone pillar with a planks gallery and a chest).
 */
final class RoomCrossing extends StrongholdPiece{
	private readonly int $type;

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
		$this->type = $random->nextBoundedInt(5);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -4, -1, 0, 11, 7, 11, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateSmallDoorChildForward($start, $builder, $random, 4, 1);
		$this->generateSmallDoorChildLeft($start, $builder, $random, 1, 4);
		$this->generateSmallDoorChildRight($start, $builder, $random, 1, 4);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$caveAir = $c->blocks->caveAir;
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 10, 6, 10, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 4, 1, 0);
		$this->fill($c, $box, 4, 1, 10, 6, 3, 10, $caveAir);
		$this->fill($c, $box, 0, 1, 4, 0, 3, 6, $caveAir);
		$this->fill($c, $box, 10, 1, 4, 10, 3, 6, $caveAir);
		$bricks = $this->state($c, "minecraft:stone_bricks");
		switch($this->type){
			case 0:
				$slab = $this->state($c, "minecraft:smooth_stone_slab");
				$this->placeBlock($c, $bricks, 5, 1, 5, $box);
				$this->placeBlock($c, $bricks, 5, 2, 5, $box);
				$this->placeBlock($c, $bricks, 5, 3, 5, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:wall_torch[facing=west]"), 4, 3, 5, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:wall_torch[facing=east]"), 6, 3, 5, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:wall_torch[facing=south]"), 5, 3, 4, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:wall_torch[facing=north]"), 5, 3, 6, $box);
				$this->placeBlock($c, $slab, 4, 1, 4, $box);
				$this->placeBlock($c, $slab, 4, 1, 5, $box);
				$this->placeBlock($c, $slab, 4, 1, 6, $box);
				$this->placeBlock($c, $slab, 6, 1, 4, $box);
				$this->placeBlock($c, $slab, 6, 1, 5, $box);
				$this->placeBlock($c, $slab, 6, 1, 6, $box);
				$this->placeBlock($c, $slab, 5, 1, 4, $box);
				$this->placeBlock($c, $slab, 5, 1, 6, $box);
				break;
			case 1:
				for($i = 0; $i < 5; ++$i){
					$this->placeBlock($c, $bricks, 3, 1, 3 + $i, $box);
					$this->placeBlock($c, $bricks, 7, 1, 3 + $i, $box);
					$this->placeBlock($c, $bricks, 3 + $i, 1, 3, $box);
					$this->placeBlock($c, $bricks, 3 + $i, 1, 7, $box);
				}
				$this->placeBlock($c, $bricks, 5, 1, 5, $box);
				$this->placeBlock($c, $bricks, 5, 2, 5, $box);
				$this->placeBlock($c, $bricks, 5, 3, 5, $box);
				$this->placeBlock($c, $this->state($c, "minecraft:water"), 5, 4, 5, $box);
				break;
			case 2:
				$cobblestone = $this->state($c, "minecraft:cobblestone");
				$planks = $this->state($c, "minecraft:oak_planks");
				for($z = 1; $z <= 9; ++$z){
					$this->placeBlock($c, $cobblestone, 1, 3, $z, $box);
					$this->placeBlock($c, $cobblestone, 9, 3, $z, $box);
				}
				for($x = 1; $x <= 9; ++$x){
					$this->placeBlock($c, $cobblestone, $x, 3, 1, $box);
					$this->placeBlock($c, $cobblestone, $x, 3, 9, $box);
				}
				$this->placeBlock($c, $cobblestone, 5, 1, 4, $box);
				$this->placeBlock($c, $cobblestone, 5, 1, 6, $box);
				$this->placeBlock($c, $cobblestone, 5, 3, 4, $box);
				$this->placeBlock($c, $cobblestone, 5, 3, 6, $box);
				$this->placeBlock($c, $cobblestone, 4, 1, 5, $box);
				$this->placeBlock($c, $cobblestone, 6, 1, 5, $box);
				$this->placeBlock($c, $cobblestone, 4, 3, 5, $box);
				$this->placeBlock($c, $cobblestone, 6, 3, 5, $box);
				for($y = 1; $y <= 3; ++$y){
					$this->placeBlock($c, $cobblestone, 4, $y, 4, $box);
					$this->placeBlock($c, $cobblestone, 6, $y, 4, $box);
					$this->placeBlock($c, $cobblestone, 4, $y, 6, $box);
					$this->placeBlock($c, $cobblestone, 6, $y, 6, $box);
				}
				$this->placeBlock($c, $this->state($c, "minecraft:wall_torch"), 5, 3, 5, $box);
				for($z = 2; $z <= 8; ++$z){
					$this->placeBlock($c, $planks, 2, 3, $z, $box);
					$this->placeBlock($c, $planks, 3, 3, $z, $box);
					if($z <= 3 || $z >= 7){
						$this->placeBlock($c, $planks, 4, 3, $z, $box);
						$this->placeBlock($c, $planks, 5, 3, $z, $box);
						$this->placeBlock($c, $planks, 6, 3, $z, $box);
					}
					$this->placeBlock($c, $planks, 7, 3, $z, $box);
					$this->placeBlock($c, $planks, 8, 3, $z, $box);
				}
				$ladder = $this->state($c, "minecraft:ladder[facing=west]");
				$this->placeBlock($c, $ladder, 9, 1, 3, $box);
				$this->placeBlock($c, $ladder, 9, 2, 3, $box);
				$this->placeBlock($c, $ladder, 9, 3, 3, $box);
				$this->createChest($c, $box, $random, 3, 4, 8, "minecraft:chests/stronghold_crossing");
				break;
		}
	}
}
