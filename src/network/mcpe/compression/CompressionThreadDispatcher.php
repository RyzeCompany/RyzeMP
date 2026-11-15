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

namespace pocketmine\network\mcpe\compression;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandler;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\timings\Timings;
use pocketmine\utils\Binary;
use function chr;
use function count;
use function substr;

/**
 * Main-thread side of the {@link CompressionThread}: every packet batch of every session is compressed once per
 * tick, and with many players close together (every movement goes to every viewer) that compression grows with the
 * square of the player count. The thread takes it off the main thread; the main thread only hands the batches over
 * and sends the results when the thread wakes it (while it sleeps between ticks, or at the start of the next tick
 * when it is overloaded).
 *
 * Unlike async-compression (an AsyncTask per batch, only worth it for big batches), a job here costs the main thread
 * a few microseconds, so every batch above the compression threshold goes through it.
 */
final class CompressionThreadDispatcher{

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $mainToThreadBuffer;
	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $threadToMainBuffer;
	private CompressionThread $thread;
	private int $sleeperNotifierId;

	/**
	 * @var CompressBatchPromise[]
	 * @phpstan-var array<int, CompressBatchPromise>
	 */
	private array $pending = [];
	private int $nextId = 0;
	private bool $shutdown = false;

	/**
	 * @param ThreadSafeClassLoader[]|null $classLoaders class loaders of the thread, null = the server's
	 */
	public function __construct(
		private Compressor $compressor,
		private SleeperHandler $sleeper,
		ThreadSafeLogger $logger,
		?array $classLoaders = null
	){
		$sleeperEntry = $this->sleeper->addNotifier(function() : void{
			$this->collectResults();
		});
		$this->sleeperNotifierId = $sleeperEntry->getNotifierId();

		/** @phpstan-var ThreadSafeArray<int, string> $mainToThreadBuffer */
		$mainToThreadBuffer = new ThreadSafeArray();
		/** @phpstan-var ThreadSafeArray<int, string> $threadToMainBuffer */
		$threadToMainBuffer = new ThreadSafeArray();
		$this->mainToThreadBuffer = $mainToThreadBuffer;
		$this->threadToMainBuffer = $threadToMainBuffer;

		$this->thread = new CompressionThread($logger, $mainToThreadBuffer, $threadToMainBuffer, $sleeperEntry, new NonThreadSafeValue($compressor));
		if($classLoaders !== null){
			$this->thread->setClassLoaders($classLoaders);
		}
		$this->thread->start();
	}

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	/**
	 * Returns whether batches can be handed to the thread (it is running and not shutting down).
	 */
	public function isAvailable() : bool{
		return !$this->shutdown && !$this->thread->isTerminated();
	}

	/**
	 * Hands a batch to the thread. The promise is resolved on the main thread with the compressed batch (algorithm
	 * byte included), in submission order.
	 */
	public function submit(string $buffer) : CompressBatchPromise{
		$id = $this->nextId;
		$this->nextId = ($id + 1) & 0xffffffff;
		$promise = new CompressBatchPromise();
		$this->pending[$id] = $promise;

		$this->mainToThreadBuffer[] = Binary::writeInt($id) . $buffer;
		$this->thread->synchronized(function() : void{
			$this->thread->notify();
		});

		return $promise;
	}

	/**
	 * Resolves the promises of the batches the thread has finished. Called when the thread wakes the main thread up.
	 */
	public function collectResults() : void{
		Timings::$playerNetworkSendCompressThread->startTiming();
		try{
			while(($result = $this->threadToMainBuffer->shift()) !== null){
				$id = Binary::readInt($result) & 0xffffffff;
				$promise = $this->pending[$id] ?? null;
				if($promise === null){
					continue; //dropped at shutdown
				}
				unset($this->pending[$id]);
				$promise->resolve(substr($result, 4));
			}
		}finally{
			Timings::$playerNetworkSendCompressThread->stopTiming();
		}
	}

	/**
	 * Stops the thread. Batches it did not get to are compressed here, so nothing queued is lost.
	 */
	public function shutdown() : void{
		if($this->shutdown){
			return;
		}
		$this->shutdown = true;
		$this->thread->quit();
		$this->collectResults();
		$algorithm = chr($this->compressor->getNetworkId());
		while(($job = $this->mainToThreadBuffer->shift()) !== null){
			$id = Binary::readInt($job) & 0xffffffff;
			$promise = $this->pending[$id] ?? null;
			if($promise !== null){
				unset($this->pending[$id]);
				$promise->resolve($algorithm . $this->compressor->compress(substr($job, 4)));
			}
		}
		$this->pending = [];
		$this->sleeper->removeNotifier($this->sleeperNotifierId);
	}

	public function getPendingCount() : int{
		return count($this->pending);
	}
}
