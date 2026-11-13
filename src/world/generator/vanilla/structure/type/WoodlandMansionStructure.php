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
use pocketmine\world\generator\vanilla\structure\piece\mansion\MansionPiecePlacer;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;

/**
 * minecraft:woodland_mansion (vanilla's WoodlandMansionStructure and WoodlandMansionPieces).
 */
final class WoodlandMansionStructure implements StructureType{

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
			return MansionPiecePlacer::generateMansion($context->env->templates, [$blockX, $startY, $blockZ], $rotation, $context->random);
		}];
	}

	public function getReach() : int{
		return 6;
	}

	/**
	 * WoodlandMansionStructure.afterPlace(): cobblestone columns under the first floor, down to the terrain.
	 */
	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		$level = $c->level;
		$blocks = $c->blocks;
		$minY = $c->env->minY;
		$boundingBox = $start->getPiecesBoundingBox();
		$yStart = $boundingBox->minY;
		//pieces.isInsidePiece(): only the pieces of this chunk can contain a position of the chunk
		$pieceBoxes = [];
		foreach($start->pieces as $piece){
			if($piece->box->intersects($chunkBox)){
				$pieceBoxes[] = $piece->box;
			}
		}
		$cobblestone = $blocks->defaultOf("minecraft:cobblestone");
		for($x = $chunkBox->minX; $x <= $chunkBox->maxX; ++$x){
			for($z = $chunkBox->minZ; $z <= $chunkBox->maxZ; ++$z){
				if($blocks->isAir($level->getState($x, $yStart, $z)) || !$boundingBox->isInside($x, $yStart, $z)){
					continue;
				}
				$insidePiece = false;
				foreach($pieceBoxes as $box){
					if($box->isInside($x, $yStart, $z)){
						$insidePiece = true;
						break;
					}
				}
				if(!$insidePiece){
					continue;
				}
				for($y = $yStart - 1; $y > $minY; --$y){
					$state = $level->getState($x, $y, $z);
					$block = $blocks->blockOf[$state];
					//isEmptyBlock() or BlockState.liquid() (water and lava blocks)
					if(!$blocks->isAir($state) && $block !== $blocks->waterBlock && $block !== $blocks->lavaBlock){
						break;
					}
					$level->setState($x, $y, $z, $cobblestone);
				}
			}
		}
	}
}
