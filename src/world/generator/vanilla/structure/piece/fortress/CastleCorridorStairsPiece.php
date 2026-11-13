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
use function max;
use function min;

/**
 * NetherFortressPieces.CastleCorridorStairsPiece: a corridor going down 7 blocks.
 */
final class CastleCorridorStairsPiece extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -1, -7, 0, 5, 14, 10, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildForward($start, $builder, $random, 1, 0, true);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$stairs = $this->state($c, "minecraft:nether_brick_stairs[facing=south]");
		$nsFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true]");
		for($step = 0; $step <= 9; ++$step){
			$floor = max(1, 7 - $step);
			$roof = min(max($floor + 5, 14 - $step), 13);
			$z = $step;
			$this->generateBox($c, $box, 0, 0, $z, 4, $floor, $z, $bricks, $bricks, false);
			$this->generateBox($c, $box, 1, $floor + 1, $z, 3, $roof - 1, $z, $air, $air, false);
			if($step <= 6){
				$this->placeBlock($c, $stairs, 1, $floor + 1, $z, $box);
				$this->placeBlock($c, $stairs, 2, $floor + 1, $z, $box);
				$this->placeBlock($c, $stairs, 3, $floor + 1, $z, $box);
			}
			$this->generateBox($c, $box, 0, $roof, $z, 4, $roof, $z, $bricks, $bricks, false);
			$this->generateBox($c, $box, 0, $floor + 1, $z, 0, $roof - 1, $z, $bricks, $bricks, false);
			$this->generateBox($c, $box, 4, $floor + 1, $z, 4, $roof - 1, $z, $bricks, $bricks, false);
			if(($step & 1) === 0){
				$this->generateBox($c, $box, 0, $floor + 2, $z, 0, $floor + 3, $z, $nsFence, $nsFence, false);
				$this->generateBox($c, $box, 4, $floor + 2, $z, 4, $floor + 3, $z, $nsFence, $nsFence, false);
			}
			for($x = 0; $x <= 4; ++$x){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
			}
		}
	}
}
