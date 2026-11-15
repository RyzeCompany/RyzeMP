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

namespace pocketmine\plugin\faulty;

use pocketmine\event\fixtures\TestChildEvent;

/**
 * Closures declared in the faulty plugin's files, which throw.
 */
final class FaultyCode{

	/**
	 * @phpstan-return \Closure() : void
	 */
	public static function noArgs() : \Closure{
		return static function() : void{
			throw new \RuntimeException("plugin failure");
		};
	}

	/**
	 * @phpstan-return \Closure(mixed) : void
	 */
	public static function oneArg() : \Closure{
		return static function(mixed $value) : void{
			throw new \RuntimeException("plugin failure");
		};
	}

	/**
	 * @phpstan-return \Closure(TestChildEvent) : void
	 */
	public static function eventHandler() : \Closure{
		return static function(TestChildEvent $event) : void{
			throw new \RuntimeException("plugin failure");
		};
	}

	/**
	 * Calls the given core closure from plugin code (the plugin is on the stack, but not at the top).
	 *
	 * @phpstan-param \Closure() : void $core
	 */
	public static function callThrough(\Closure $core) : void{
		$core();
	}
}
