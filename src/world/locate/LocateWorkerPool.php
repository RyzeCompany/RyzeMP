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

namespace pocketmine\world\locate;

use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\YmlServerProperties;
use function array_sum;
use function max;
use function time;

/**
 * A separate async pool with a single worker for /locate searches (biome noise sampling, structure placement checks).
 *
 * The server's async pool also generates chunks, so long searches there delay terrain for players; this pool is
 * started on first use and shut down after {@link IDLE_SECONDS} without work, so it costs nothing on servers that never
 * use /locate. Callers should limit how many searches they queue ({@link getPendingCount()}).
 */
final class LocateWorkerPool{
	/** Seconds without queued tasks after which the worker thread is stopped. */
	public const IDLE_SECONDS = 60;

	private static ?self $instance = null;

	private ?AsyncPool $pool = null;
	private int $idleSince;

	private function __construct(
		private Server $server
	){
		$this->idleSince = time();
	}

	public static function getInstance(Server $server) : self{
		if(self::$instance === null || self::$instance->server !== $server){
			self::$instance?->shutdown();
			self::$instance = new self($server);
		}
		return self::$instance;
	}

	/**
	 * Submits a task to the locate worker, starting it if needed.
	 */
	public function submitTask(AsyncTask $task) : void{
		if($this->pool === null){
			$this->pool = new AsyncPool(
				1,
				max(-1, $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::MEMORY_ASYNC_WORKER_HARD_LIMIT, 384)),
				$this->server->getLoader(),
				$this->server->getLogger(),
				$this->server->getTickSleeper()
			);
			$this->server->getLogger()->debug("Started the /locate worker");
		}
		$this->pool->submitTask($task);
		$this->idleSince = time();
	}

	/**
	 * Number of tasks queued or running on the locate worker.
	 */
	public function getPendingCount() : int{
		return $this->pool === null ? 0 : array_sum($this->pool->getTaskQueueSizes());
	}

	public function isRunning() : bool{
		return $this->pool !== null;
	}

	/**
	 * Collects finished tasks and stops the worker once it has been idle for {@link IDLE_SECONDS}. Called by the server
	 * once per second.
	 */
	public function tick() : void{
		if($this->pool === null){
			return;
		}
		if($this->pool->collectTasks()){
			$this->idleSince = time();
		}elseif(time() - $this->idleSince >= self::IDLE_SECONDS){
			$this->server->getLogger()->debug("The /locate worker has been idle for " . self::IDLE_SECONDS . " seconds");
			$this->shutdown();
		}
	}

	public function shutdown() : void{
		if($this->pool !== null){
			$this->pool->shutdown();
			$this->pool = null;
			$this->server->getLogger()->debug("Stopped the /locate worker");
		}
		$this->idleSince = time();
	}

	/**
	 * Ticks the pool if it was ever used (no-op otherwise).
	 * @internal
	 */
	public static function tickInstance() : void{
		self::$instance?->tick();
	}

	/**
	 * Stops the worker, if any (on server shutdown).
	 * @internal
	 */
	public static function shutdownInstance() : void{
		self::$instance?->shutdown();
		self::$instance = null;
	}
}
