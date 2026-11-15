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

use pocketmine\timings\TimingsHandler;
use function gc_collect_cycles;
use function gc_disable;
use function gc_status;
use function hrtime;
use function max;
use function memory_get_usage;
use function min;
use function number_format;
use function sprintf;

/**
 * Allows threads to manually trigger the cyclic garbage collector using a threshold like PHP's own garbage collector,
 * but triggered at a time that suits the thread instead of in random code pathways.
 *
 * The GC trigger behaviour in this class was adapted from Zend/zend_gc.c as of PHP 8.3.14.
 */
final class GarbageCollectorManager{
	//TODO: These values could be adjusted to better suit PM, but for now we just want to mirror PHP GC to minimize
	//behavioural changes.
	private const GC_THRESHOLD_TRIGGER = 100;
	private const GC_THRESHOLD_MAX = 1_000_000_000;
	private const GC_THRESHOLD_DEFAULT = 10_001;
	private const GC_THRESHOLD_STEP = 10_000;

	private int $threshold = self::GC_THRESHOLD_DEFAULT;
	private int $minThreshold = self::GC_THRESHOLD_DEFAULT;
	private int $maxThreshold = self::GC_THRESHOLD_MAX;
	private int $collectionTimeTotalNs = 0;
	private int $runs = 0;
	private int $lastCollectionTimeNs = 0;

	private \Logger $logger;
	private TimingsHandler $timings;

	public function __construct(
		\Logger $logger,
		?TimingsHandler $parentTimings,
	){
		gc_disable();
		$this->logger = new \PrefixedLogger($logger, "Cyclic Garbage Collector");
		$this->timings = new TimingsHandler("Cyclic Garbage Collector", $parentTimings);
	}

	private function adjustGcThreshold(int $cyclesCollected, int $rootsAfterGC) : void{
		//TODO Very simple heuristic for dynamic GC buffer resizing:
		//If there are "too few" collections, increase the collection threshold
		//by a fixed step
		//Adapted from zend_gc.c/gc_adjust_threshold() as of PHP 8.3.14
		if($cyclesCollected === 0){
			//nothing to collect at all: a server keeps tens of thousands of possible roots alive that are no garbage, and
			//growing by one step at a time made a 60-140 ms main thread pause every few seconds after a player joined
			//(30k, 40k, 50k... roots, 0 cycles each time); double the threshold instead
			$this->threshold = min($this->maxThreshold, $this->threshold * 2);
		}elseif($cyclesCollected < self::GC_THRESHOLD_TRIGGER || $rootsAfterGC >= $this->threshold){
			$this->threshold = min($this->maxThreshold, $this->threshold + self::GC_THRESHOLD_STEP);
		}elseif($this->threshold > $this->minThreshold){
			$this->threshold = max($this->minThreshold, $this->threshold - self::GC_THRESHOLD_STEP);
		}
	}

	/**
	 * Bounds of the number of possible roots at which a collection runs (memory.garbage-collection.cycle-threshold-min / -max
	 * for the main thread). The threshold starts at the minimum. Without a call (async workers): PHP's own, 10001 and 1 billion.
	 */
	public function setThresholdLimits(int $min, int $max) : void{
		$this->minThreshold = max(1, $min);
		$this->maxThreshold = max($this->minThreshold, $max);
		$this->threshold = $this->minThreshold;
	}

	public function getThreshold() : int{ return $this->threshold; }

	public function getCollectionTimeTotalNs() : int{ return $this->collectionTimeTotalNs; }

	/** Returns how long the last collection took (0 before the first one). */
	public function getLastCollectionTimeNs() : int{ return $this->lastCollectionTimeNs; }

	public function maybeCollectCycles() : int{
		$rootsBefore = gc_status()["roots"];
		if($rootsBefore < $this->threshold){
			return 0;
		}
		return $this->collect($rootsBefore, true);
	}

	/**
	 * Same as {@link GarbageCollectorManager::maybeCollectCycles()}, and also collects from $earlyThreshold possible
	 * roots (below the threshold): for a thread that has spare time now. Early collections don't move the threshold.
	 */
	public function maybeCollectCyclesEarly(int $earlyThreshold) : int{
		$rootsBefore = gc_status()["roots"];
		if($rootsBefore >= $this->threshold){
			return $this->collect($rootsBefore, true);
		}
		if($earlyThreshold <= 0 || $rootsBefore < $earlyThreshold){
			return 0;
		}
		return $this->collect($rootsBefore, false);
	}

	/**
	 * Returns the number of possible roots now (what the thresholds are compared with).
	 */
	public function getRoots() : int{
		return gc_status()["roots"];
	}

	private function collect(int $rootsBefore, bool $adjustThreshold) : int{
		$this->timings->startTiming();

		$memoryBefore = memory_get_usage();
		$start = hrtime(true);
		$cycles = gc_collect_cycles();
		$end = hrtime(true);
		$freed = $memoryBefore - memory_get_usage();

		$rootsAfter = gc_status()["roots"];
		if($adjustThreshold){
			$this->adjustGcThreshold($cycles, $rootsAfter);
		}

		$this->timings->stopTiming();

		$time = $end - $start;
		$this->collectionTimeTotalNs += $time;
		$this->lastCollectionTimeNs = $time;
		$this->runs++;
		$this->logger->info(sprintf(
			"Run #%d%s took %s ms (%s -> %s roots, %s cycles collected, %s MB freed) - cumulative GC time: %s ms",
			$this->runs,
			$adjustThreshold ? "" : " (spare time)",
			number_format($time / 1_000_000, 2),
			$rootsBefore,
			$rootsAfter,
			$cycles,
			number_format($freed / 1_048_576, 2),
			number_format($this->collectionTimeTotalNs / 1_000_000, 2)
		));

		return $cycles;
	}
}
