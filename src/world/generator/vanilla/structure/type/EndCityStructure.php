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

namespace pocketmine\world\generator\vanilla\structure\type;

use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\EndCityPieces;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;

/**
 * minecraft:end_city (vanilla's EndCityStructure; pieces in {@link EndCityPieces}).
 */
final class EndCityStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		$blockX = $context->getMinBlockX() + 7;
		$blockZ = $context->getMinBlockZ() + 7;
		//couldValidBiomeExistInTerrainColumn() is left to the biome check of the generation point (same column)
		$rotation = $context->random->nextBoundedInt(4); //Rotation.getRandom()
		$startY = $context->getLowestYIn5by5Box($blockX, $blockZ, $rotation);
		if($startY < 60){
			return null;
		}
		return [$blockX, $startY, $blockZ, function() use ($context, $blockX, $startY, $blockZ, $rotation) : array{
			return (new EndCityPieces($context->env->templates, $context->random))->startHouseTower($blockX, $startY, $blockZ, $rotation);
		}];
	}

	public function getReach() : int{
		return 8;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
