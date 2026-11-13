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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * StrongholdPieces.RightTurn: a 5x5 room turning right.
 */
final class RightTurn extends StrongholdPiece{

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 5, 5, 5, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		if($this->orientation === Direction::NORTH || $this->orientation === Direction::EAST){
			$this->generateSmallDoorChildRight($start, $builder, $random, 1, 1);
		}else{
			$this->generateSmallDoorChildLeft($start, $builder, $random, 1, 1);
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 4, 4, 4, true, $c->random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 1, 1, 0);
		$caveAir = $c->blocks->caveAir;
		if($this->orientation === Direction::NORTH || $this->orientation === Direction::EAST){
			$this->fill($c, $box, 4, 1, 1, 4, 3, 3, $caveAir);
		}else{
			$this->fill($c, $box, 0, 1, 1, 0, 3, 3, $caveAir);
		}
	}
}
