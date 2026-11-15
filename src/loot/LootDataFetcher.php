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

namespace pocketmine\loot;

use pocketmine\utils\Filesystem;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use function count;
use function dirname;
use function explode;
use function filesize;
use function getmypid;
use function glob;
use function hash_file;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function microtime;
use function mkdir;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function time;
use function trim;
use function unlink;
use const GLOB_ONLYDIR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Prepares the data the loot tables need ({@link LootData}) without Java, for servers without a vanilla world: the
 * Minecraft Java Edition {@link VanillaDataFetcher::MINECRAFT_VERSION} server.jar is downloaded from Mojang's official
 * server (same manifest and SHA-1 checks as the vanilla worldgen data), the game jar bundled inside it is opened and
 * only the loot data files are kept ({@link EXTRACTED_PREFIXES}, plus GeyserMC's item mappings). The data is
 * (c) Mojang Studios, used under the Minecraft EULA, and is never shipped with PocketMine-MP.
 *
 * The files land where the vanilla worldgen cache keeps them, so a full cache (with java_items.json) is used the same
 * way, and the worldgen fetch replaces them when a vanilla world needs it. {@link LootData::LOOT_STAMP_FILE} is
 * written last. The server.jar lives in a temporary directory inside the cache directory, deleted afterwards.
 */
final class LootDataFetcher{
	/** Entries of the game jar copied to the cache (the loot data and the tags its predicates may test). */
	public const EXTRACTED_PREFIXES = [
		"data/minecraft/loot_table/",
		"data/minecraft/predicate/",
		"data/minecraft/enchantment/",
		"data/minecraft/enchantment_provider/",
		"data/minecraft/tags/item/",
		"data/minecraft/tags/enchantment/",
		"data/minecraft/tags/entity_type/",
		"data/minecraft/tags/instrument/",
		"data/minecraft/tags/block/",
		"data/minecraft/tags/worldgen/biome/",
	];
	/** The bundler jar lists the jars it carries here: "<sha256>\t<id>\t<path relative to META-INF/versions/>". */
	private const BUNDLER_VERSIONS_LIST = "META-INF/versions.list";
	private const BUNDLER_VERSIONS_DIR = "META-INF/versions/";
	private const TMP_PREFIX = ".tmp-loot-";

	private function __construct(){
		//NOOP
	}

	/**
	 * Whether $root has loot data for this version: a vanilla worldgen cache with loot tables, or a loot-only cache
	 * made for the current {@link VanillaDataFetcher::MINECRAFT_VERSION}.
	 */
	public static function isReady(string $root) : bool{
		if(!LootData::isAvailable($root)){
			return false;
		}
		if(is_file($root . "/" . LootData::JAVA_ITEMS_FILE)){
			return true;
		}
		try{
			$stamp = json_decode(Filesystem::fileGetContents($root . "/" . LootData::LOOT_STAMP_FILE), true, 8, JSON_THROW_ON_ERROR);
		}catch(\JsonException|\RuntimeException){
			return false;
		}
		return is_array($stamp) && ($stamp["minecraft"] ?? null) === VanillaDataFetcher::MINECRAFT_VERSION && is_file($root . "/" . LootData::GEYSER_ITEMS_FILE);
	}

	/**
	 * Downloads and extracts the loot data synchronously (a ~60 MB download). $heartbeat is called regularly and may
	 * throw to cancel.
	 *
	 * @phpstan-param \Closure() : void $heartbeat
	 * @return int[]
	 * @phpstan-return array{int, int, int} files, bytes written, bytes downloaded
	 */
	public static function fetch(string $root, \Closure $heartbeat) : array{
		if(!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)){
			throw new \RuntimeException("Cannot create $root");
		}
		$staleDirs = glob($root . "/" . self::TMP_PREFIX . "*", GLOB_ONLYDIR);
		foreach($staleDirs !== false ? $staleDirs : [] as $stale){
			Filesystem::recursiveUnlink($stale);
		}
		$tmp = $root . "/" . self::TMP_PREFIX . getmypid() . "-" . time();
		if(!@mkdir($tmp, 0777, true)){
			throw new \RuntimeException("Cannot create $tmp");
		}
		try{
			[$serverUrl, $serverSha1, $serverSize] = VanillaDataFetcher::resolveServerJar();
			$jar = $tmp . "/server.jar";
			VanillaDataFetcher::download($serverUrl, $jar, $heartbeat);
			VanillaDataFetcher::verify($jar, "sha1", $serverSha1);
			if(filesize($jar) !== $serverSize){
				throw new \RuntimeException("server.jar size mismatch");
			}
			$downloaded = $serverSize;

			$gameJar = self::extractBundledJar($jar, $tmp);
			@unlink($jar);
			$heartbeat();

			@unlink($root . "/" . LootData::LOOT_STAMP_FILE);
			[$files, $bytes] = self::extractData($gameJar, $root, $heartbeat);

			$geyserDir = $root . "/" . VanillaDataFetcher::GEYSER_DIR;
			@mkdir($geyserDir, 0777, true);
			$itemsSha256 = VanillaDataFetcher::GEYSER_FILES["items.json"];
			$items = $geyserDir . "/items.json";
			if(!is_file($items) || strtolower((string) hash_file("sha256", $items)) !== $itemsSha256){
				VanillaDataFetcher::download(VanillaDataFetcher::GEYSER_MAPPINGS_URL . "items.json", $items, $heartbeat);
				VanillaDataFetcher::verify($items, "sha256", $itemsSha256);
				$downloaded += (int) filesize($items);
			}
			$files++;
			$bytes += (int) filesize($items);

			Filesystem::safeFilePutContents($root . "/" . LootData::LOOT_STAMP_FILE, json_encode([
				"minecraft" => VanillaDataFetcher::MINECRAFT_VERSION,
				"server_jar_sha1" => $serverSha1,
				"files" => $files,
				"bytes" => $bytes,
				"created" => time(),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			return [$files, $bytes, $downloaded];
		}finally{
			Filesystem::recursiveUnlink($tmp);
		}
	}

	/**
	 * Extracts the game jar from Mojang's bundler server.jar (META-INF/versions/<version>/server-<version>.jar),
	 * checking it against the SHA-256 the bundler lists for it. Returns its path.
	 */
	public static function extractBundledJar(string $serverJar, string $directory) : string{
		$reader = new JarReader($serverJar);
		try{
			$path = null;
			$sha256 = null;
			foreach(explode("\n", str_replace("\r", "", $reader->read(self::BUNDLER_VERSIONS_LIST)), limit: 64) as $line){
				$parts = explode("\t", trim($line), limit: 4);
				if(count($parts) === 3){
					[$sha256, , $path] = $parts;
					break;
				}
			}
			if($path === null || $sha256 === null || str_contains($path, "..")){
				throw new \RuntimeException("No game jar listed in " . self::BUNDLER_VERSIONS_LIST);
			}
			$target = $directory . "/game.jar";
			$reader->extractTo(self::BUNDLER_VERSIONS_DIR . $path, $target);
		}finally{
			$reader->close();
		}
		VanillaDataFetcher::verify($target, "sha256", $sha256);
		return $target;
	}

	/**
	 * Copies the loot data entries of the game jar into $root, replacing the previous ones.
	 *
	 * @phpstan-param \Closure() : void $heartbeat
	 * @return int[]
	 * @phpstan-return array{int, int} files, bytes
	 */
	public static function extractData(string $gameJar, string $root, \Closure $heartbeat, bool $allowZipExtension = true) : array{
		$reader = new JarReader($gameJar, $allowZipExtension);
		try{
			$wanted = [];
			foreach($reader->getNames() as $name){
				foreach(self::EXTRACTED_PREFIXES as $prefix){
					if(str_starts_with($name, $prefix)){
						if(str_contains($name, "..")){
							throw new \RuntimeException("Unexpected entry $name in $gameJar");
						}
						$wanted[] = $name;
						break;
					}
				}
			}
			if(count($wanted) === 0){
				throw new \RuntimeException("No loot tables in $gameJar");
			}
			foreach(self::EXTRACTED_PREFIXES as $prefix){
				if(is_dir($root . "/" . $prefix)){
					Filesystem::recursiveUnlink($root . "/" . $prefix);
				}
			}
			$bytes = 0;
			$start = microtime(true);
			foreach($wanted as $i => $name){
				$target = $root . "/" . $name;
				$dir = dirname($target);
				if(!is_dir($dir)){
					@mkdir($dir, 0777, true);
				}
				$contents = $reader->read($name);
				Filesystem::safeFilePutContents($target, $contents);
				$bytes += strlen($contents);
				if(($i & 127) === 0 && microtime(true) - $start > 0.5){
					$start = microtime(true);
					$heartbeat();
				}
			}
			return [count($wanted), $bytes];
		}finally{
			$reader->close();
		}
	}
}
