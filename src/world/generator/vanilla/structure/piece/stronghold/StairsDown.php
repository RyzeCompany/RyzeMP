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
 * StrongholdPieces.StairsDown: a spiral staircase going down 6 blocks (also the start piece).
 */
class StairsDown extends StrongholdPiece{

	/**
	 * @param bool $isSource whether this is the start piece (its child is always a five-way crossing)
	 */
	protected function __construct(int $genDepth, BoundingBox $box, int $direction, protected readonly bool $isSource){
		parent::__construct($genDepth, $box, $direction);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -7, 0, 5, 11, 5, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		$piece = new self($genDepth, $box, $direction, false);
		$piece->entryDoor = self::randomSmallDoor($random);
		return $piece;
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		if($this->isSource){
			$start->generation->imposedPiece = FiveCrossing::class;
		}
		$this->generateSmallDoorChildForward($start, $builder, $random, 1, 1);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$bricks = $this->state($c, "minecraft:stone_bricks");
		$slab = $this->state($c, "minecraft:smooth_stone_slab");
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 4, 10, 4, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 1, 7, 0);
		$this->generateSmallDoor($c, $box, self::DOOR_OPENING, 1, 1, 4);
		$this->placeBlock($c, $bricks, 2, 6, 1, $box);
		$this->placeBlock($c, $bricks, 1, 5, 1, $box);
		$this->placeBlock($c, $slab, 1, 6, 1, $box);
		$this->placeBlock($c, $bricks, 1, 5, 2, $box);
		$this->placeBlock($c, $bricks, 1, 4, 3, $box);
		$this->placeBlock($c, $slab, 1, 5, 3, $box);
		$this->placeBlock($c, $bricks, 2, 4, 3, $box);
		$this->placeBlock($c, $bricks, 3, 3, 3, $box);
		$this->placeBlock($c, $slab, 3, 4, 3, $box);
		$this->placeBlock($c, $bricks, 3, 3, 2, $box);
		$this->placeBlock($c, $bricks, 3, 2, 1, $box);
		$this->placeBlock($c, $slab, 3, 3, 1, $box);
		$this->placeBlock($c, $bricks, 2, 2, 1, $box);
		$this->placeBlock($c, $bricks, 1, 1, 1, $box);
		$this->placeBlock($c, $slab, 1, 2, 1, $box);
		$this->placeBlock($c, $bricks, 1, 1, 2, $box);
		$this->placeBlock($c, $slab, 1, 1, 3, $box);
	}
}
