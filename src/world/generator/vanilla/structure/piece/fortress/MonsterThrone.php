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
 * NetherFortressPieces.MonsterThrone: the raised platform with the blaze spawner.
 */
final class MonsterThrone extends NetherBridgePiece{

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -2, 0, 0, 7, 8, 9, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $box, $direction);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		$air = $c->blocks->air;
		$this->generateBox($c, $box, 0, 2, 0, 6, 7, 7, $air, $air, false);
		$this->generateBox($c, $box, 1, 0, 0, 5, 1, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 2, 1, 5, 2, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 3, 2, 5, 3, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 4, 3, 5, 4, 7, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 2, 0, 1, 4, 2, $bricks, $bricks, false);
		$this->generateBox($c, $box, 5, 2, 0, 5, 4, 2, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 5, 2, 1, 5, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 5, 5, 2, 5, 5, 3, $bricks, $bricks, false);
		$this->generateBox($c, $box, 0, 5, 3, 0, 5, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 6, 5, 3, 6, 5, 8, $bricks, $bricks, false);
		$this->generateBox($c, $box, 1, 5, 8, 5, 5, 8, $bricks, $bricks, false);
		$weFence = $this->state($c, "minecraft:nether_brick_fence[west=true,east=true]");
		$nsFence = $this->state($c, "minecraft:nether_brick_fence[north=true,south=true]");
		$wFence = $this->state($c, "minecraft:nether_brick_fence[west=true]");
		$eFence = $this->state($c, "minecraft:nether_brick_fence[east=true]");
		$this->placeBlock($c, $wFence, 1, 6, 3, $box);
		$this->placeBlock($c, $eFence, 5, 6, 3, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[east=true,north=true]"), 0, 6, 3, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[west=true,north=true]"), 6, 6, 3, $box);
		$this->generateBox($c, $box, 0, 6, 4, 0, 6, 7, $nsFence, $nsFence, false);
		$this->generateBox($c, $box, 6, 6, 4, 6, 6, 7, $nsFence, $nsFence, false);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[east=true,south=true]"), 0, 6, 8, $box);
		$this->placeBlock($c, $this->state($c, "minecraft:nether_brick_fence[west=true,south=true]"), 6, 6, 8, $box);
		$this->generateBox($c, $box, 1, 6, 8, 5, 6, 8, $weFence, $weFence, false);
		$this->placeBlock($c, $eFence, 1, 7, 8, $box);
		$this->generateBox($c, $box, 2, 7, 8, 4, 7, 8, $weFence, $weFence, false);
		$this->placeBlock($c, $wFence, 5, 7, 8, $box);
		$this->placeBlock($c, $eFence, 2, 8, 8, $box);
		$this->placeBlock($c, $weFence, 3, 8, 8, $box);
		$this->placeBlock($c, $wFence, 4, 8, 8, $box);
		//vanilla sets hasPlacedSpawner when the spawner position is in the chunk box: that happens in a single
		//chunk, so the box check (in placeSpawner) alone places it once and the piece stays immutable
		$this->placeSpawner($c, $box, 3, 5, 5, "minecraft:blaze");
		for($x = 0; $x <= 6; ++$x){
			for($z = 0; $z <= 6; ++$z){
				$this->fillColumnDown($c, $bricks, $x, -1, $z, $box);
			}
		}
	}
}
