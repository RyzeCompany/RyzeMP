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
use pocketmine\thread\Thread;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use function microtime;
use function round;
use function touch;
use function unlink;

/**
 * Runs {@link LootDataFetcher::fetch()} away from the main thread. Touches the vanilla data lock file while working
 * (so a vanilla worldgen fetch, here or in another process, does not run in the same directory at the same time) and
 * stops early when the server shuts down.
 */
final class LootDataFetchThread extends Thread{
	private bool $done = false;
	private ?string $error = null;

	public function __construct(
		private string $root,
		private ThreadSafeLogger $logger
	){}

	protected function onRun() : void{
		$lock = $this->root . "/" . VanillaDataFetcher::LOCK_FILE;
		$start = microtime(true);
		try{
			[$files, $bytes, $downloaded] = LootDataFetcher::fetch($this->root, function() use ($lock) : void{
				if($this->isKilled){
					throw new \RuntimeException("the server is stopping");
				}
				@touch($lock);
			});
			$this->logger->info("[Loot] Loot tables ready: $files files (" . VanillaDataFetcher::megabytes($bytes) . ") from a " .
				VanillaDataFetcher::megabytes($downloaded) . " download in " . round(microtime(true) - $start, 1) . " s");
		}catch(\Throwable $e){
			$this->synchronized(function() use ($e) : void{
				$this->error = $e->getMessage();
			});
			$this->logger->warning("[Loot] Could not prepare the loot table data in $this->root: " . $e->getMessage() .
				". Vaults, trial spawners, decorated pots, suspicious sand/gravel and loot chests will not roll loot until it is available (retried at the next start)");
		}
		@unlink($lock);
		$this->synchronized(function() : void{
			$this->done = true;
		});
	}

	public function isDone() : bool{
		return $this->synchronized(fn() : bool => $this->done);
	}

	public function getError() : ?string{
		return $this->synchronized(fn() : ?string => $this->error);
	}

	public function getThreadName() : string{
		return "LootDataFetch";
	}
}
