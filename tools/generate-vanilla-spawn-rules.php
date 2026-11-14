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

/**
 * Generates resources/vanilla_spawn_rules.json (see BundledSpawnRules): the spawn conditions read from the Bedrock
 * behavior pack spawn rules pinned by VanillaSpawnRules::FILES, with the same parser the server uses.
 *
 * Usage: php tools/generate-vanilla-spawn-rules.php <behavior_pack/spawn_rules dir> [output.json]
 *
 * The directory can be a server's bedrock-spawn-rules/<tag> folder or a checkout of Mojang/bedrock-samples at the
 * pinned tag (files checked out with CRLF line endings are accepted: they are normalised before the checksum check).
 */

declare(strict_types=1);

namespace pocketmine\tools\generate_vanilla_spawn_rules;

use pocketmine\utils\Filesystem;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use pocketmine\world\mobs\spawn\VanillaSpawnRules;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function getmypid;
use function hash;
use function is_dir;
use function mkdir;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;
use const STDERR;

require dirname(__DIR__) . '/vendor/autoload.php';

if(count($argv) < 2 || !is_dir($argv[1])){
	fwrite(STDERR, "Usage: php " . $argv[0] . " <behavior_pack/spawn_rules dir> [output.json]\n");
	exit(1);
}
$output = $argv[2] ?? dirname(__DIR__) . '/resources/' . BundledSpawnRules::FILE;

$tmp = sys_get_temp_dir() . "/pm-spawn-rules-gen-" . getmypid();
if(!is_dir($tmp)){
	mkdir($tmp, 0777, true);
}
try{
	foreach(VanillaSpawnRules::FILES as $file => $sha256){
		$json = Filesystem::fileGetContents($argv[1] . "/" . $file);
		if(hash("sha256", $json) !== $sha256){
			$json = str_replace("\r\n", "\n", $json);
		}
		file_put_contents($tmp . "/" . $file, $json);
	}
	$rules = VanillaSpawnRules::loadFrom($tmp);
}finally{
	foreach(VanillaSpawnRules::FILES as $file => $_){
		@unlink($tmp . "/" . $file);
	}
	@rmdir($tmp);
}

file_put_contents($output, BundledSpawnRules::toJson($rules));
echo "Wrote " . count($rules->getConditions()) . " conditions and " . count($rules->getPopulations()) . " populations (" . VanillaSpawnRules::TAG . ") to $output\n";
