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
 * World data which stores the domobspawning gamerule. Kept out of {@link WorldData} so that world providers written
 * by plugins against the original interface keep working; worlds whose data doesn't implement it always have natural
 * mob spawning enabled (if enabled in pocketmine.yml).
 */
interface MobSpawningWorldData extends WorldData{

	/**
	 * Returns whether mobs spawn naturally (gamerule domobspawning).
	 */
	public function isMobSpawningEnabled() : bool;

	public function setMobSpawningEnabled(bool $enabled) : void;
}
