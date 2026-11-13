<?php

declare(strict_types=1);

/**
 * Offline, read-only VanillaMine data identity tool.
 *
 * Usage: php tools/worldgen-data-fingerprint.php /absolute/cache/path
 * Requires Composer dependencies, but no server startup, Java or Go runtime.
 * This script never downloads or redistributes any Minecraft data.
 */
require dirname(__DIR__) . "/vendor/autoload.php";

use pocketmine\world\generator\vanilla\VanillaDataIdentity;

if($argc !== 2){
	fwrite(STDERR, "Usage: php tools/worldgen-data-fingerprint.php /absolute/vanillamine-cache\n");
	exit(2);
}
try{
	$root = realpath($argv[1]);
	if($root === false){
		throw new \RuntimeException("Cache directory does not exist");
	}
	$digest = VanillaDataIdentity::fingerprint($root);
	fwrite(STDOUT, "algorithm: " . VanillaDataIdentity::ALGORITHM . "\n");
	fwrite(STDOUT, "minecraft-data: 26.3; cache-format: 5 (verify version.json before use)\n");
	fwrite(STDOUT, "sha256: " . $digest . "\n");
	fwrite(STDOUT, "generator-options: core-v2;data=" . $root . ";sha256=" . $digest . "\n");
}catch(\Throwable $e){
	fwrite(STDERR, "Cannot fingerprint worldgen cache: " . $e->getMessage() . "\n");
	exit(1);
}
