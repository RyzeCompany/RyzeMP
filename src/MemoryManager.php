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

namespace pocketmine;

use pocketmine\event\server\LowMemoryEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\scheduler\DumpWorkerMemoryTask;
use pocketmine\scheduler\GarbageCollectionTask;
use pocketmine\timings\Timings;
use pocketmine\utils\Process;
use pocketmine\YmlServerProperties as Yml;
use function gc_collect_cycles;
use function gc_mem_caches;
use function ini_set;
use function intdiv;
use function max;
use function mb_strtoupper;
use function microtime;
use function preg_match;
use function round;
use function sprintf;

class MemoryManager{
	private const DEFAULT_CHECK_RATE = Server::TARGET_TICKS_PER_SECOND;
	private const DEFAULT_CONTINUOUS_TRIGGER_RATE = Server::TARGET_TICKS_PER_SECOND * 2;
	private const DEFAULT_TICKS_PER_GC = 30 * 60 * Server::TARGET_TICKS_PER_SECOND;
	private const DEFAULT_IDLE_WORKER_TIMEOUT = 300;
	/**
	 * Main thread cyclic GC: a collection walks most of the heap whatever the number of roots (~300-450 ms with 100 players
	 * whether 150k or 1M roots), so it runs at 1M possible roots (every few minutes) instead of PHP's 10001 (every 5-10 s).
	 * The garbage it frees is small (~0.4 MB per million roots); the root buffer costs 8 bytes per root.
	 */
	private const DEFAULT_CYCLE_THRESHOLD_MIN = 1_000_000;
	private const DEFAULT_CYCLE_THRESHOLD_MAX = 4_000_000;
	/**
	 * Main thread cyclic GC while the server has spare time: from this many possible roots (0 = never). The pause grows
	 * with the number of roots, so collecting early when there is time for it keeps the pauses short, and the long ones
	 * at the threshold above only happen while the server is busy.
	 */
	private const DEFAULT_CYCLE_THRESHOLD_SPARE_TIME = 200_000;
	/**
	 * An early collection taking longer than this means the heap is big (a collection walks the part of the heap its
	 * roots reach, whatever their number: with 100 players ~250 ms at 300k roots, ~370 ms at 1M), so collecting early
	 * would cost more CPU without making the pauses much shorter: early collections stop until the next periodic
	 * collection.
	 */
	private const SPARE_TIME_TARGET_PAUSE_NS = 100_000_000;
	/**
	 * Low memory from this share of memory.main-hard-limit when memory.main-limit is 0. The hard limit applies to the
	 * memory PHP took from the system, which with many chunks loaded and unloaded is 15-25% more than the memory in use
	 * (2 MB allocator blocks only partly used): with 100 players exploring, 1 GB was reached at ~840 MB in use.
	 */
	private const DEFAULT_AUTO_MAIN_LIMIT_PERCENT = 70;
	/**
	 * The view distance cap of low memory is raised step by step while the memory used stays under this share of the
	 * low memory limit (see {@link MemoryPressure}).
	 */
	private const RECOVERY_LEVEL = 0.8;
	/** Spare time: at most this share of the tick time used on average over the last second... */
	private const SPARE_TIME_MAX_AVERAGE_USE = 0.6;
	/** ...and at most this share of the current tick's time used so far. */
	private const SPARE_TIME_MAX_TICK_USE = 0.5;
	private const IDLE_WORKER_CHECK_TICKS = 60 * Server::TARGET_TICKS_PER_SECOND;

	private GarbageCollectorManager $cycleGcManager;

	private int $memoryLimit;
	/**
	 * Low memory level used when memory.main-limit is 0: memory.auto-main-limit-percent of memory.main-hard-limit
	 * (0 = none). See {@link MemoryManager::getMainLimit()}.
	 */
	private int $autoMemoryLimit = 0;
	private int $globalMemoryLimit;
	private int $checkRate;
	private int $checkTicker = 0;
	private bool $lowMemory = false;

	private bool $continuousTrigger = true;
	private int $continuousTriggerRate;
	private int $continuousTriggerCount = 0;
	private int $continuousTriggerTicker = 0;

	private int $garbageCollectionPeriod;
	private int $garbageCollectionTicker = 0;
	private int $cycleThresholdSpareTime;
	/** {@link MemoryManager::$cycleThresholdSpareTime}, or 0 after a long early collection (see SPARE_TIME_TARGET_PAUSE_NS). */
	private int $earlyCycleThreshold = 0;

