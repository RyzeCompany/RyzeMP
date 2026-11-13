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
use pocketmine\world\generator\vanilla\structure\piece\DesertPyramidPiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use function min;

/**
 * minecraft:desert_pyramid (vanilla's DesertPyramidStructure).
 */
final class DesertPyramidStructure extends SinglePieceStructure{

	public function __construct(){
		parent::__construct(21, 21);
	}

	protected function createPiece(StructureGenContext $context) : StructurePiece{
		$piece = new DesertPyramidPiece($context->random, $context->getMinBlockX(), $context->getMinBlockZ());
		//ScatteredFeaturePiece.updateHeightPositionToLowestGroundHeight(level, -random.nextInt(3)): the lowest terrain of
		//the footprint, with the offset vanilla rolls when the start chunk is the first one decorated
		$terrain = $context->env->terrain;
		$box = $piece->box;
		$lowest = $context->env->getMaxY() + 1;
		for($z = $box->minZ; $z <= $box->maxZ; ++$z){
			for($x = $box->minX; $x <= $box->maxX; ++$x){
				$lowest = min($lowest, $terrain->getFirstFreeHeight($x, $z, TerrainSampler::WORLD_SURFACE_WG));
			}
		}
		$box->move(0, $lowest - $box->minY - $context->startChunkDecorationRandom("minecraft:desert_pyramid")->nextBoundedInt(3), 0);
		return $piece;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		foreach($start->pieces as $piece){
			if($piece instanceof DesertPyramidPiece){
				$piece->placeSuspiciousSand($c, $chunkBox, $start);
			}
		}
	}
}
