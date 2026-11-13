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
use pocketmine\world\generator\vanilla\structure\piece\mineshaft\MineshaftPieces;
use pocketmine\world\generator\vanilla\structure\piece\mineshaft\MineShaftRoom;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * minecraft:mineshaft and minecraft:mineshaft_mesa (vanilla's MineshaftStructure and MineshaftPieces): a network of
 * corridors, crossings and stairs grown from a room, below sea level (normal) or up to the surface (mesa, dark oak).
 */
final class MineshaftStructure implements StructureType{
	private bool $mesa;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		$this->mesa = ($json["mineshaft_type"] ?? "normal") === "mesa";
	}

	public function findGenerationPoint(StructureGenContext $context) : array{
		//the position depends on the pieces (generatePiecesAndAdjust()), so they are built here
		//vanilla starts with a discarded context.random().nextDouble()
		$context->random->nextDouble();
		$builder = new StructurePiecesBuilder();
		$yOffset = $this->generatePiecesAndAdjust($builder, $context);
		$pieces = $builder->pieces;
		return [$context->getMinBlockX() + 8, MineshaftPieces::MAGIC_START_Y + $yOffset, $context->getMinBlockZ(), fn() : array => $pieces];
	}

	/**
	 * MineshaftStructure.generatePiecesAndAdjust(): returns the vertical offset applied to the pieces.
	 */
	private function generatePiecesAndAdjust(StructurePiecesBuilder $builder, StructureGenContext $context) : int{
		$random = $context->random;
		$env = $context->env;
		$room = new MineShaftRoom(0, $random, $context->getMinBlockX() + 2, $context->getMinBlockZ() + 2, $this->mesa);
		$builder->addPiece($room);
		$room->addChildren($room, $builder, $random);
		$seaLevel = $env->seaLevel;
		if($this->mesa){
			[$centerX, $centerY, $centerZ] = $builder->getBoundingBox()->getCenter();
			//ChunkGenerator.getBaseHeight() = first free height
			$surface = $env->terrain->getFirstFreeHeight($centerX, $centerZ, TerrainSampler::WORLD_SURFACE_WG);
			$target = $surface <= $seaLevel ? $seaLevel : StructureRandom::between($random, $seaLevel, $surface);
			$dy = $target - $centerY;
			$builder->offsetPiecesVertically($dy);
			return $dy;
		}
		return $builder->moveBelowSeaLevel($seaLevel, $env->minY, $random, 10);
	}

	public function getReach() : int{
		//pieces start at most 80 blocks from the room corner (chunk block 2) and corridors are up to 20 blocks long:
		//-97..+101 blocks from the chunk origin
		return 7;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