	/**
	 * Seconds without tasks after which an async worker is shut down, checked every minute and at the periodic garbage
	 * collection (0 = only at garbage collection, after 300 s). Low memory and /gc shut down the workers idle for 300 s
	 * whatever this is.
	 */
	private int $idleWorkerTimeout;
	private int $idleWorkerTicker = 0;

	private int $lowMemChunkRadiusOverride;
	private MemoryPressure $pressure;

	private bool $dumpWorkers = true;

	private \Logger $logger;

	public function __construct(
		private Server $server
	){
		$this->logger = new \PrefixedLogger($server->getLogger(), "Memory Manager");
		$this->cycleGcManager = new GarbageCollectorManager($this->logger, Timings::$memoryManager);

		$this->init($server->getConfigGroup());
	}

	private function init(ServerConfigGroup $config) : void{
		$this->memoryLimit = $config->getPropertyInt(Yml::MEMORY_MAIN_LIMIT, 0) * 1024 * 1024;

		$defaultMemory = 1024;

		if(preg_match("/([0-9]+)([KMGkmg])/", $config->getConfigString("memory-limit", ""), $matches) > 0){
			$m = (int) $matches[1];
			if($m <= 0){
				$defaultMemory = 0;
			}else{
				$defaultMemory = match(mb_strtoupper($matches[2])){
					"K" => intdiv($m, 1024),
					"M" => $m,
					"G" => $m * 1024,
					default => $m,
				};
			}
		}

		$hardLimit = $config->getPropertyInt(Yml::MEMORY_MAIN_HARD_LIMIT, $defaultMemory);

		if($hardLimit <= 0){
			ini_set("memory_limit", '-1');
		}else{
			ini_set("memory_limit", $hardLimit . "M");
			$autoPercent = $config->getPropertyInt(Yml::MEMORY_AUTO_MAIN_LIMIT_PERCENT, self::DEFAULT_AUTO_MAIN_LIMIT_PERCENT);
			if($autoPercent > 0 && $autoPercent < 100){
				$this->autoMemoryLimit = intdiv($hardLimit * 1024 * 1024 * $autoPercent, 100);
			}
		}

		$this->globalMemoryLimit = $config->getPropertyInt(Yml::MEMORY_GLOBAL_LIMIT, 0) * 1024 * 1024;
		$this->checkRate = $config->getPropertyInt(Yml::MEMORY_CHECK_RATE, self::DEFAULT_CHECK_RATE);
		$this->continuousTrigger = $config->getPropertyBool(Yml::MEMORY_CONTINUOUS_TRIGGER, true);
		$this->continuousTriggerRate = $config->getPropertyInt(Yml::MEMORY_CONTINUOUS_TRIGGER_RATE, self::DEFAULT_CONTINUOUS_TRIGGER_RATE);

		$this->garbageCollectionPeriod = $config->getPropertyInt(Yml::MEMORY_GARBAGE_COLLECTION_PERIOD, self::DEFAULT_TICKS_PER_GC);
		//the same in both resource profiles: lower thresholds made 0.2-0.5 s collections every ~15 s with 100 players
		//that freed nothing (a collection walks most of the heap whatever the number of roots)
		$this->cycleGcManager->setThresholdLimits(
			$config->getPropertyInt(Yml::MEMORY_GARBAGE_COLLECTION_CYCLE_THRESHOLD_MIN, self::DEFAULT_CYCLE_THRESHOLD_MIN),
			$config->getPropertyInt(Yml::MEMORY_GARBAGE_COLLECTION_CYCLE_THRESHOLD_MAX, self::DEFAULT_CYCLE_THRESHOLD_MAX)
		);
		$this->cycleThresholdSpareTime = max(0, $config->getPropertyInt(Yml::MEMORY_GARBAGE_COLLECTION_CYCLE_THRESHOLD_SPARE_TIME, self::DEFAULT_CYCLE_THRESHOLD_SPARE_TIME));
		$this->earlyCycleThreshold = $this->cycleThresholdSpareTime;
		//a setting still at its shipped value follows the resource profile (performance-profile in server.properties)
		$profile = ActiveResourceProfile::get();
		$this->idleWorkerTimeout = max(0, $profile->resolveInt($config, Yml::MEMORY_ASYNC_WORKER_IDLE_TIMEOUT, self::DEFAULT_IDLE_WORKER_TIMEOUT, $profile->getAsyncWorkerIdleTimeout()));

		$this->lowMemChunkRadiusOverride = $config->getPropertyInt(Yml::MEMORY_MAX_CHUNKS_CHUNK_RADIUS, 4);
		$this->pressure = new MemoryPressure($this->lowMemChunkRadiusOverride);

		$this->dumpWorkers = $config->getPropertyBool(Yml::MEMORY_MEMORY_DUMP_DUMP_ASYNC_WORKER, true);
	}

