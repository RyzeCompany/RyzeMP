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
 * StrongholdPieces.PortalRoom: the end portal over a lava pool, with the silverfish spawner on the stairs.
 */
final class PortalRoom extends StrongholdPiece{
	public static function createPiece(StructurePiecesBuilder $builder, int $x, int $y, int $z, int $direction, int $genDepth) : ?self{
		$box = BoundingBox::orientBox($x, $y, $z, -4, -1, 0, 11, 8, 16, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$start->portalRoomPiece = $this;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		$this->generateSmoothStoneBox($c, $box, 0, 0, 0, 10, 7, 15, false, $random);
		$this->generateSmallDoor($c, $box, self::DOOR_GRATES, 4, 1, 0);
		$this->generateSmoothStoneBox($c, $box, 1, 6, 1, 1, 6, 14, false, $random);
		$this->generateSmoothStoneBox($c, $box, 9, 6, 1, 9, 6, 14, false, $random);
		$this->generateSmoothStoneBox($c, $box, 2, 6, 1, 8, 6, 2, false, $random);
		$this->generateSmoothStoneBox($c, $box, 2, 6, 14, 8, 6, 14, false, $random);
		$this->generateSmoothStoneBox($c, $box, 1, 1, 1, 2, 1, 4, false, $random);
		$this->generateSmoothStoneBox($c, $box, 8, 1, 1, 9, 1, 4, false, $random);
		$lava = $this->state($c, "minecraft:lava");
		$this->fill($c, $box, 1, 1, 1, 1, 1, 3, $lava);
		$this->fill($c, $box, 9, 1, 1, 9, 1, 3, $lava);
		$this->generateSmoothStoneBox($c, $box, 3, 1, 8, 7, 1, 12, false, $random);
		$this->fill($c, $box, 4, 1, 9, 6, 1, 11, $lava);
		$nsBars = $this->state($c, "minecraft:iron_bars[north=true,south=true]");
		$weBars = $this->state($c, "minecraft:iron_bars[west=true,east=true]");
		for($z = 3; $z < 14; $z += 2){
			$this->fill($c, $box, 0, 3, $z, 0, 4, $z, $nsBars);
			$this->fill($c, $box, 10, 3, $z, 10, 4, $z, $nsBars);
		}
		for($x = 2; $x < 9; $x += 2){
			$this->fill($c, $box, $x, 3, 15, $x, 4, 15, $weBars);
		}
		$stairs = $this->state($c, "minecraft:stone_brick_stairs[facing=north]");
		$this->generateSmoothStoneBox($c, $box, 4, 1, 5, 6, 1, 7, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 2, 6, 6, 2, 7, false, $random);
		$this->generateSmoothStoneBox($c, $box, 4, 3, 7, 6, 3, 7, false, $random);
		for($x = 4; $x <= 6; ++$x){
			$this->placeBlock($c, $stairs, $x, 1, 4, $box);
			$this->placeBlock($c, $stairs, $x, 2, 5, $box);
			$this->placeBlock($c, $stairs, $x, 3, 6, $box);
		}
		$allEyes = true;
		$eyes = [];
		for($i = 0; $i < 12; ++$i){
			$eyes[$i] = $random->nextFloat() > 0.8999999761581421;
			$allEyes = $allEyes && $eyes[$i];
		}
		$frame = function(string $facing, int $i) use ($c, $eyes) : int{
			return $this->state($c, "minecraft:end_portal_frame[facing=$facing,eye=" . ($eyes[$i] ? "true" : "false") . "]");
		};
		$this->placeBlock($c, $frame("north", 0), 4, 3, 8, $box);
		$this->placeBlock($c, $frame("north", 1), 5, 3, 8, $box);
		$this->placeBlock($c, $frame("north", 2), 6, 3, 8, $box);
		$this->placeBlock($c, $frame("south", 3), 4, 3, 12, $box);
		$this->placeBlock($c, $frame("south", 4), 5, 3, 12, $box);
		$this->placeBlock($c, $frame("south", 5), 6, 3, 12, $box);
		$this->placeBlock($c, $frame("east", 6), 3, 3, 9, $box);
		$this->placeBlock($c, $frame("east", 7), 3, 3, 10, $box);
		$this->placeBlock($c, $frame("east", 8), 3, 3, 11, $box);
		$this->placeBlock($c, $frame("west", 9), 7, 3, 9, $box);
		$this->placeBlock($c, $frame("west", 10), 7, 3, 10, $box);
		$this->placeBlock($c, $frame("west", 11), 7, 3, 11, $box);
		if($allEyes){
			$portal = $this->state($c, "minecraft:end_portal");
			for($z = 9; $z <= 11; ++$z){
				for($x = 4; $x <= 6; ++$x){
					$this->placeBlock($c, $portal, $x, 3, $z, $box);
				}
			}
		}
		$this->placeSpawner($c, $box, 5, 3, 6, "minecraft:silverfish");
	}
}
