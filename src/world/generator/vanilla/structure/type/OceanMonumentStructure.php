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
use pocketmine\world\generator\vanilla\structure\piece\monument\MonumentBuilding;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use function array_flip;

/**
 * minecraft:ocean_monument (vanilla's OceanMonumentStructure and OceanMonumentPieces): a 58x58 prismarine building on
 * the ocean floor, only where every biome within 29 blocks is a deep ocean (or another monument surrounding biome).
 */
final class OceanMonumentStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		$env = $context->env;
		$offsetX = $context->getMinBlockX() + 9;
		$offsetZ = $context->getMinBlockZ() + 9;
		$allowed = array_flip($env->registry->resolveBiomes("#minecraft:required_ocean_monument_surrounding"));
		//BiomeResolver.getBiomesWithin(offsetX, seaLevel, offsetZ, 29): every biome must be in the tag (the whole
		//set is checked in vanilla; stopping at the first failure gives the same answer)
		$radius = MonumentBuilding::BIOME_RANGE_CHECK;
		$y = $env->seaLevel;
		$x0 = ($offsetX - $radius) >> 2;
		$y0 = ($y - $radius) >> 2;
		$z0 = ($offsetZ - $radius) >> 2;
		$x1 = ($offsetX + $radius) >> 2;
		$y1 = ($y + $radius) >> 2;
		$z1 = ($offsetZ + $radius) >> 2;
		for($noiseZ = $z0; $noiseZ <= $z1; ++$noiseZ){
			for($noiseX = $x0; $noiseX <= $x1; ++$noiseX){
				for($noiseY = $y0; $noiseY <= $y1; ++$noiseY){
					if(!isset($allowed[$env->getBiome($noiseX << 2, $noiseY << 2, $noiseZ << 2)])){
						return null;
					}
				}
			}
		}
		[$x, $y, $z] = $context->onTopOfChunkCenter(TerrainSampler::OCEAN_FLOOR_WG);
		return [$x, $y, $z, function() use ($context) : array{
			//OceanMonumentStructure.createTopPiece()
			$west = $context->getMinBlockX() - 29;
			$north = $context->getMinBlockZ() - 29;
			$orientation = OrientedPiece::randomHorizontalDirection($context->random);
			return [new MonumentBuilding($context->random, $west, $north, $orientation)];
		}];
	}

	public function getReach() : int{
		return 3;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
