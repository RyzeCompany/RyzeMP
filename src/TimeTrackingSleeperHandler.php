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

use pocketmine\plugin\PluginFaultHandler;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Utils;
use function chunkutils2_precise_sleep;
use function function_exists;
use function hrtime;
use function microtime;
use const PHP_OS_FAMILY;

/**
 * Custom Snooze sleeper handler which captures notification processing time.
 * @internal
 */
final class TimeTrackingSleeperHandler extends SleeperHandler{

	private int $notificationProcessingTimeNs = 0;

	/**
	 * @var TimingsHandler[]
	 * @phpstan-var array<string, TimingsHandler>
	 */
	private static array $handlerTimings = [];

	/**
	 * How long before the target time the regular wait stops when the precise sleep is used: one Windows clock
	 * interrupt (15.625 ms) plus the up to ~1.5 ms the wait's millisecond rounding adds (measured).
	 */
	private const PRECISE_SLEEP_MARGIN = 0.017;
	/** Shorter remainders are not slept: the precise timer itself takes ~0.5 ms to fire on some machines. */
	private const PRECISE_SLEEP_MIN = 0.0002;

	private bool $preciseSleep;

	public function __construct(
		private TimingsHandler $timings
	){
		parent::__construct();
		$this->preciseSleep = PHP_OS_FAMILY === "Windows" && function_exists("chunkutils2_precise_sleep");
	}

	public function addNotifier(\Closure $handler) : SleeperHandlerEntry{
		$name = Utils::getNiceClosureName($handler);
		$timings = self::$handlerTimings[$name] ??= new TimingsHandler("Snooze Handler: " . $name, $this->timings);

		return parent::addNotifier(function() use ($timings, $handler, $name) : void{
			$timings->startTiming();
			try{
				$handler();
			}catch(\Throwable $e){
				//notifiers are often registered by plugins and virions (e.g. database result callbacks); rethrown if
				//no plugin is to blame, so core failures still crash the server
				PluginFaultHandler::isolate($e, null, "sleeper notifier " . $name, $handler);
			}finally{
				$timings->stopTiming();
			}
		});
	}

	/**
	 * Returns the time in nanoseconds spent processing notifications since the last reset.
	 */
	public function getNotificationProcessingTime() : int{ return $this->notificationProcessingTimeNs; }

	/**
	 * Resets the notification processing time tracker to zero.
	 */
	public function resetNotificationProcessingTime() : void{ $this->notificationProcessingTimeNs = 0; }

	/**
	 * On Windows every timed wait ends on a system clock interrupt (every 15.625 ms by default), so a plain wait for the
	 * next tick wakes up 0-15.6 ms late and idle ticks land 46.9 or 62.5 ms apart instead of 50 ms. With chunkutils2's
	 * precise sleep (a high resolution waitable timer, which unlike timeBeginPeriod() leaves the system-wide timer
	 * resolution alone), the regular wait - which wakes up immediately for notifications - stops one clock interrupt
	 * early, and the precise timer sleeps the rest (on average ~8 ms; notifications received during that part are
	 * processed when it ends, right before the tick).
	 */
	public function sleepUntil(float $unixTime) : void{
		if($this->preciseSleep){
			parent::sleepUntil($unixTime - self::PRECISE_SLEEP_MARGIN);
			//a waitable timer never fires early: no need to check the clock again afterwards
			$left = $unixTime - microtime(true);
			if($left > self::PRECISE_SLEEP_MIN && !chunkutils2_precise_sleep((int) ($left * 1_000_000_000))){
				$this->preciseSleep = false; //no high resolution timer on this system: plain waits from now on
				parent::sleepUntil($unixTime);
				return;
			}
			$this->processNotifications();
			return;
		}
		parent::sleepUntil($unixTime);
	}

	public function processNotifications() : void{
		$startTime = hrtime(true);
		$this->timings->startTiming();
		try{
			parent::processNotifications();
		}finally{
			$this->notificationProcessingTimeNs += hrtime(true) - $startTime;
			$this->timings->stopTiming();
		}
	}
}
