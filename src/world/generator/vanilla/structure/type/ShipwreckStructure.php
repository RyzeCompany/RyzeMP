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
use pocketmine\world\generator\vanilla\structure\piece\ShipwreckPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use function count;
use function intdiv;
use function min;

/**
 * minecraft:shipwreck (vanilla's ShipwreckStructure and ShipwreckPieces): one random wreck template on the ocean floor,
 * or half buried on a beach.
 */
final class ShipwreckStructure implements StructureType{
	private const BEACHED = [
		"with_mast", "sideways_full", "sideways_fronthalf", "sideways_backhalf", "rightsideup_full", "rightsideup_fronthalf",
		"rightsideup_backhalf", "with_mast_degraded", "rightsideup_full_degraded", "rightsideup_fronthalf_degraded",
		"rightsideup_backhalf_degraded"
	];
	private const OCEAN = [
		"with_mast", "upsidedown_full", "upsidedown_fronthalf", "upsidedown_backhalf", "sideways_full", "sideways_fronthalf",
		"sideways_backhalf", "rightsideup_full", "rightsideup_fronthalf", "rightsideup_backhalf", "with_mast_degraded",
		"upsidedown_full_degraded", "upsidedown_fronthalf_degraded", "upsidedown_backhalf_degraded", "sideways_full_degraded",
		"sideways_fronthalf_degraded", "sideways_backhalf_degraded", "rightsideup_full_degraded",
		"rightsideup_fronthalf_degraded", "rightsideup_backhalf_degraded"
	];

	public function __construct(
		private bool $beached
	){}

	public function findGenerationPoint(StructureGenContext $context) : array{
		$heightmap = $this->beached ? TerrainSampler::WORLD_SURFACE_WG : TerrainSampler::OCEAN_FLOOR_WG;
		[$x, $y, $z] = $context->onTopOfChunkCenter($heightmap);
		return [$x, $y, $z, function() use ($context, $heightmap) : array{
			$random = $context->random;
			$env = $context->env;
			$rotation = $random->nextBoundedInt(4);
			$names = $this->beached ? self::BEACHED : self::OCEAN;
			$location = "minecraft:shipwreck/" . $names[$random->nextBoundedInt(count($names))];
			$piece = new ShipwreckPiece($env->templates, $location, $context->getMinBlockX(), 90, $context->getMinBlockZ(), $rotation, $this->beached);

			//vanilla computes the height in postProcess() from the chunk being decorated (the first chunk wins); here it
			//is computed once from the noise terrain over the template's unrotated area, the same for every chunk
			[$sizeX, $sizeY, $sizeZ] = $env->templates->size($location);
			$terrain = $env->terrain;
			if($sizeX > 32 || $sizeY > 32){
				$box = $piece->box;
				$y = $this->beached ?
					$context->getLowestY($box->minX, $box->minZ, $box->getXSpan(), $box->getZSpan()) - intdiv($sizeY, 2) - $random->nextBoundedInt(3) :
					$context->getMeanFirstOccupiedHeight($box->minX, $box->minZ, $box->getXSpan(), $box->getZSpan(), $heightmap);
			}else{
				$lowest = $env->getMaxY() + 1;
				$sum = 0;
				$count = $sizeX * $sizeZ;
				for($dx = 0; $dx < $sizeX; ++$dx){
					for($dz = 0; $dz < $sizeZ; ++$dz){
						$h = $terrain->getFirstFreeHeight($piece->x + $dx, $piece->z + $dz, $heightmap);
						$sum += $h;
						$lowest = min($lowest, $h);
					}
				}
				if($count === 0){
					$sum = $terrain->getFirstFreeHeight($piece->x, $piece->z, $heightmap);
				}else{
					$sum = intdiv($sum, $count);
				}
				$y = $this->beached ? $lowest - intdiv($sizeY, 2) - $random->nextBoundedInt(3) : $sum;
			}
			$piece->setTemplateY($env->templates, $y);
			return [$piece];
		}];
	}

	public function getReach() : int{
		return 3;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
