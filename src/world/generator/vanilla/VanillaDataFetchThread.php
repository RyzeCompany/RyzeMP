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

use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use function touch;
use function unlink;

/**
 * Runs {@link VanillaDataFetcher::fetch()} away from the main thread (downloads + Mojang's data generator take
 * minutes). Touches the lock file while working so other processes can tell a live job from a dead one.
 */
final class VanillaDataFetchThread extends Thread{
	private bool $done = false;
	private ?string $error = null;

	public function __construct(
		private string $root,
		private ThreadSafeLogger $logger
	){}

	protected function onRun() : void{
		$lock = $this->root . "/" . VanillaDataFetcher::LOCK_FILE;
		$logger = $this->logger;
		try{
			VanillaDataFetcher::fetch(
				$this->root,
				function(string $message) use ($logger) : void{
					$logger->info("[Vanilla worldgen data] " . $message);
				},
				function() use ($lock) : void{
					@touch($lock);
				}
			);
		}catch(\Throwable $e){
			$this->synchronized(function() use ($e) : void{
				$this->error = $e->getMessage();
			});
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
}
