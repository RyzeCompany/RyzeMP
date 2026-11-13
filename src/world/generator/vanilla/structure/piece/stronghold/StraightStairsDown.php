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
 * StrongholdPieces.StraightStairsDown: a straight cobblestone staircase going down 6 blocks.
 */
final class StraightStairsDown extends StrongholdPiece{

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -7, 0, 5, 11, 8, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateSmallDoorChildForward($start, $builder, $random, 1, 1);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 4, 10, 7, true, $c->random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 1, 7, 0);
		$this->generateSmallDoor($c, $box, self::DOOR_OPENING, 1, 1, 7);
		$stairs = $this->state($c, "minecraft:cobblestone_stairs[facing=south]");
		$bricks = $this->state($c, "minecraft:stone_bricks");
		for($i = 0; $i < 6; ++$i){
			$this->placeBlock($c, $stairs, 1, 6 - $i, 1 + $i, $box);
			$this->placeBlock($c, $stairs, 2, 6 - $i, 1 + $i, $box);
			$this->placeBlock($c, $stairs, 3, 6 - $i, 1 + $i, $box);
			if($i < 5){
				$this->placeBlock($c, $bricks, 1, 5 - $i, 1 + $i, $box);
				$this->placeBlock($c, $bricks, 2, 5 - $i, 1 + $i, $box);
				$this->placeBlock($c, $bricks, 3, 5 - $i, 1 + $i, $box);
			}
		}
	}
}
