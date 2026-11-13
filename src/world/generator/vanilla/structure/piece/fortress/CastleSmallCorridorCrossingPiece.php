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
 * NetherFortressPieces.CastleSmallCorridorCrossingPiece: a crossing of small corridors.
 */
final class CastleSmallCorridorCrossingPiece extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -1, 0, 0, 5, 7, 5, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildForward($start, $builder, $random, 1, 0, true);
		$this->generateChildLeft($start, $builder, $random, 0, 1, true);
		$this->generateChildRight($start, $builder, $random, 0, 1, true);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 0, 0, 0, 4, 1, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 0, 4, 5, 4, $air, $air, false);
		$this->generateBox($c, $box, 0, 2, 0, 0, 5, 0, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 2, 0, 4, 5, 0, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 2, 4, 0, 5, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 4, 2, 4, 4, 5, 4, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 6, 0, 4, 6, 4, $bricks, $bricks, false);
		for($x = 0; $x <= 4; ++$x){
			for($z = 0; $z <= 4; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
			}
		}
	}
}
