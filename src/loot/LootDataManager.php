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

use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use function filemtime;
use function is_dir;
use function is_file;
use function mkdir;
use function rtrim;
use function str_replace;
use function time;
use function touch;

/**
 * Main-thread side of the loot-only data download ({@link LootDataFetcher}): called once at server startup, after the
 * worlds are loaded (a vanilla world has then set the data root to its fully prepared worldgen data). If the data
 * root has no loot data, the download starts in a {@link LootDataFetchThread}; nothing waits for it, and loot tables
 * work as soon as it is done ({@link LootTables::getData()} checks again until the data is there).
 * @internal
 */
final class LootDataManager{
	/** Same as the vanilla worldgen data: a lock not touched for this long belongs to a dead job. */
	private const LOCK_STALE_SECONDS = 120;

	private static ?LootDataFetchThread $job = null;
	private static ?string $jobRoot = null;
	/** Why the loot data is missing, when known (automatic download disabled, another process fetching...). */
	private static ?string $reason = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Starts the download in the background if the loot data is missing in $root. Never blocks.
	 */
	public static function prepare(string $root, bool $autoDownload, ThreadSafeLogger $logger) : void{
		self::$reason = null;
		if(LootDataFetcher::isReady($root) || self::isFetching($root)){
			return;
		}
		if(!$autoDownload){
			self::$reason = "loot-tables.auto-download is false in pocketmine.yml, and no vanilla world has prepared it";
			$logger->debug("[Loot] No loot table data in $root and the automatic download is disabled");
			return;
		}
		$lock = $root . "/" . VanillaDataFetcher::LOCK_FILE;
		if(is_file($lock) && time() - (int) @filemtime($lock) < self::LOCK_STALE_SECONDS){
			self::$reason = "another process is preparing the vanilla data in $root (restart once it is done)";
			$logger->info("[Loot] The vanilla data in $root is being prepared by another process; loot tables work once it is done (after a restart)");
			return;
		}
		if(!is_dir($root)){
			@mkdir($root, 0777, true);
		}
		@touch($lock);
		$logger->info("[Loot] Loot table data not found in $root: downloading Minecraft Java Edition " . VanillaDataFetcher::MINECRAFT_VERSION .
			"'s server.jar from Mojang's official server in the background to read it (one-time, about a minute; (c) Mojang, used under the Minecraft EULA)");
		self::$job = new LootDataFetchThread($root, $logger);
		self::$jobRoot = self::key($root);
		self::$job->start();
	}

	/**
	 * Whether the loot data for $root is being downloaded by this process.
	 */
	public static function isFetching(string $root) : bool{
		return self::$job !== null && self::$jobRoot === self::key($root) && !self::$job->isDone();
	}

	/**
	 * Why the loot data in $root is not available, for the warning shown the first time a loot table is needed.
	 */
	public static function getUnavailableReason(string $root) : string{
		if(self::$job !== null && self::$jobRoot === self::key($root)){
			if(!self::$job->isDone()){
				return "it is still being downloaded";
			}
			$error = self::$job->getError();
			if($error !== null){
				return "the download failed ($error)";
			}
		}
		return self::$reason ?? "it is missing";
	}

	private static function key(string $root) : string{
		return rtrim(str_replace("\\", "/", $root), "/");
	}
}
