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
 * StrongholdPieces.ChestCorridor: a corridor with a slab altar holding a chest.
 */
final class ChestCorridor extends StrongholdPiece{
	private function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->entryDoor = self::randomSmallDoor($random);
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -1, -1, 0, 5, 5, 7, $direction);
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
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 4, 4, 6, true, $random);
		$this->generateSmallDoor($c, $box, $this->entryDoor, 1, 1, 0);
		$this->generateSmallDoor($c, $box, self::DOOR_OPENING, 1, 1, 6);
		$this->fill($c, $box, 3, 1, 2, 3, 1, 4, $this->state($c, "minecraft:stone_bricks"));
		$slab = $this->state($c, "minecraft:stone_brick_slab");
		$this->placeBlock($c, $slab, 3, 1, 1, $box);
		$this->placeBlock($c, $slab, 3, 1, 5, $box);
		$this->placeBlock($c, $slab, 3, 2, 2, $box);
		$this->placeBlock($c, $slab, 3, 2, 4, $box);
		for($z = 2; $z <= 4; ++$z){
			$this->placeBlock($c, $slab, 2, 1, $z, $box);
		}
		$this->createChest($c, $box, $random, 3, 2, 3, "minecraft:chests/stronghold_corridor");
	}
}
