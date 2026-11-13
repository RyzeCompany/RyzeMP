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
 * StrongholdPieces.Library: bookshelves, cobwebs and chests; the tall variant has a gallery reached by a ladder and a
 * fence chandelier with torches.
 */
final class Library extends StrongholdPiece{
	private readonly bool $isTall;

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
		$this->isTall = $box->getYSpan() > 6;
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -4, -1, 0, 14, 11, 15, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			$box = BoundingBox::orientBox($x, $y, $z, -4, -1, 0, 14, 6, 15, $direction);
			if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
				return null;
			}
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$height = $this->isTall ? 11 : 6;
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 13, $height - 1, 14, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 4, 1, 0);
		$cobweb = $this->state($c, "minecraft:cobweb");
		$this->generateMaybeBox($c, $box, $random, 0.07000000029802322, 2, 1, 1, 11, 4, 13, $cobweb, $cobweb, false, false);
		$planks = $this->state($c, "minecraft:oak_planks");
		$bookshelf = $this->state($c, "minecraft:bookshelf");
		$eastTorch = $this->state($c, "minecraft:wall_torch[facing=east]");
		$westTorch = $this->state($c, "minecraft:wall_torch[facing=west]");
		for($d = 1; $d <= 13; ++$d){
			if(($d - 1) % 4 === 0){
				$this->fill($c, $box, 1, 1, $d, 1, 4, $d, $planks);
				$this->fill($c, $box, 12, 1, $d, 12, 4, $d, $planks);
				$this->placeBlock($c, $eastTorch, 2, 3, $d, $box);
				$this->placeBlock($c, $westTorch, 11, 3, $d, $box);
				if($this->isTall){
					$this->fill($c, $box, 1, 6, $d, 1, 9, $d, $planks);
					$this->fill($c, $box, 12, 6, $d, 12, 9, $d, $planks);
				}
			}else{
				$this->fill($c, $box, 1, 1, $d, 1, 4, $d, $bookshelf);
				$this->fill($c, $box, 12, 1, $d, 12, 4, $d, $bookshelf);
				if($this->isTall){
					$this->fill($c, $box, 1, 6, $d, 1, 9, $d, $bookshelf);
					$this->fill($c, $box, 12, 6, $d, 12, 9, $d, $bookshelf);
				}
			}
		}
		for($d = 3; $d < 12; $d += 2){
			$this->fill($c, $box, 3, 1, $d, 4, 3, $d, $bookshelf);
			$this->fill($c, $box, 6, 1, $d, 7, 3, $d, $bookshelf);
			$this->fill($c, $box, 9, 1, $d, 10, 3, $d, $bookshelf);
		}
		if($this->isTall){
			$this->fill($c, $box, 1, 5, 1, 3, 5, 13, $planks);
			$this->fill($c, $box, 10, 5, 1, 12, 5, 13, $planks);
			$this->fill($c, $box, 4, 5, 1, 9, 5, 2, $planks);
			$this->fill($c, $box, 4, 5, 12, 9, 5, 13, $planks);
			$this->placeBlock($c, $planks, 9, 5, 11, $box);
			$this->placeBlock($c, $planks, 8, 5, 11, $box);
			$this->placeBlock($c, $planks, 9, 5, 10, $box);
			$weFence = $this->state($c, "minecraft:oak_fence[west=true,east=true]");
			$nsFence = $this->state($c, "minecraft:oak_fence[north=true,south=true]");
			$this->fill($c, $box, 3, 6, 3, 3, 6, 11, $nsFence);
			$this->fill($c, $box, 10, 6, 3, 10, 6, 9, $nsFence);
			$this->fill($c, $box, 4, 6, 2, 9, 6, 2, $weFence);
			$this->fill($c, $box, 4, 6, 12, 7, 6, 12, $weFence);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[north=true,east=true]"), 3, 6, 2, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[south=true,east=true]"), 3, 6, 12, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[north=true,west=true]"), 10, 6, 2, $box);
			$southWest = $this->state($c, "minecraft:oak_fence[south=true,west=true]");
			$northEast = $this->state($c, "minecraft:oak_fence[north=true,east=true]");
			for($i = 0; $i <= 2; ++$i){
				$this->placeBlock($c, $southWest, 8 + $i, 6, 12 - $i, $box);
				if($i !== 2){
					$this->placeBlock($c, $northEast, 8 + $i, 6, 11 - $i, $box);
				}
			}
			$ladder = $this->state($c, "minecraft:ladder[facing=south]");
			for($y = 1; $y <= 7; ++$y){
				$this->placeBlock($c, $ladder, 10, $y, 13, $box);
			}
			//chandelier
			$eFence = $this->state($c, "minecraft:oak_fence[east=true]");
			$wFence = $this->state($c, "minecraft:oak_fence[west=true]");
			$this->placeBlock($c, $eFence, 6, 9, 7, $box);
			$this->placeBlock($c, $wFence, 7, 9, 7, $box);
			$this->placeBlock($c, $eFence, 6, 8, 7, $box);
			$this->placeBlock($c, $wFence, 7, 8, 7, $box);
			$nsweFence = $this->state($c, "minecraft:oak_fence[north=true,south=true,west=true,east=true]");
			$this->placeBlock($c, $nsweFence, 6, 7, 7, $box);
			$this->placeBlock($c, $nsweFence, 7, 7, 7, $box);
			$this->placeBlock($c, $eFence, 5, 7, 7, $box);
			$this->placeBlock($c, $wFence, 8, 7, 7, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[east=true,north=true]"), 6, 7, 6, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[east=true,south=true]"), 6, 7, 8, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[west=true,north=true]"), 7, 7, 6, $box);
			$this->placeBlock($c, $this->state($c, "minecraft:oak_fence[west=true,south=true]"), 7, 7, 8, $box);
			$torch = $this->state($c, "minecraft:torch");
			$this->placeBlock($c, $torch, 5, 8, 7, $box);
			$this->placeBlock($c, $torch, 8, 8, 7, $box);
			$this->placeBlock($c, $torch, 6, 8, 6, $box);
			$this->placeBlock($c, $torch, 6, 8, 8, $box);
			$this->placeBlock($c, $torch, 7, 8, 6, $box);
			$this->placeBlock($c, $torch, 7, 8, 8, $box);
		}
		$this->createChest($c, $box, $random, 3, 3, 5, "minecraft:chests/stronghold_library");
		if($this->isTall){
			$this->placeBlock($c, $c->blocks->caveAir, 12, 9, 1, $box);
			$this->createChest($c, $box, $random, 12, 8, 1, "minecraft:chests/stronghold_library");
		}
	}
}
