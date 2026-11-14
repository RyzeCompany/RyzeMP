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

namespace pocketmine\world\format\io;

/**
 * World data which stores the age of the world: the number of ticks it has run since it was created, which unlike the
 * world time is not changed by /time and keeps going while the daylight cycle is off (Bedrock level.dat "currentTick").
 * Kept out of {@link WorldData} so that world providers written by plugins against the original interface keep
 * working; for worlds whose data doesn't implement it, the age starts from the world time when the world is loaded.
 */
interface WorldAgeWorldData extends WorldData{

	/**
	 * Returns the stored age of the world in ticks, or null if the world has none yet (worlds saved by older versions).
	 */
	public function getWorldAge() : ?int;

	public function setWorldAge(int $ticks) : void;
}
