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

namespace pocketmine\server_phar_stub;

use function chr;
use function clearstatcache;
use function copy;
use function count;
use function crc32;
use function define;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function getenv;
use function getmypid;
use function gzinflate;
use function hrtime;
use function in_array;
use function ini_get;
use function is_dir;
use function is_file;
use function is_int;
use function is_writable;
use function mkdir;
use function number_format;
use function octdec;
use function ord;
use function pack;
use function preg_match;
use function preg_replace_callback;
use function putenv;
use function realpath;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function unpack;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_OS_FAMILY;

/**
 * Finds the appropriate tmp directory to store the decompressed phar cache, accounting for potential file name
 * collisions.
 */
function preparePharCacheDirectory() : string{
	clearstatcache();

	$i = 0;
	do{
		$tmpPath = sys_get_temp_dir() . '/PocketMine-MP-phar-cache.' . $i;
		$i++;
	}while(is_file($tmpPath));
	if(!@mkdir($tmpPath) && !is_dir($tmpPath)){
		throw new \RuntimeException("Failed to create temporary directory $tmpPath. Please ensure the disk has enough space and that the current user has permission to write to this location.");
	}

	return $tmpPath;
}

/**
 * Deletes caches left behind by previous server instances.
 * This ensures that the tmp directory doesn't get flooded by servers crashing in restart loops.
 */
function cleanupPharCache(string $tmpPath) : void{
	clearstatcache();

	/** @var string[] $matches */
	foreach(new \RegexIterator(
		new \FilesystemIterator(
			$tmpPath,
			\FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
		),
		'/(.+)\.lock$/',
		\RegexIterator::GET_MATCH
	) as $matches){
		$lockFilePath = $matches[0];
		$baseTmpPath = $matches[1];

		$file = @fopen($lockFilePath, "rb");
		if($file === false){
			//another process probably deleted the lock file already
			continue;
		}

		if(flock($file, LOCK_EX | LOCK_NB)){
			//this tmpfile is no longer in use
			flock($file, LOCK_UN);
			fclose($file);

			unlink($lockFilePath);
			unlink($baseTmpPath . ".tar");
			unlink($baseTmpPath);
			echo "Deleted stale phar cache at $baseTmpPath\n";
		}else{
			$pid = stream_get_contents($file);
			fclose($file);

			echo "Phar cache at $baseTmpPath is still in use by PID $pid\n";
		}
	}
}

/**
 * Paths inside the phar that are left out of the cache: the native helper programs for every platform
 * (native/<name>/bin/) are most of the phar, and the server copies the one it needs directly from the original phar
 * (see BundledBinary).
 */
const CACHE_EXCLUDED_PATHS = '#^native/[^/]+/bin/#';

/**
 * Returns the ustar header of a regular file, or null if the path is too long for one.
 */
function tarHeader(string $path, int $size, int $mtime) : ?string{
	$name = $path;
	$prefix = "";
	if(strlen($path) > 100){
		//split at a slash into prefix (max 155 bytes) and name (max 100 bytes)
		$slash = strpos($path, "/", strlen($path) - 101);
		if($slash === false || $slash > 155){
			return null;
		}
		$prefix = substr($path, 0, $slash);
		$name = substr($path, $slash + 1);
	}
	$header = pack(
		"a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
		$name, "0000644", "0000000", "0000000", sprintf("%011o", $size), sprintf("%011o", $mtime),
		"        ", "0", "", "ustar", "00", "", "", "", "", $prefix, ""
	);
	$checksum = 0;
	for($i = 0; $i < 512; $i++){
		$checksum += ord($header[$i]);
	}
	return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
}

/**
 * @param resource $file
 */
function readExactly($file, int $length) : string{
	if($length <= 0){
		return "";
	}
	$data = fread($file, $length);
	if($data === false || strlen($data) !== $length){
		throw new PharFormatException("Unexpected end of file");
	}
	return $data;
}

