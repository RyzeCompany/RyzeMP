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

namespace pocketmine\world\generator\structure;

use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * Finds generated (or to-be-generated) structures for /locate structure. A structure generator implements this and
 * registers it in {@link StructureLocators}, for a generator name (every world of that generator) or for one world.
 *
 * Both methods are called on the main thread while a player waits for the command, so they must be reasonably fast
 * and must not generate chunks: compute the structure positions from the seed (structure placement grids) and check
 * the biome from the generator's biome source rather than from chunks. Locators whose searches can take longer should
 * implement {@link AsyncStructureLocator}, whose searches /locate runs off the main thread.
 */
interface StructureLocator{

	/**
	 * Structure ids this locator can find, lowercase without namespace, using the Bedrock /locate names where they
	 * exist (e.g. "village", "stronghold", "fortress", "monument", "mansion", "ruined_portal", "end_city").
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getStructureIds() : array;

	/**
	 * Returns the position of the structure of the given type closest to $from within $radiusChunks chunks
	 * (horizontally), or null if there is none. The Y coordinate may be a guess (the command shows "y?" like Bedrock).
	 *
	 * @param string $structureId   one of {@link getStructureIds()}
	 * @param bool   $newChunksOnly only structures in chunks that are not generated yet (Bedrock's useNewChunksOnly)
	 */
	public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3;
}
