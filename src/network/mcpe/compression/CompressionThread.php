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
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\thread\Thread;
use function chr;
use function gc_disable;
use function substr;

/**
 * Compresses packet batches for the main thread (see {@link CompressionThreadDispatcher}). Jobs arrive as
 * "<4-byte id><batch>" strings and go back as "<4-byte id><compression algorithm byte><compressed batch>", in the order
 * they were submitted, so every session's batches keep their order.
 */
final class CompressionThread extends Thread{

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $mainToThreadBuffer
	 * @phpstan-param ThreadSafeArray<int, string> $threadToMainBuffer
	 * @phpstan-param NonThreadSafeValue<Compressor> $compressor
	 */
	public function __construct(
		private ThreadSafeLogger $logger,
		private ThreadSafeArray $mainToThreadBuffer,
		private ThreadSafeArray $threadToMainBuffer,
		private SleeperHandlerEntry $sleeperEntry,
		private NonThreadSafeValue $compressor
	){}

	protected function onRun() : void{
		//nothing here builds cycles
		gc_disable();
		\GlobalLogger::set($this->logger);

		/** @var Compressor $compressor */
		$compressor = $this->compressor->deserialize();
		$algorithm = chr($compressor->getNetworkId());
		$notifier = $this->sleeperEntry->createNotifier();

		while(!$this->isKilled){
			$job = $this->mainToThreadBuffer->shift();
			if($job === null){
				$this->synchronized(function() : void{
					if(!$this->isKilled && $this->mainToThreadBuffer->count() === 0){
						$this->wait();
					}
				});
				continue;
			}
			$this->threadToMainBuffer[] = substr($job, 0, 4) . $algorithm . $compressor->compress(substr($job, 4));
			if($this->mainToThreadBuffer->count() === 0){
				//the main thread is woken once per burst of jobs, not once per job
				$notifier->wakeupSleeper();
			}
		}
	}

	public function quit() : void{
		$this->synchronized(function() : void{
			$this->isKilled = true;
			$this->notify();
		});
		parent::quit();
	}

	public function getThreadName() : string{
		return "Network compression";
	}
}
