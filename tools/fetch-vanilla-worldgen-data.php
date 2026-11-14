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

/**
 * Prepares the worldgen data needed by the "vanilla" generator in a cache directory
 * (default: ./vanilla-worldgen, i.e. run it from the server data directory). The server does the same automatically
 * the first time a vanilla world is loaded; this script is for preparing it ahead of time or on another machine.
 *
 * The data comes from Mojang's own Minecraft Java Edition server (version pinned in VanillaDataFetcher): server.jar
 * and a portable Eclipse Temurin JRE are downloaded (checksum-verified) to a temporary directory, Mojang's data
 * generator is run, and only the needed files are kept. The data is (c) Mojang Studios, usable under the Minecraft
 * EULA; it is not part of PocketMine-MP and must not be redistributed with it.
 *
 * Set POCKETMINE_VANILLA_JAVA to a java executable (Java 25+) to skip the JRE download.
 *
 * Usage: php tools/fetch-vanilla-worldgen-data.php [target directory]
 */

namespace pocketmine\tools\fetch_vanilla_worldgen_data;

use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use function count;
use function dirname;
use function getcwd;

require dirname(__DIR__) . '/vendor/autoload.php';

$target = count($argv) > 1 ? $argv[1] : (getcwd() . "/" . VanillaGenerator::DEFAULT_DATA_DIR);
VanillaDataFetcher::fetch($target, function(string $message) : void{
	echo $message . "\n";
});