/**
 * Reads a little-endian unsigned 32-bit integer and advances $offset.
 */
function readUint32(string $data, int &$offset) : int{
	$value = $offset + 4 <= strlen($data) ? unpack("V", $data, $offset) : false;
	if($value === false || !is_int($value[1] ?? null)){
		throw new PharFormatException("Manifest too short");
	}
	$offset += 4;
	return $value[1];
}

/**
 * Returns the offset of the phar manifest, using the same rules as the phar extension.
 *
 * @param resource $file
 */
function findPharManifest($file) : int{
	$token = '__HALT_COMPILER' . '();'; //not written as one string, the phar extension looks for its first occurrence
	$head = fread($file, 1024 * 1024);
	$pos = $head === false ? false : strpos($head, $token);
	if($head === false || $pos === false){
		throw new PharFormatException("No $token");
	}
	$offset = $pos + strlen($token);
	if(in_array(substr($head, $offset, 3), [' ?>', "\n?>"], true)){
		$offset += 3;
		if(substr($head, $offset, 1) === "\r"){
			$offset++;
		}
		if(substr($head, $offset, 1) === "\n"){
			$offset++;
		}
	}
	return $offset;
}

/**
 * Writes the uncompressed tar of the phar without the paths in CACHE_EXCLUDED_PATHS.
 *
 * The phar is read directly instead of through the phar extension: reading compressed entries through phar:// makes
 * PHP decompress them into a temporary file that stays there as long as the phar is open (for the whole server run),
 * and phar's own tar writers (buildFromIterator(), addFromString()) are several times slower than convertToData().
 *
 * @throws PharFormatException if the phar is not what this expects (the caller then falls back to convertToData())
 */
function writeCacheTar(string $tarPath, string $pharPath) : void{
	$in = fopen($pharPath, "rb");
	if($in === false){
		throw new \RuntimeException("Failed to open $pharPath");
	}
	$out = fopen($tarPath, "wb");
	if($out === false){
		fclose($in);
		throw new \RuntimeException("Failed to create $tarPath");
	}
	try{
		$manifestOffset = findPharManifest($in);
		fseek($in, $manifestOffset);
		$pos = 0;
		$manifestLength = readUint32(readExactly($in, 4), $pos);
		$manifest = readExactly($in, $manifestLength);
		$dataOffset = $manifestOffset + 4 + $manifestLength;

		$pos = 0;
		$fileCount = readUint32($manifest, $pos);
		$pos += 6; //API version, global flags
		$pos += readUint32($manifest, $pos); //alias
		$metadataLength = readUint32($manifest, $pos);
		$metadata = substr($manifest, $pos, $metadataLength);
		$pos += $metadataLength;
		if($metadata !== ""){
			//the archive metadata (git commit, build number... read by VersionInfo through PharData::getMetadata()):
			//phar's tar format keeps it, serialized like in the phar manifest, in this entry
			$header = tarHeader(".phar/.metadata.bin", strlen($metadata), 0) ?? throw new PharFormatException("Metadata header");
			$data = $header . $metadata . str_repeat("\0", (512 - strlen($metadata) % 512) % 512);
			if(fwrite($out, $data) !== strlen($data)){
				throw new \RuntimeException("Failed to write $tarPath. Please ensure the disk has enough space.");
			}
		}

		for($i = 0; $i < $fileCount; $i++){
			$nameLength = readUint32($manifest, $pos);
			$name = substr($manifest, $pos, $nameLength);
			$pos += $nameLength;
			$size = readUint32($manifest, $pos);
			$mtime = readUint32($manifest, $pos);
			$compressedSize = readUint32($manifest, $pos);
			$crc32 = readUint32($manifest, $pos);
			$flags = readUint32($manifest, $pos);
			$pos += readUint32($manifest, $pos); //metadata

			$entryOffset = $dataOffset;
			$dataOffset += $compressedSize;
			if(str_ends_with($name, "/") || preg_match(CACHE_EXCLUDED_PATHS, $name) === 1){
				continue; //directories are implied by the file paths
			}
			fseek($in, $entryOffset);
			$contents = readExactly($in, $compressedSize);
			if(($flags & 0x1000) !== 0){ //zlib
				$contents = @gzinflate($contents);
				if($contents === false){
					throw new PharFormatException("Failed to decompress $name");
				}
			}elseif(($flags & 0xf000) !== 0){
				throw new PharFormatException("Unsupported compression of $name");
			}
			if(strlen($contents) !== $size || crc32($contents) !== $crc32){
				throw new PharFormatException("Corrupted file $name");
			}

			$header = tarHeader($name, $size, $mtime) ?? throw new PharFormatException("Path too long for a tar header: $name");
			$data = $header . $contents . str_repeat("\0", (512 - $size % 512) % 512);
			if(fwrite($out, $data) !== strlen($data)){
				throw new \RuntimeException("Failed to write $tarPath. Please ensure the disk has enough space.");
			}
		}
		if($pos !== $manifestLength){
			throw new PharFormatException("Unexpected data after the manifest");
		}
		if(fwrite($out, str_repeat("\0", 1024)) !== 1024){ //end of archive
			throw new \RuntimeException("Failed to write $tarPath. Please ensure the disk has enough space.");
		}
	}finally{
		fclose($in);
		fclose($out);
	}
}