	public function isLowMemory() : bool{
		return $this->lowMemory;
	}

	public function getGlobalMemoryLimit() : int{
		return $this->globalMemoryLimit;
	}

	/**
	 * @deprecated
	 */
	public function canUseChunkCache() : bool{
		return !$this->lowMemory;
	}

	/**
	 * Returns the allowed chunk radius based on the current memory usage.
	 */
	public function getViewDistance(int $distance) : int{
		return $this->pressure->capViewDistance($distance);
	}

	/**
	 * Main thread memory use (memory_get_usage(), bytes) from which the server is on low memory: memory.main-limit, or
	 * when that is 0, memory.auto-main-limit-percent of memory.main-hard-limit. 0 = never.
	 */
	private function getMainLimit() : int{
		return $this->memoryLimit > 0 ? $this->memoryLimit : $this->autoMemoryLimit;
	}

	/**
	 * Re-applies the players' view distance after the low memory cap changed.
	 */
	private function onViewDistanceCapChanged(int $memory, int $limit) : void{
		$cap = $this->pressure->getCap();
		if($cap > 0){
			$this->logger->warning(sprintf("Low on memory (%d MB used, limit %d MB): view distance limited to %d chunks", intdiv($memory, 1024 * 1024), intdiv($limit, 1024 * 1024), $cap));
		}else{
			$this->logger->info("Memory use is back to normal: view distance no longer limited");
		}
		foreach($this->server->getOnlinePlayers() as $player){
			$player->refreshViewDistance();
		}
	}

	/**
	 * Triggers garbage collection and cache cleanup to try and free memory.
	 */
	public function trigger(int $memory, int $limit, bool $global = false, int $triggerCount = 0) : void{
		$this->logger->debug(sprintf("%sLow memory triggered, limit %gMB, using %gMB",
			$global ? "Global " : "", round(($limit / 1024) / 1024, 2), round(($memory / 1024) / 1024, 2)));
		foreach($this->server->getWorldManager()->getWorlds() as $world){
			$world->clearCache(true);
		}
		ChunkCache::pruneCaches();

		foreach($this->server->getWorldManager()->getWorlds() as $world){
			$world->doChunkGarbageCollection();
		}

		$ev = new LowMemoryEvent($memory, $limit, $global, $triggerCount);
		$ev->call();

		$cycles = $this->triggerGarbageCollector();

		$this->logger->debug(sprintf("Freed %gMB, $cycles cycles", round(($ev->getMemoryFreed() / 1024) / 1024, 2)));
	}

