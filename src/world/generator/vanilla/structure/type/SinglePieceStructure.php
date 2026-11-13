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
use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * vanilla's SinglePieceStructure (desert pyramid, jungle temple): one piece at the chunk corner, only where the four
 * corners of its footprint are at or above sea level.
 */
abstract class SinglePieceStructure implements StructureType{

	public function __construct(
		private int $width,
		private int $depth
	){}

	/**
	 * Builds the piece (the context's random is the start random) and sets its height.
	 */
	abstract protected function createPiece(StructureGenContext $context) : StructurePiece;

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		if($context->getLowestY($context->getMinBlockX(), $context->getMinBlockZ(), $this->width, $this->depth) < $context->env->seaLevel){
			return null;
		}
		[$x, $y, $z] = $context->onTopOfChunkCenter(TerrainSampler::WORLD_SURFACE_WG);
		return [$x, $y, $z, fn() : array => [$this->createPiece($context)]];
	}

	public function getReach() : int{
		return 2;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
