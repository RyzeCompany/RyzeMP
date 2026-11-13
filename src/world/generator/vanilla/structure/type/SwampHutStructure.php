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
use pocketmine\world\generator\vanilla\structure\piece\SwampHutPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * minecraft:swamp_hut (vanilla's SwampHutStructure and SwampHutPiece): a spruce hut on stilts with a witch and a
 * black cat.
 */
final class SwampHutStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : array{
		[$x, $y, $z] = $context->onTopOfChunkCenter(TerrainSampler::WORLD_SURFACE_WG);
		return [$x, $y, $z, function() use ($context) : array{
			$piece = new SwampHutPiece($context->random, $context->getMinBlockX(), $context->getMinBlockZ());
			$piece->box->move(0, $context->averageHeightInStartChunk($piece->box, TerrainSampler::WORLD_SURFACE_WG) - $piece->box->minY, 0);
			return [$piece];
		}];
	}

	public function getReach() : int{
		return 1;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
