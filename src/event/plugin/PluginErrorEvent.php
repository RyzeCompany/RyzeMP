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

namespace pocketmine\event\plugin;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\plugin\Plugin;

/**
 * Called when code belonging to a plugin throws an exception or error that the plugin did not catch, and the server
 * isolated it instead of crashing (see {@link \pocketmine\plugin\PluginFaultHandler}).
 *
 * This is intended for monitoring and error-reporting plugins.
 *
 * Cancelling the event suppresses the server's default error log for this failure. The failure still counts towards
 * the plugin's error limit, so a plugin that keeps failing will be disabled regardless.
 *
 * This event is never called for failures of handlers of this event itself, to avoid infinite recursion.
 */
class PluginErrorEvent extends PluginEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Plugin $plugin,
		private \Throwable $throwable,
		private string $context,
		private int $recentErrorCount,
		private bool $pluginWillBeDisabled
	){
		parent::__construct($plugin);
	}

	/**
	 * Returns the exception or error that was thrown by the plugin.
	 */
	public function getThrowable() : \Throwable{
		return $this->throwable;
	}

	/**
	 * Returns a human-readable description of the entry point in which the failure occurred, e.g.
	 * "event handler Foo::onJoin(PlayerJoinEvent)" or "command /foo".
	 */
	public function getContext() : string{
		return $this->context;
	}

	/**
	 * Returns the number of unhandled errors the plugin has had within the configured error window, including this one.
	 */
	public function getRecentErrorCount() : int{
		return $this->recentErrorCount;
	}

	/**
	 * Returns whether this failure made the plugin reach its error limit, which means it will be disabled right after
	 * this event.
	 */
	public function willPluginBeDisabled() : bool{
		return $this->pluginWillBeDisabled;
	}
}
