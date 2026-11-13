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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * NetherFortressPieces.CastleCorridorTBalconyPiece: a T crossing of corridors with a balcony.
 */
final class CastleCorridorTBalconyPiece extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -3, 0, 0, 9, 7, 9, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$zOff = 1;
		$orientation = $this->orientation;
		if($orientation === Direction::WEST || $orientation === Direction::NORTH){
			$zOff = 5;
		}
		$this->generateChildLeft($start, $builder, $random, 0, $zOff, $random->nextBoundedInt(8) > 0);
		$this->generateChildRight($start, $builder, $random, 0, $zOff, $random->nextBoundedInt(8) > 0);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$nsFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true]");
		$weFence = $this->state($c, "minecraft:nether_brick_fence[west=true,east=true]");
		$this->generateBox($c, $box, 0, 0, 0, 8, 1, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 0, 8, 5, 8, $air, $air, false);
		$this->generateBox($c, $box, 0, 6, 0, 8, 6, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 0, 2, 5, 0, $bricks, $bricks, false);
		$this->generateBox($c, $box, 6, 2, 0, 8, 5, 0, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 3, 0, 1, 4, 0, $weFence, $weFence, false);
		$this->generateBox($c, $box, 7, 3, 0, 7, 4, 0, $weFence, $weFence, false);
		$this->generateBox($c, $box, 0, 2, 4, 8, 2, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 1, 4, 2, 2, 4, $air, $air, false);
		$this->generateBox($c, $box, 6, 1, 4, 7, 2, 4, $air, $air, false);
		$this->generateBox($c, $box, 1, 3, 8, 7, 3, 8, $weFence, $weFence, false);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[east=true,south=true]"), 0, 3, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[west=true,south=true]"), 8, 3, 8, $box);
		$this->generateBox($c, $box, 0, 3, 6, 0, 3, 7, $nsFence, $nsFence, false);
		$this->generateBox($c, $box, 8, 3, 6, 8, 3, 7, $nsFence, $nsFence, false);
		$this->generateBox($c, $box, 0, 3, 4, 0, 5, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 8, 3, 4, 8, 5, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 3, 5, 2, 5, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 6, 3, 5, 7, 5, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 4, 5, 1, 5, 5, $weFence, $weFence, false);
		$this->generateBox($c, $box, 7, 4, 5, 7, 5, 5, $weFence, $weFence, false);
		for($z = 0; $z <= 5; ++$z){
			for($x = 0; $x <= 8; ++$x){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
			}
		}
	}
}
