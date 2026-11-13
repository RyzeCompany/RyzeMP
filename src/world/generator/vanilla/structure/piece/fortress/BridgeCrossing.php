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
 * NetherFortressPieces.BridgeCrossing: a crossing of two bridges.
 */
class BridgeCrossing extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -8, -3, 0, 19, 10, 19, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateChildForward($start, $builder, $random, 8, 3, false);
		$this->generateChildLeft($start, $builder, $random, 3, 8, false);
		$this->generateChildRight($start, $builder, $random, 3, 8, false);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 7, 3, 0, 11, 4, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 3, 7, 18, 4, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 8, 5, 0, 10, 7, 18, $air, $air, false);
		$this->generateBox($c, $box, 0, 5, 8, 18, 7, 10, $air, $air, false);
		$this->generateBox($c, $box, 7, 5, 0, 7, 5, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 7, 5, 11, 7, 5, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 11, 5, 0, 11, 5, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 11, 5, 11, 11, 5, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 5, 7, 7, 5, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 11, 5, 7, 18, 5, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 5, 11, 7, 5, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 11, 5, 11, 18, 5, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 7, 2, 0, 11, 2, 5, $bricks, $bricks, false);
		$this->generateBox($c, $box, 7, 2, 13, 11, 2, 18, $bricks, $bricks, false);
		$this->generateBox($c, $box, 7, 0, 0, 11, 1, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 7, 0, 15, 11, 1, 18, $bricks, $bricks, false);
		for($x = 7; $x <= 11; ++$x){
			for($z = 0; $z <= 2; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
				$this->fillColumnDown($c, $bricks, $x, -1, 18 - $z, $box);
			}
		}
		$this->generateBox($c, $box, 0, 2, 7, 5, 2, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 13, 2, 7, 18, 2, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 0, 7, 3, 1, 11, $bricks, $bricks, false);
		$this->generateBox($c, $box, 15, 0, 7, 18, 1, 11, $bricks, $bricks, false);
		for($x = 0; $x <= 2; ++$x){
			for($z = 7; $z <= 11; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
				$this->fillColumnDown($c, $bricks, 18 - $x, -1, $z, $box);
			}
		}
	}
}
