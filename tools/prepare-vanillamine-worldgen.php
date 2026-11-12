<?php

declare(strict_types=1);

/**
 * Explicit, offline-to-server bootstrap for VanillaMine's own verified
 * Minecraft Java Edition 26.3 datagen cache.
 *
 * This command requires internet access and a suitable Java runtime;
 * it is NOT executed during normal server startup or in CI.
 *
 * Usage: php tools/prepare-vanillamine-worldgen.php /absolute/new/empty/cache
 *
 * It uses the unmodified VanillaMine fetcher: Mojang's version manifest,
 * SHA-1-verified server.jar, checksum-verified Java runtime when required,
 * and SHA-256-pinned GeyserMC mappings. Review Minecraft's EULA before use.
 */
require dirname(__DIR__) . "/vendor/autoload.php";

use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use pocketmine\world\generator\vanilla\VanillaDataIdentity;
use Symfony\Component\Filesystem\Path;

if($argc !== 2 || !Path::isAbsolute($argv[1])){
	fwrite(STDERR, "Usage: php tools/prepare-vanillamine-worldgen.php /absolute/new/empty/cache\n");
	exit(2);
}
$root = $argv[1];
if(is_link($root) || (is_dir($root) && (scandir($root) ?: []) !== [".", ".."])){
	fwrite(STDERR, "Refusing a non-empty or symlink cache directory. Create a NEW path; never overwrite a world or a pinned cache.\n");
	exit(2);
}
try{
	fwrite(STDERR, "Preparing VanillaMine Minecraft Java 26.3 data (format 5) from the original verified sources.\n");
	fwrite(STDERR, "Do not redistribute the downloaded Mojang assets; observe the Minecraft EULA.\n");
	VanillaDataFetcher::fetch(
		$root,
		static function(string $message) : void{
			fwrite(STDERR, "[VanillaMine datagen] " . $message . "\n");
		}
	);
	if(!VanillaDataFetcher::isReady($root)){
		throw new \RuntimeException("Upstream data cache readiness checks failed");
	}
	$hash = VanillaDataIdentity::fingerprint($root);
	fwrite(STDOUT, "\nVerified data stamp: Minecraft " . VanillaDataFetcher::MINECRAFT_VERSION . ", format " . VanillaDataFetcher::DATA_FORMAT . "\n");
	fwrite(STDOUT, "SHA-256 identity: " . $hash . "\n");
	fwrite(STDOUT, "New world generator settings:\ncore-v2;data=" . realpath($root) . ";sha256=" . $hash . "\n");
}catch(\Throwable $e){
	fwrite(STDERR, "VanillaMine data preparation failed: " . $e->getMessage() . "\n");
	exit(1);
}