final class PharFormatException extends \RuntimeException{

}

function convertPharToTar(string $tmpName, string $pharPath) : string{
	$tarPath = $tmpName . ".tar";
	try{
		writeCacheTar($tarPath, $pharPath);
		return $tarPath;
	}catch(PharFormatException $e){
		echo "Could not read the phar directly ({$e->getMessage()}), converting it with the phar extension instead\n";
		unlink($tarPath);
	}

	//fallback: slower, needs the space of the phar and of the native programs of every platform in the temp directory
	$tmpPharPath = $tmpName . ".phar";
	copy($pharPath, $tmpPharPath);

	$phar = new \Phar($tmpPharPath);
	//phar requires phar.readonly=0, and zip doesn't support disabling compression - tar is the only viable option
	//we don't need phar anyway since we don't need to directly execute the file, only require files from inside it
	$phar->convertToData(\Phar::TAR, \Phar::NONE);
	unset($phar);
	\Phar::unlinkArchive($tmpPharPath);

	return $tarPath;
}

/**
 * Locks a phar tmp cache to prevent it from being deleted by other server instances.
 * This code looks similar to Filesystem::createLockFile(), but we can't use that because it's inside the compressed
 * phar.
 */
function lockPharCache(string $lockFilePath) : void{
	//this static variable will keep the file(s) locked until the process ends
	static $lockFiles = [];

	$lockFile = fopen($lockFilePath, "wb");
	if($lockFile === false){
		throw new \RuntimeException("Failed to open temporary file");
	}
	flock($lockFile, LOCK_EX); //this tells other server instances not to delete this cache file
	fwrite($lockFile, (string) getmypid()); //maybe useful for debugging
	fflush($lockFile);
	$lockFiles[$lockFilePath] = $lockFile;
}

/**
 * Prepares a decompressed .tar of PocketMine-MP.phar in the system temp directory for loading code from.
 *
 * @return string path to the temporary decompressed phar (actually a .tar)
 */
function preparePharCache(string $tmpPath, string $pharPath) : string{
	clearstatcache();

	$tmpName = tempnam($tmpPath, "PMMP");
	if($tmpName === false){
		throw new \RuntimeException("Failed to create temporary file");
	}

	lockPharCache($tmpName . ".lock");
	return convertPharToTar($tmpName, $pharPath);
}

