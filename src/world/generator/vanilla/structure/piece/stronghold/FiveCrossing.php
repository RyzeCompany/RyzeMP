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
 * StrongholdPieces.FiveCrossing: a two-level hall with up to five exits.
 */
final class FiveCrossing extends StrongholdPiece{
	private readonly bool $leftLow;
	private readonly bool $leftHigh;
	private readonly bool $rightLow;
	private readonly bool $rightHigh;

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
		$this->leftLow = $random->nextBoolean();
		$this->leftHigh = $random->nextBoolean();
		$this->rightLow = $random->nextBoolean();
		$this->rightHigh = $random->nextBoundedInt(3) > 0;
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -4, -3, 0, 10, 9, 11, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$offA = 3;
		$offB = 5;
		if($this->orientation === Direction::WEST || $this->orientation === Direction::NORTH){
			$offA = 8 - $offA;
			$offB = 8 - $offB;
		}
		$this->generateSmallDoorChildForward($start, $builder, $random, 5, 1);
		if($this->leftLow){
			$this->generateSmallDoorChildLeft($start, $builder, $random, $offA, 1);
		}
		if($this->leftHigh){
			$this->generateSmallDoorChildLeft($start, $builder, $random, $offB, 7);
		}
		if($this->rightLow){
			$this->generateSmallDoorChildRight($start, $builder, $random, $offA, 1);
		}
		if($this->rightHigh){
			$this->generateSmallDoorChildRight($start, $builder, $random, $offB, 7);
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$caveAir = $c->blocks->caveAir;
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 9, 8, 10, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 4, 3, 0);
		if($this->leftLow){
			$this->fill($c, $box, 0, 3, 1, 0, 5, 3, $caveAir);
		}
		if($this->rightLow){
			$this->fill($c, $box, 9, 3, 1, 9, 5, 3, $caveAir);
		}
		if($this->leftHigh){
			$this->fill($c, $box, 0, 5, 7, 0, 7, 9, $caveAir);
		}
		if($this->rightHigh){
			$this->fill($c, $box, 9, 5, 7, 9, 7, 9, $caveAir);
		}
		$this->fill($c, $box, 5, 1, 10, 7, 3, 10, $caveAir);
		$this->generateSmoothStoneBox($c, $box, 1, 2, 1, 8, 2, 6, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 1, 5, 4, 4, 9, false, $random);
		$this->generateSmoothStoneBox($c, $box, 8, 1, 5, 8, 4, 9, false, $random);
		$this->generateSmoothStoneBox($c, $box, 1, 4, 7, 3, 4, 9, false, $random);
		$this->generateSmoothStoneBox($c, $box, 1, 3, 5, 3, 3, 6, false, $random);
		$slab = $this->state($c, "minecraft:smooth_stone_slab");
		$this->fill($c, $box, 1, 3, 4, 3, 3, 4, $slab);
		$this->fill($c, $box, 1, 4, 6, 3, 4, 6, $slab);
		$this->generateSmoothStoneBox($c, $box, 5, 1, 7, 7, 1, 8, false, $random);
		$this->fill($c, $box, 5, 1, 9, 7, 1, 9, $slab);
		$this->fill($c, $box, 5, 2, 7, 7, 2, 7, $slab);
		$this->fill($c, $box, 4, 5, 7, 4, 5, 9, $slab);
		$this->fill($c, $box, 8, 5, 7, 8, 5, 9, $slab);
		$this->fill($c, $box, 5, 5, 7, 7, 5, 9, $this->state($c, "minecraft:smooth_stone_slab[type=double]"));
		$this->placeBlock($c, $this->state($c, "minecraft:wall_torch[facing=south]"), 6, 5, 6, $box);
	}
}
