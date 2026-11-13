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

namespace pocketmine\world\generator\vanilla\structure\piece\mineshaft;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use function abs;

/**
 * Vanilla's MineshaftPieces: the random growth of a mineshaft from its start room.
 */
final class MineshaftPieces{
	/** MineshaftPieces.MAX_DEPTH */
	public const MAX_DEPTH = 8;
	/** MineshaftPieces.MAGIC_START_Y */
	public const MAGIC_START_Y = 50;

	private function __construct(){
		//NOOP
	}

	/**
	 * MineshaftPieces.createRandomShaftPiece()
	 */
	private static function createRandomShaftPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth, bool $mesa) : ?MineShaftPiece{
		$selection = $random->nextBoundedInt(100);
		if($selection >= 80){
			$box = MineShaftCrossing::findCrossing($builder, $random, $footX, $footY, $footZ, $direction);
			return $box !== null ? new MineShaftCrossing($genDepth, $box, $direction, $mesa) : null;
		}
		if($selection >= 70){
			$box = MineShaftStairs::findStairs($builder, $footX, $footY, $footZ, $direction);
			return $box !== null ? new MineShaftStairs($genDepth, $box, $direction, $mesa) : null;
		}
		$box = MineShaftCorridor::findCorridorSize($builder, $random, $footX, $footY, $footZ, $direction);
		return $box !== null ? new MineShaftCorridor($genDepth, $random, $box, $direction, $mesa) : null;
	}

	/**
	 * MineshaftPieces.generateAndAddPiece(): adds a random piece at the given foot (unless too deep or more than 80
	 * blocks from the start room) and grows its children.
	 */
	public static function generateAndAddPiece(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $depth) : ?MineShaftPiece{
		if($depth > self::MAX_DEPTH){
			return null;
		}
		if(abs($footX - $start->box->minX) > 80 || abs($footZ - $start->box->minZ) > 80){
			return null;
		}
		$piece = self::createRandomShaftPiece($builder, $random, $footX, $footY, $footZ, $direction, $depth + 1, $start->mesa);
		if($piece !== null){
			$builder->addPiece($piece);
			$piece->addChildren($start, $builder, $random);
		}
		return $piece;
	}
}
