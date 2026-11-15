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

namespace pocketmine\utils;

use Symfony\Component\Filesystem\Path;
use function chmod;
use function copy;
use function defined;
use function filemtime;
use function filesize;
use function is_dir;
use function is_file;
use function mkdir;
use function str_replace;
use function str_starts_with;
use function touch;
use const DIRECTORY_SEPARATOR;

/**
 * Finds the native helper programs shipped with the server (native/<tool>/bin/) and copies the one for this platform
 * out of the phar, since a program cannot run from inside it.
 *
 * @internal
 */
final class BundledBinary{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the program to run: the bundled one when running from source, otherwise a copy of it at $localPath
	 * (refreshed when the bundled one differs). When the server doesn't ship it, a program placed at $localPath by
	 * the server owner is used. Returns null if there is neither, or if copying failed.
	 *
	 * @param string $relativePath path of the bundled program, relative to the server root (native/<tool>/bin/<file>)
	 */
	public static function locate(string $relativePath, string $localPath) : ?string{
		$bundled = self::findBundled($relativePath);
		if($bundled === null){
			return is_file($localPath) ? $localPath : null;
		}
		if(!str_starts_with($bundled, "phar://")){
			return $bundled;
		}
		//size and modification time come from the phar's manifest: reading (or hashing) a compressed entry would make
		//PHP decompress it into a temporary file that stays there until the server stops
		$bundledTime = filemtime($bundled);
		if(!is_file($localPath) || filesize($localPath) !== filesize($bundled) || filemtime($localPath) !== $bundledTime){
			$dir = Path::getDirectory($localPath);
			if(!is_dir($dir) && !@mkdir($dir, 0777, true)){
				return null;
			}
			if(!@copy($bundled, $localPath)){
				return null;
			}
			if($bundledTime !== false){
				@touch($localPath, $bundledTime);
			}
			@chmod($localPath, 0755);
		}
		return $localPath;
	}

	private static function findBundled(string $relativePath) : ?string{
		$path = Path::join(\pocketmine\PATH, $relativePath);
		if(is_file($path)){
			return $path;
		}
		if(defined('pocketmine\ORIGINAL_PHAR_PATH')){
			//the decompressed phar cache leaves the native programs out (see build/server-phar-stub.php), so they are
			//read from the original phar
			$path = "phar://" . str_replace(DIRECTORY_SEPARATOR, "/", \pocketmine\ORIGINAL_PHAR_PATH) . "/" . $relativePath;
			if(is_file($path)){
				return $path;
			}
		}
		return null;
	}
}
