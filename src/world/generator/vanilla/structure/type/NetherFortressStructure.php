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
use pocketmine\world\generator\vanilla\structure\piece\fortress\StartPiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use function array_splice;
use function count;

/**
 * minecraft:fortress (vanilla's NetherFortressStructure and NetherFortressPieces): bridges and castle corridors of
 * nether bricks, grown piece by piece from a bridge crossing.
 */
final class NetherFortressStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : array{
		return [$context->getMinBlockX(), 64, $context->getMinBlockZ(), function() use ($context) : array{
			$random = $context->random;
			$builder = new StructurePiecesBuilder();
			$start = new StartPiece($random, $context->getMinBlockX() + 2, $context->getMinBlockZ() + 2);
			$builder->addPiece($start);
			$start->addChildren($start, $builder, $random);
			while(count($start->pendingChildren) > 0){
				$index = $random->nextBoundedInt(count($start->pendingChildren));
				$piece = array_splice($start->pendingChildren, $index, 1)[0];
				$piece->addChildren($start, $builder, $random);
			}
			$builder->moveInsideHeights($random, 48, 70);
			return $builder->pieces;
		}];
	}

	public function getReach() : int{
		//pieces are limited to 112 blocks from the start piece
		return 8;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
