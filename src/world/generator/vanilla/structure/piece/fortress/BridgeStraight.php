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
 * NetherFortressPieces.BridgeStraight: a straight bridge on two pillars.
 */
final class BridgeStraight extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -1, -3, 0, 5, 10, 19, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildForward($start, $builder, $random, 1, 3, false);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 0, 3, 0, 4, 4, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 5, 0, 3, 7, 18, $air, $air, false);
		$this->generateBox($c, $box, 0, 5, 0, 0, 5, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 5, 0, 4, 5, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 0, 4, 2, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 13, 4, 2, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 0, 0, 4, 1, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 0, 15, 4, 1, 18, $bricks, $bricks, false);
		for($x = 0; $x <= 4; ++$x){
			for($z = 0; $z <= 2; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
				$this->fillColumnDown($c, $bricks, $x, -1, 18 - $z, $box);
			}
		}
		$nseFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true,east=true]");
		$nswFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true,west=true]");
		$this->generateBox($c, $box, 0, 1, 1, 0, 4, 1, $nseFence, $nseFence, false);
		$this->generateBox($c, $box, 0, 3, 4, 0, 4, 4, $nseFence, $nseFence, false);
		$this->generateBox($c, $box, 0, 3, 14, 0, 4, 14, $nseFence, $nseFence, false);
		$this->generateBox($c, $box, 0, 1, 17, 0, 4, 17, $nseFence, $nseFence, false);
		$this->generateBox($c, $box, 4, 1, 1, 4, 4, 1, $nswFence, $nswFence, false);
		$this->generateBox($c, $box, 4, 3, 4, 4, 4, 4, $nswFence, $nswFence, false);
		$this->generateBox($c, $box, 4, 3, 14, 4, 4, 14, $nswFence, $nswFence, false);
		$this->generateBox($c, $box, 4, 1, 17, 4, 4, 17, $nswFence, $nswFence, false);
	}
}
