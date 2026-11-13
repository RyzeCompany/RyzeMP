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
 * NetherFortressPieces.CastleSmallCorridorLeftTurnPiece: a small corridor turning left, sometimes with a chest.
 */
final class CastleSmallCorridorLeftTurnPiece extends NetherBridgePiece{
	private bool $isNeedingChest;

	public function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->isNeedingChest = $random->nextBoundedInt(3) === 0;
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -1, 0, 0, 5, 7, 5, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildLeft($start, $builder, $random, 0, 1, true);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 0, 0, 0, 4, 1, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 0, 4, 5, 4, $air, $air, false);
		$weFence = $this->state($c, "minecraft:nether_brick_fence[west=true,east=true]");
		$nsFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true]");
		$this->generateBox($c, $box, 4, 2, 0, 4, 5, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 3, 1, 4, 4, 1, $nsFence, $nsFence, false);
		$this->generateBox($c, $box, 4, 3, 3, 4, 4, 3, $nsFence, $nsFence, false);
		$this->generateBox($c, $box, 0, 2, 0, 0, 5, 0, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 4, 3, 5, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 3, 4, 1, 4, 4, $weFence, $weFence, false);
		$this->generateBox($c, $box, 3, 3, 4, 3, 4, 4, $weFence, $weFence, false);
		//vanilla clears isNeedingChest here; the position is inside a single chunk, so the box check alone places
		//the chest once (and keeps the piece immutable when several workers share or recompute the start)
		[$cx, $cy, $cz] = $this->getWorldPos(3, 2, 3);
		if($this->isNeedingChest && $box->isInside($cx, $cy, $cz)){
			$this->createChest($c, $box, $c->random, 3, 2, 3, "minecraft:chests/nether_bridge");
		}
		$this->generateBox($c, $box, 0, 6, 0, 4, 6, 4, $bricks, $bricks, false);
		for($x = 0; $x <= 4; ++$x){
			for($z = 0; $z <= 4; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
			}
		}
	}
}
