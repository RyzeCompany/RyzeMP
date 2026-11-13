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
use pocketmine\world\generator\vanilla\structure\piece\IglooPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * minecraft:igloo (vanilla's IglooStructure and IglooPieces): the igloo, and half of the time a ladder shaft down to a
 * basement laboratory.
 */
final class IglooStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : array{
		[$x, $y, $z] = $context->onTopOfChunkCenter(TerrainSampler::WORLD_SURFACE_WG);
		return [$x, $y, $z, function() use ($context) : array{
			$random = $context->random;
			$templates = $context->env->templates;
			$terrain = $context->env->terrain;
			$startX = $context->getMinBlockX();
			$startZ = $context->getMinBlockZ();
			$rotation = $random->nextBoundedInt(4);
			$pieces = [];
			if($random->nextDouble() < 0.5){
				$depth = $random->nextBoundedInt(8) + 4;
				$pieces[] = new IglooPiece($templates, $terrain, IglooPiece::LABORATORY, $startX, $startZ, $rotation, $depth * 3);
				for($i = 0; $i < $depth - 1; ++$i){
					$pieces[] = new IglooPiece($templates, $terrain, IglooPiece::LADDER, $startX, $startZ, $rotation, $i * 3);
				}
			}
			$pieces[] = new IglooPiece($templates, $terrain, IglooPiece::IGLOO, $startX, $startZ, $rotation, 0);
			return $pieces;
		}];
	}

	public function getReach() : int{
		return 1;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
