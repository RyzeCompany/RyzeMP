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

namespace pocketmine\network\mcpe\cache;

use pocketmine\network\mcpe\ChunkWorkerWarmUpTask;
use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\AsyncTask;

/**
 * Chooses the async worker of each chunk packet encoding (ChunkRequestTask).
 *
 * The first chunk a worker encodes makes it build its own TypeConverter (mostly the block palette: ~1.1 s on a slow
 * CPU, ~21 MB), and nothing of that chunk, nor of the chunks queued behind it, reaches the player meanwhile. When the
 * worker the pool picks for a chunk hasn't done that yet, the chunk goes to one that has (the least busy one) unless
 * all of those are far behind; and a worker can be prepared ahead of time with {@link ChunkRequestWorkers::warmUp()},
 * while a player logs in, so that its first chunks don't wait for it.
 *
 * The workers the pool keeps free for chunk generation (see {@link AsyncPool::getReservedWorkerCount()}) are left to
 * it here too, as the pool does: chunks queued behind generation tasks would wait for them.
 *
 * Fewer workers holding a TypeConverter is also less memory. A worker started again (after being idle, or after a
 * crash) has an empty memory again, so it counts as not prepared.
 *
 * @internal
 */
final class ChunkRequestWorkers{
	/**
	 * A prepared worker with this many tasks queued is skipped for a fresh one: ~0.4 s of chunk tasks (2-4 ms each on
	 * a slow CPU), against ~1.2 s before a fresh worker encodes anything.
	 */
	private const MAX_PREPARED_WORKER_BACKLOG = 128;

	/** @phpstan-var \WeakMap<AsyncPool, self>|null */
	private static ?\WeakMap $instances = null;

	public static function get(AsyncPool $pool) : self{
		self::$instances ??= new \WeakMap();
		return self::$instances[$pool] ??= new self($pool);
	}

	/**
	 * @var true[] workerId => true for the workers which have a chunk task or a warm-up task (they have a
	 * TypeConverter, or will have one once that task ran)
	 * @phpstan-var array<int, true>
	 */
	private array $prepared = [];

	private function __construct(
		private AsyncPool $pool
	){
		$pool->addWorkerStartHook(function(int $workerId) : void{
			unset($this->prepared[$workerId]);
		});
	}

	/**
	 * Returns the prepared running worker outside the reserved ones with the shortest queue, or null when there is
	 * none, or all of them are far behind.
	 */
	private function selectPrepared() : ?int{
		$queueSizes = $this->pool->getTaskQueueSizes();
		$reserved = $this->pool->getReservedWorkerCount();
		$best = null;
		$bestQueue = self::MAX_PREPARED_WORKER_BACKLOG;
		foreach($this->prepared as $workerId => $_){
			if(!isset($queueSizes[$workerId])){
				unset($this->prepared[$workerId]); //shut down (the start hook clears it too)
				continue;
			}
			if($workerId >= $reserved && $queueSizes[$workerId] < $bestQueue){
				$best = $workerId;
				$bestQueue = $queueSizes[$workerId];
			}
		}
		return $best;
	}

	/**
	 * Submits a task that needs the worker's TypeConverter (a chunk packet encoding): to the worker the pool picks
	 * (as for any task), unless that one isn't prepared and a prepared one isn't far behind.
	 */
	public function submit(AsyncTask $task) : void{
		$workerId = $this->pool->selectWorker();
		if(!isset($this->prepared[$workerId])){
			$workerId = $this->selectPrepared() ?? $workerId;
		}
		$this->pool->submitTaskToWorker($task, $workerId);
		$this->prepared[$workerId] = true;
	}

	/**
	 * Makes sure a worker is prepared for chunk tasks, or will be soon: if none is, a warm-up task is submitted to an
	 * idle running worker outside the reserved ones, or else to the first of those that isn't running (it is started,
	 * as the pool would start it for the player's chunks), or else to the least busy of them. It only does the work
	 * that the first chunk task would have done on that worker anyway.
	 */
	public function warmUp() : void{
		if($this->selectPrepared() !== null){
			return;
		}
		$queueSizes = $this->pool->getTaskQueueSizes();
		$workerId = null;
		$notRunning = null;
		$leastBusy = null;
		for($i = $this->pool->getReservedWorkerCount(), $size = $this->pool->getSize(); $i < $size; ++$i){
			if(!isset($queueSizes[$i])){
				$notRunning ??= $i;
			}elseif($queueSizes[$i] === 0){
				$workerId = $i;
				break;
			}elseif($leastBusy === null || $queueSizes[$i] < $queueSizes[$leastBusy]){
				$leastBusy = $i;
			}
		}
		$workerId ??= $notRunning ?? $leastBusy ?? $this->pool->selectWorker();
		$this->pool->submitTaskToWorker(new ChunkWorkerWarmUpTask(), $workerId);
		$this->prepared[$workerId] = true;
	}
}
