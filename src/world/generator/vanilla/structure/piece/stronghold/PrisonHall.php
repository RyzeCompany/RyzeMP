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
 * StrongholdPieces.PrisonHall: a corridor along two iron-barred cells with iron doors.
 */
final class PrisonHall extends StrongholdPiece{

	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 9, 5, 11, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$this->generateSmallDoorChildForward($start, $builder, $random, 1, 1);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 8, 4, 10, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 1, 1, 0);
		$this->fill($c, $box, 1, 1, 10, 3, 3, 10, $c->blocks->caveAir);
		$this->generateSmoothStoneBox($c, $box, 4, 1, 1, 4, 3, 1, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 1, 3, 4, 3, 3, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 1, 7, 4, 3, 7, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 1, 9, 4, 3, 9, false, $random);
		$northSouth = $this->state($c, "minecraft:iron_bars[north=true,south=true]");
		$northSouthEast = $this->state($c, "minecraft:iron_bars[north=true,south=true,east=true]");
		$westEast = $this->state($c, "minecraft:iron_bars[west=true,east=true]");
		for($y = 1; $y <= 3; ++$y){
			$this->placeBlock($c, $northSouth, 4, $y, 4, $box);
			$this->placeBlock($c, $northSouthEast, 4, $y, 5, $box);
			$this->placeBlock($c, $northSouth, 4, $y, 6, $box);
			$this->placeBlock($c, $westEast, 5, $y, 5, $box);
			$this->placeBlock($c, $westEast, 6, $y, 5, $box);
			$this->placeBlock($c, $westEast, 7, $y, 5, $box);
		}
		$this->placeBlock($c, $northSouth, 4, 3, 2, $box);
		$this->placeBlock($c, $northSouth, 4, 3, 8, $box);
		$doorBottom = $this->state($c, "minecraft:iron_door[facing=west]");
		$doorTop = $this->state($c, "minecraft:iron_door[facing=west,half=upper]");
		$this->placeBlock($c, $doorBottom, 4, 1, 2, $box);
		$this->placeBlock($c, $doorTop, 4, 2, 2, $box);
		$this->placeBlock($c, $doorBottom, 4, 1, 8, $box);
		$this->placeBlock($c, $doorTop, 4, 2, 8, $box);
	}
}
