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

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\structure\piece\BlockSelector;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * StrongholdPieces.SmoothStoneSelector: random stone bricks (cracked, mossy, infested) on the edges, cave air inside.
 */
final class SmoothStoneSelector implements BlockSelector{

	public function next(StructurePlaceContext $c, FeatureRandom $random, int $x, int $y, int $z, bool $isEdge) : int{
		if(!$isEdge){
			return $c->blocks->caveAir;
		}
		$selection = $random->nextFloat();
		if($selection < 0.20000000298023224){
			return $c->blocks->defaultOf("minecraft:cracked_stone_bricks");
		}
		if($selection < 0.5){
			return $c->blocks->defaultOf("minecraft:mossy_stone_bricks");
		}
		if($selection < 0.550000011920929){
			return $c->blocks->defaultOf("minecraft:infested_stone_bricks");
		}
		return $c->blocks->defaultOf("minecraft:stone_bricks");
	}
}
