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

namespace pocketmine\scheduler;

use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginFaultHandler;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;

/**
 * @template TTask of Task
 */
class TaskHandler{
	protected int $nextRun;

	protected bool $cancelled = false;

	private TimingsHandler $timings;

	private string $taskName;
	private string $ownerName;

	/**
	 * @phpstan-param TTask $task
	 */
	public function __construct(
		protected Task $task,
		protected int $delay = -1,
		protected int $period = -1,
		?string $ownerName = null,
		private ?Plugin $ownerPlugin = null
	){
		if($task->getHandler() !== null){
			throw new \InvalidArgumentException("Cannot assign multiple handlers to the same task");
		}
		$this->taskName = $task->getName();
		$this->ownerName = $ownerName ?? "Unknown";
		$this->timings = Timings::getScheduledTaskTimings($this, $period);
		$this->task->setHandler($this);
	}

	public function isCancelled() : bool{
		return $this->cancelled;
	}

	public function getNextRun() : int{
		return $this->nextRun;
	}

	/**
	 * @internal
	 */
	public function setNextRun(int $ticks) : void{
		$this->nextRun = $ticks;
	}

	/**
	 * @phpstan-return TTask
	 */
	public function getTask() : Task{
		return $this->task;
	}

	public function getDelay() : int{
		return $this->delay;
	}

	public function isDelayed() : bool{
		return $this->delay > 0;
	}

	public function isRepeating() : bool{
		return $this->period > 0;
	}

	public function getPeriod() : int{
		return $this->period;
	}

	public function cancel() : void{
		try{
			if(!$this->isCancelled()){
				$this->task->onCancel();
			}
		}finally{
			$this->remove();
		}
	}

	/**
	 * @internal
	 */
	public function remove() : void{
		$this->cancelled = true;
		$this->task->setHandler(null);
	}

	/**
	 * @internal
	 */
	public function run() : void{
		if($this->ownerPlugin !== null && PluginFaultHandler::isPluginPendingDisable($this->ownerPlugin)){
			//the owner failed earlier and is about to be disabled (which cancels its tasks) - don't run its code any more
			return;
		}
		$this->timings->startTiming();
		try{
			$this->task->onRun();
		}catch(CancelTaskException $e){
			$this->cancel();
		}catch(\Throwable $e){
			//rethrown if it isn't a plugin's fault (or if the server is configured to crash on plugin errors)
			PluginFaultHandler::isolate($e, $this->ownerPlugin, "task " . $this->taskName . ($this->isRepeating() ? " (repeating, now cancelled)" : ""), $this->task);
			//a failing task is cancelled: a repeating task would most likely just fail again
			try{
				$this->cancel();
			}catch(\Throwable $e){
				PluginFaultHandler::isolate($e, $this->ownerPlugin, "onCancel() of task " . $this->taskName, $this->task);
			}
		}finally{
			$this->timings->stopTiming();
		}
	}

	/**
	 * Returns the plugin owning the scheduler this task was scheduled on, if known.
	 */
	public function getOwnerPlugin() : ?Plugin{
		return $this->ownerPlugin;
	}

	public function getTaskName() : string{
		return $this->taskName;
	}

	public function getOwnerName() : string{
		return $this->ownerName;
	}
}
