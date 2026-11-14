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

namespace pocketmine\world\mobs\spawn;

use pocketmine\world\World;

/**
 * Tells the natural mob spawner where structure spawn areas are (see {@link StructureSpawnArea}), e.g. a structure
 * generator remembering the swamp huts and fortresses it placed. Register with {@link StructureSpawns::register()}.
 */
interface StructureSpawnProvider{

	/**
	 * Returns the structure spawn areas whose box contains the column (x, z) of the world. Called on the main thread
	 * for each successful cluster spawn attempt, so it must be cheap (e.g. a lookup by chunk).
	 *
	 * @return StructureSpawnArea[]
	 * @phpstan-return list<StructureSpawnArea>
	 */
	public function getSpawnAreas(World $world, int $x, int $z) : array;
}
