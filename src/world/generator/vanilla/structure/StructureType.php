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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;

/**
 * The algorithm of a structure type (vanilla's Structure subclasses: jigsaw, igloo, swamp hut...).
 */
interface StructureType{

	/**
	 * Structure.findGenerationPoint(): the position whose biome decides whether the structure can start in the
	 * context's chunk, and the builder of its pieces (called only if the biome is valid), or null if the structure
	 * cannot start here.
	 *
	 * @phpstan-return array{int, int, int, \Closure() : list<StructurePiece>}|null
	 */
	public function findGenerationPoint(StructureGenContext $context) : ?array;

	/**
	 * How far (in chunks) from the start chunk the pieces can reach; vanilla never places pieces of starts more than
	 * 8 chunks away (STRUCTURE_REFERENCES).
	 */
	public function getReach() : int;

	/**
	 * Called after the pieces of a start were placed in a chunk (Structure.afterPlace()).
	 */
	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void;
}
