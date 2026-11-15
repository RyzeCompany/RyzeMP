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

namespace pocketmine\world\generator\vanilla;

use pocketmine\loot\LootDataManager;
use pocketmine\Server;
use function filemtime;
use function is_dir;
use function is_file;
use function mkdir;
use function rtrim;
use function str_replace;
use function time;
use function touch;
use function usleep;

/**
 * Main-thread side of the automatic vanilla data setup, called when a world using the vanilla generator is created
 * or loaded (generator preset validation).
 *
 * - Data ready: nothing to do.
 * - Data missing during server startup (no players yet, tick 0): the fetch runs in a {@link VanillaDataFetchThread}
 *   and the world load waits for it, logging progress. The main thread only sleeps; nothing is ticking yet.
 * - Data missing while the server is running: the fetch starts in the background and this world load is refused
 *   with a message (loading it again once the data is ready works). Blocking would freeze the server, and letting
 *   generation workers wait would block the shared async pool (and its shutdown).
 * - Failures (no network, checksum mismatch, generator error) refuse the world load with the reason; the next load
 *   attempt retries. Unsupported platforms without a configured Java are reported without downloading anything.
 */
final class VanillaDataManager{
	/** A lock not touched for this long belongs to a dead job. */
	private const LOCK_STALE_SECONDS = 120;
	private const PROGRESS_LOG_SECONDS = 30;

	/** @phpstan-var array<string, VanillaDataFetchThread> */
	private static array $jobs = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns null if the data in $root is ready, otherwise why the world cannot be loaded yet.
	 */
	public static function ensureReady(string $root) : ?string{
		if(VanillaDataFetcher::isReady($root)){
			return null;
		}
		$platformError = VanillaDataFetcher::checkPlatform();
		if($platformError !== null){
			return "Vanilla worldgen data (Minecraft " . VanillaDataFetcher::MINECRAFT_VERSION . ") is missing in $root and cannot be prepared automatically: $platformError";
		}

		$key = rtrim(str_replace("\\", "/", $root), "/");
		$server = Server::getInstance();
		$logger = $server->getLogger();
		$job = self::$jobs[$key] ?? null;
		if($job === null){
			$lock = $root . "/" . VanillaDataFetcher::LOCK_FILE;
			if(LootDataManager::isFetching($root)){
				return "The loot table data is being downloaded to $root; load the world again in a minute";
			}
			if(is_file($lock) && time() - (int) @filemtime($lock) < self::LOCK_STALE_SECONDS){
				return "Vanilla worldgen data is being prepared in $root by another process; try again in a few minutes";
			}
			if(!is_dir($root)){
				@mkdir($root, 0777, true);
			}
			@touch($lock);
			$logger->info("[Vanilla worldgen data] Not found in $root: fetching it in the background from Mojang's official server (one-time, a few minutes)");
			$job = self::$jobs[$key] = new VanillaDataFetchThread($root, $logger);
			$job->start();
		}

		if(!$job->isDone() && $server->getTick() === 0){
			//server startup: nobody is connected yet, so wait for the data instead of failing the world load
			$logger->info("[Vanilla worldgen data] Waiting for the data before loading the world (server startup)");
			$lastLog = time();
			while(!$job->isDone() && !VanillaDataFetcher::isReady($root) && $server->isRunning()){
				usleep(100_000);
				if(time() - $lastLog >= self::PROGRESS_LOG_SECONDS){
					$lastLog = time();
					$logger->info("[Vanilla worldgen data] Still preparing...");
				}
			}
		}

		if(!$job->isDone()){
			if(VanillaDataFetcher::isReady($root)){
				return null; //the job is only deleting its temporary files
			}
			return "Vanilla worldgen data (Minecraft " . VanillaDataFetcher::MINECRAFT_VERSION . ") is being prepared in the background; load the world again in a few minutes";
		}

		$job->quit();
		unset(self::$jobs[$key]);
		$error = $job->getError();
		if($error !== null || !VanillaDataFetcher::isReady($root)){
			$logger->error("[Vanilla worldgen data] Failed: " . ($error ?? "no data was written"));
			return "Could not prepare the vanilla worldgen data in $root: " . ($error ?? "no data was written") . ". It will be retried on the next load attempt; it can also be prepared manually with tools/fetch-vanilla-worldgen-data.php";
		}
		return null;
	}
}