	/**
	 * Called every tick to update the memory manager state.
	 */
	public function check() : void{
		Timings::$memoryManager->startTiming();

		$mainLimit = $this->getMainLimit();
		if(($mainLimit > 0 || $this->globalMemoryLimit > 0) && ++$this->checkTicker >= $this->checkRate){
			$this->checkTicker = 0;
			$memory = Process::getAdvancedMemoryUsage();
			$trigger = false;
			if($mainLimit > 0 && $memory[0] > $mainLimit){
				$trigger = 0;
			}elseif($this->globalMemoryLimit > 0 && $memory[1] > $this->globalMemoryLimit){
				$trigger = 1;
			}

			if($trigger !== false){
				$limit = $trigger > 0 ? $this->globalMemoryLimit : $mainLimit;
				if($this->lowMemory && $this->continuousTrigger){
					if(++$this->continuousTriggerTicker >= $this->continuousTriggerRate){
						$this->continuousTriggerTicker = 0;
						if($this->pressure->onLowMemory($this->server->getViewDistance())){
							$this->onViewDistanceCapChanged($memory[$trigger], $limit);
						}
						$this->trigger($memory[$trigger], $limit, $trigger > 0, ++$this->continuousTriggerCount);
					}
				}elseif(!$this->lowMemory){
					$this->lowMemory = true;
					$this->continuousTriggerCount = 0;
					$this->continuousTriggerTicker = 0;
					if($this->pressure->onLowMemory($this->server->getViewDistance())){
						$this->onViewDistanceCapChanged($memory[$trigger], $limit);
					}
					$this->trigger($memory[$trigger], $limit, $trigger > 0);
				}
			}else{
				$this->lowMemory = false;
				$belowRecovery =
					($mainLimit <= 0 || $memory[0] < $mainLimit * self::RECOVERY_LEVEL) &&
					($this->globalMemoryLimit <= 0 || $memory[1] < $this->globalMemoryLimit * self::RECOVERY_LEVEL);
				if($this->pressure->onMemoryCheck($belowRecovery, $this->server->getViewDistance())){
					$this->onViewDistanceCapChanged($memory[0], $mainLimit);
				}
			}
		}

		if($this->idleWorkerTimeout > 0 && ++$this->idleWorkerTicker >= self::IDLE_WORKER_CHECK_TICKS){
			$this->idleWorkerTicker = 0;
			if(($w = $this->server->getAsyncPool()->shutdownWorkersIdleFor($this->idleWorkerTimeout)) > 0){
				$this->logger->debug("Shut down $w idle async pool workers");
			}
		}

		if($this->garbageCollectionPeriod > 0 && ++$this->garbageCollectionTicker >= $this->garbageCollectionPeriod){
			$this->garbageCollectionTicker = 0;
			//routine collection: keeps the workers for as long as memory.async-worker-idle-timeout says
			$this->earlyCycleThreshold = $this->cycleThresholdSpareTime; //the heap may have changed: try early collections again
			$this->collectGarbage($this->idleWorkerTimeout > 0 ? $this->idleWorkerTimeout : self::DEFAULT_IDLE_WORKER_TIMEOUT);
		}elseif($this->earlyCycleThreshold > 0 && ($roots = $this->cycleGcManager->getRoots()) >= $this->earlyCycleThreshold && $roots < $this->cycleGcManager->getThreshold() && $this->hasSpareTime()){
			$this->cycleGcManager->maybeCollectCyclesEarly($this->earlyCycleThreshold);
			$pause = $this->cycleGcManager->getLastCollectionTimeNs();
			if($pause > self::SPARE_TIME_TARGET_PAUSE_NS){
				$this->earlyCycleThreshold = 0;
				$this->logger->debug("Early cyclic garbage collections stop until the next periodic collection: the last one took " . intdiv($pause, 1_000_000) . " ms");
			}
		}else{
			$this->cycleGcManager->maybeCollectCycles();
		}

		Timings::$memoryManager->stopTiming();
	}

	/**
	 * Whether the server is not busy: under 60% of the tick time used on average over the last second, and the current
	 * tick in its first half.
	 */
	private function hasSpareTime() : bool{
		return $this->server->getTickUsageAverage() <= self::SPARE_TIME_MAX_AVERAGE_USE * 100 &&
			microtime(true) - $this->server->getTickStartTime() <= self::SPARE_TIME_MAX_TICK_USE * Server::TARGET_SECONDS_PER_TICK;
	}

	/**
	 * Collects garbage now (low memory, /gc): also shuts down the async workers that have had no task for 300 s.
	 */
	public function triggerGarbageCollector() : int{
		return $this->collectGarbage(self::DEFAULT_IDLE_WORKER_TIMEOUT);
	}

	private function collectGarbage(int $workerIdleSeconds) : int{
		Timings::$garbageCollector->startTiming();

		$pool = $this->server->getAsyncPool();
		if(($w = $pool->shutdownWorkersIdleFor($workerIdleSeconds)) > 0){
			$this->logger->debug("Shut down $w idle async pool workers");
		}
		foreach($pool->getRunningWorkers() as $i){
			$pool->submitTaskToWorker(new GarbageCollectionTask(), $i);
		}

		$cycles = gc_collect_cycles();
		gc_mem_caches();

		Timings::$garbageCollector->stopTiming();

		return $cycles;
	}

	/**
	 * Dumps the server memory into the specified output folder.
	 */
	public function dumpServerMemory(string $outputFolder, int $maxNesting, int $maxStringSize) : void{
		$logger = new \PrefixedLogger($this->server->getLogger(), "Memory Dump");
		$logger->notice("After the memory dump is done, the server might crash");
		MemoryDump::dumpMemory($this->server, $outputFolder, $maxNesting, $maxStringSize, $logger);

		if($this->dumpWorkers){
			$pool = $this->server->getAsyncPool();
			foreach($pool->getRunningWorkers() as $i){
				$pool->submitTaskToWorker(new DumpWorkerMemoryTask($outputFolder, $maxNesting, $maxStringSize), $i);
			}
		}
	}

	/**
	 * Static memory dumper accessible from any thread.
	 * @deprecated
	 * @see MemoryDump
	 */
	public static function dumpMemory(mixed $startingObject, string $outputFolder, int $maxNesting, int $maxStringSize, \Logger $logger) : void{
		MemoryDump::dumpMemory($startingObject, $outputFolder, $maxNesting, $maxStringSize, $logger);
	}
}
