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

use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\stronghold\StartPiece;
use pocketmine\world\generator\vanilla\structure\piece\stronghold\StrongholdPieces;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use function array_splice;
use function count;

/**
 * minecraft:stronghold (vanilla's StrongholdStructure and StrongholdPieces): corridors, rooms, libraries and the end
 * portal room, generated piece by piece from a staircase in the start chunk until a portal room exists.
 */
final class StrongholdStructure implements StructureType{

	public function findGenerationPoint(StructureGenContext $context) : array{
		return [$context->getMinBlockX(), 0, $context->getMinBlockZ(), fn() : array => self::generatePieces($context)];
	}

	/**
	 * StrongholdStructure.generatePieces(): retries with the next seed until the stronghold has a portal room.
	 * @phpstan-return list<StructurePiece>
	 */
	private static function generatePieces(StructureGenContext $context) : array{
		$env = $context->env;
		$builder = new StructurePiecesBuilder();
		$tries = 0;
		do{
			$builder->clear();
			//setLargeFeatureSeed(seed + tries++, ...): the first try uses the level seed
			$random = StructureRandom::largeFeature(Int64::add($env->seed, $tries++), $context->chunkX, $context->chunkZ);
			$generation = new StrongholdPieces();
			$start = new StartPiece($generation, $random, ($context->chunkX << 4) + 2, ($context->chunkZ << 4) + 2);
			$builder->addPiece($start);
			$start->addChildren($start, $builder, $random);
			while(count($start->pendingChildren) > 0){
				$piece = array_splice($start->pendingChildren, $random->nextBoundedInt(count($start->pendingChildren)), 1)[0];
				$piece->addChildren($start, $builder, $random);
			}
			$builder->moveBelowSeaLevel($env->seaLevel, $env->minY, $random, 10);
		}while($builder->isEmpty() || $start->portalRoomPiece === null);
		return $builder->pieces;
	}

	public function getReach() : int{
		return 8;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