/**
 * Returns the filesystem type (e.g. "tmpfs", "ext4") of the mount that contains $path, read from /proc/self/mounts,
 * or null if it cannot be told (not Linux, no procfs).
 *
 * The mount with the longest mount point that is a prefix of $path wins. Mounts stacked on the same mount point are
 * listed in mount order, so among those the last one (the one on top, which is visible) wins.
 * Not unit tested: this file runs its startup code when it is loaded. Example: "overlay / overlay", then
 * "/dev/sdb1 /tmp ext4", then "tmpfs /tmp tmpfs" gives "tmpfs" for /tmp/.
 */
function filesystemTypeOf(string $path) : ?string{
	$mounts = @file_get_contents("/proc/self/mounts");
	if($mounts === false){
		return null;
	}
	$path = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/') . '/';
	$best = null;
	$bestLength = -1;
	foreach(explode("\n", $mounts, 100000) as $line){
		$fields = explode(" ", $line, 7);
		if(count($fields) < 3){
			continue;
		}
		//mount points escape spaces and other special characters as octal (\040)
		$mountPoint = rtrim(preg_replace_callback('/\\\\([0-7]{3})/', fn(array $m) => chr((int) octdec($m[1])), $fields[1]) ?? $fields[1], '/') . '/';
		if(str_starts_with($path, $mountPoint) && strlen($mountPoint) >= $bestLength){
			$best = $fields[2];
			$bestLength = strlen($mountPoint);
		}
	}
	return $best;
}

/**
 * When the system temporary directory is in RAM (tmpfs: Docker / Pterodactyl containers mount /tmp like that, and
 * there it counts as the container's memory and is often only 100 MB), points TMPDIR at a .tmp directory next to the
 * phar instead, so that the phar cache and every other temporary file of the server go to the server's disk.
 * Does nothing when the temp directory was configured explicitly (TMPDIR or sys_temp_dir) or is not a tmpfs.
 */
function avoidTmpfsTempDirectory(string $pharPath) : void{
	if(PHP_OS_FAMILY !== "Linux" || (string) ini_get("sys_temp_dir") !== "" || getenv("TMPDIR") !== false){
		return;
	}
	$real = realpath("/tmp");
	$type = filesystemTypeOf($real !== false ? $real : "/tmp");
	if($type !== "tmpfs" && $type !== "ramfs"){
		return;
	}
	$dir = dirname($pharPath) . "/.tmp";
	if(!is_dir($dir) && !@mkdir($dir, 0700)){
		return;
	}
	if(!is_writable($dir)){
		return;
	}
	//sys_get_temp_dir() reads TMPDIR the first time it is called, which has not happened yet in this process
	putenv("TMPDIR=$dir");
	echo "/tmp is in memory ($type): using $dir for temporary files\n";
}

/**
 * Creates the temporary directory if it does not exist. PHP takes TMPDIR as it is without checking it, e.g. the
 * Pterodactyl egg's TMPDIR=/home/container/.tmp after .tmp was deleted in the file manager or left out of a restored
 * backup, and the phar cache could then not be created: the server would fail on every start.
 * Must be called after avoidTmpfsTempDirectory(): sys_get_temp_dir() reads TMPDIR only the first time it is called.
 */
function ensureTempDirectoryExists() : void{
	clearstatcache();
	$dir = sys_get_temp_dir();
	if($dir !== "" && !is_dir($dir)){
		if(@mkdir($dir, 0700, true)){
			echo "Created the missing temporary directory $dir\n";
		}
		//if it failed, preparePharCacheDirectory() reports the path
	}
}

avoidTmpfsTempDirectory(__FILE__);
ensureTempDirectoryExists();
$tmpDir = preparePharCacheDirectory();
cleanupPharCache($tmpDir);
echo "Preparing PocketMine-MP.phar decompressed cache...\n";
$start = hrtime(true);
$cacheName = preparePharCache($tmpDir, __FILE__);
echo "Cache ready at $cacheName in " . number_format((hrtime(true) - $start) / 1e9, 2) . "s\n";

define('pocketmine\ORIGINAL_PHAR_PATH', __FILE__);
require 'phar://' . str_replace(DIRECTORY_SEPARATOR, '/', $cacheName) . '/src/PocketMine.php';
