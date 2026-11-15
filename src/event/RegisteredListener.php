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

namespace pocketmine\event;

use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginFaultHandler;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Utils;
use function in_array;

/**
 * @phpstan-template TEvent of Event
 */
class RegisteredListener{
	/**
	 * @phpstan-param \Closure(TEvent) : void $handler
	 */
	public function __construct(
		private \Closure $handler,
		private int $priority,
		private Plugin $plugin,
		private bool $handleCancelled,
		private TimingsHandler $timings
	){
		if(!in_array($priority, EventPriority::ALL, true)){
			throw new \InvalidArgumentException("Invalid priority number $priority");
		}
	}

	/**
	 * @phpstan-return \Closure(TEvent) : void
	 */
	public function getHandler() : \Closure{
		return $this->handler;
	}

	public function getPlugin() : Plugin{
		return $this->plugin;
	}

	public function getPriority() : int{
		return $this->priority;
	}

	/**
	 * @phpstan-param TEvent $event
	 */
	public function callEvent(Event $event) : void{
		if($event instanceof Cancellable && $event->isCancelled() && !$this->isHandlingCancelled()){
			return;
		}
		if(PluginFaultHandler::isPluginPendingDisable($this->plugin)){
			//the plugin failed earlier and is about to be disabled - don't call it any more
			return;
		}
		$this->timings->startTiming();
		try{
			($this->handler)($event);
		}catch(\Throwable $e){
			//a buggy handler must neither crash the server nor prevent the other handlers from running; the event keeps
			//whatever state the handler left it in
			PluginFaultHandler::isolate($e, $this->plugin, "event handler " . Utils::getNiceClosureName($this->handler) . "(" . $event->getEventName() . ")");
		}finally{
			$this->timings->stopTiming();
		}
	}

	public function isHandlingCancelled() : bool{
		return $this->handleCancelled;
	}
}
