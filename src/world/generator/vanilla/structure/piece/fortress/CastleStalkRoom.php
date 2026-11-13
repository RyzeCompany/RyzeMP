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

namespace pocketmine\world\generator\vanilla\structure\piece\fortress;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * NetherFortressPieces.CastleStalkRoom: the hall with the nether wart gardens and a staircase.
 */
final class CastleStalkRoom extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -5, -3, 0, 13, 14, 13, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildForward($start, $builder, $random, 5, 3, true);
		$this->generateChildForward($start, $builder, $random, 5, 11, true);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 0, 3, 0, 12, 4, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 5, 0, 12, 13, 12, $air, $air, false);
		$this->generateBox($c, $box, 0, 5, 0, 1, 12, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 11, 5, 0, 12, 12, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 2, 5, 11, 4, 12, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 8, 5, 11, 10, 12, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 5, 9, 11, 7, 12, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 2, 5, 0, 4, 12, 1, $bricks, $bricks, false);
		$this->generateBox($c, $box, 8, 5, 0, 10, 12, 1, $bricks, $bricks, false);
		$this->generateBox($c, $box, 5, 9, 0, 7, 12, 1, $bricks, $bricks, false);
		$this->generateBox($c, $box, 2, 11, 2, 10, 12, 10, $bricks, $bricks, false);
		$weFence = $this->state($c, "minecraft:nether_brick_fence[west=true,east=true]");
		$nsFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true]");
		$nswFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true,west=true]");
		$nseFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true,east=true]");
		for($i = 1; $i <= 11; $i += 2){
			$this->generateBox($c, $box, $i, 10, 0, $i, 11, 0, $weFence, $weFence, false);
			$this->generateBox($c, $box, $i, 10, 12, $i, 11, 12, $weFence, $weFence, false);
			$this->generateBox($c, $box, 0, 10, $i, 0, 11, $i, $nsFence, $nsFence, false);
			$this->generateBox($c, $box, 12, 10, $i, 12, 11, $i, $nsFence, $nsFence, false);
			$this->placeBlock($c, $bricks, $i, 13, 0, $box);
			$this->placeBlock($c, $bricks, $i, 13, 12, $box);
			$this->placeBlock($c, $bricks, 0, 13, $i, $box);
			$this->placeBlock($c, $bricks, 12, 13, $i, $box);
			if($i !== 11){
				$this->placeBlock($c, $weFence, $i + 1, 13, 0, $box);
				$this->placeBlock($c, $weFence, $i + 1, 13, 12, $box);
				$this->placeBlock($c, $nsFence, 0, 13, $i + 1, $box);
				$this->placeBlock($c, $nsFence, 12, 13, $i + 1, $box);
			}
		}
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[north=true,east=true]"), 0, 13, 0, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[south=true,east=true]"), 0, 13, 12, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[south=true,west=true]"), 12, 13, 12, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[north=true,west=true]"), 12, 13, 0, $box);
		for($z = 3; $z <= 9; $z += 2){
			$this->generateBox($c, $box, 1, 7, $z, 1, 8, $z, $nswFence, $nswFence, false);
			$this->generateBox($c, $box, 11, 7, $z, 11, 8, $z, $nseFence, $nseFence, false);
		}
		$stairs = $this->state($c, "minecraft:nether_brick_stairs[facing=north]");
		for($i = 0; $i <= 6; ++$i){
			$z = $i + 4;
			for($x = 5; $x <= 7; ++$x){
				$this->placeBlock($c, $stairs, $x, 5 + $i, $z, $box);
			}
			if($z >= 5 && $z <= 8){
				$this->generateBox($c, $box, 5, 5, $z, 7, $i + 4, $z, $bricks, $bricks, false);
			}elseif($z >= 9){ //vanilla: z >= 9 && z <= 10 (z is at most 10)
				$this->generateBox($c, $box, 5, 8, $z, 7, $i + 4, $z, $bricks, $bricks, false);
			}
			if($i >= 1){
				$this->generateBox($c, $box, 5, 6 + $i, $z, 7, 9 + $i, $z, $air, $air, false);
			}
		}
		for($x = 5; $x <= 7; ++$x){
			$this->placeBlock($c, $stairs, $x, 12, 11, $box);
		}
		$this->generateBox($c, $box, 5, 6, 7, 5, 7, 7, $nseFence, $nseFence, false);
		$this->generateBox($c, $box, 7, 6, 7, 7, 7, 7, $nswFence, $nswFence, false);
		$this->generateBox($c, $box, 5, 13, 12, 7, 13, 12, $air, $air, false);
		$this->generateBox($c, $box, 2, 5, 2, 3, 5, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 2, 5, 9, 3, 5, 10, $bricks, $bricks, false);
		$this->generateBox($c, $box, 2, 5, 4, 2, 5, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 9, 5, 2, 10, 5, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 9, 5, 9, 10, 5, 10, $bricks, $bricks, false);
		$this->generateBox($c, $box, 10, 5, 4, 10, 5, 8, $bricks, $bricks, false);
		$eastStairs = $this->state($c, "minecraft:nether_brick_stairs[facing=east]");
		$westStairs = $this->state($c, "minecraft:nether_brick_stairs[facing=west]");
		$this->placeBlock($c, $westStairs, 4, 5, 2, $box);
		$this->placeBlock($c, $westStairs, 4, 5, 3, $box);
		$this->placeBlock($c, $westStairs, 4, 5, 9, $box);
		$this->placeBlock($c, $westStairs, 4, 5, 10, $box);
		$this->placeBlock($c, $eastStairs, 8, 5, 2, $box);
		$this->placeBlock($c, $eastStairs, 8, 5, 3, $box);
		$this->placeBlock($c, $eastStairs, 8, 5, 9, $box);
		$this->placeBlock($c, $eastStairs, 8, 5, 10, $box);
		$soulSand = $this->state($c, "minecraft:soul_sand");
		$wart = $this->state($c, "minecraft:nether_wart");
		$this->generateBox($c, $box, 3, 4, 4, 4, 4, 8, $soulSand, $soulSand, false);
		$this->generateBox($c, $box, 8, 4, 4, 9, 4, 8, $soulSand, $soulSand, false);
		$this->generateBox($c, $box, 3, 5, 4, 4, 5, 8, $wart, $wart, false);
		$this->generateBox($c, $box, 8, 5, 4, 9, 5, 8, $wart, $wart, false);
		$this->generateBox($c, $box, 4, 2, 0, 8, 2, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 4, 12, 2, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 0, 0, 8, 1, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 0, 9, 8, 1, 12, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 0, 4, 3, 1, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 9, 0, 4, 12, 1, 8, $bricks, $bricks, false);
		for($x = 4; $x <= 8; ++$x){
			for($z = 0; $z <= 2; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
				$this->fillColumnDown($c, $bricks, $x, -1, 12 - $z, $box);
			}
		}
		for($x = 0; $x <= 2; ++$x){
			for($z = 4; $z <= 8; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
				$this->fillColumnDown($c, $bricks, 12 - $x, -1, $z, $box);
			}
		}
	}
}
