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

/**
 * The resource profile of this server (main thread), set once at startup from server.properties. Kept here rather
 * than read from the Server, so that worlds and the memory manager built around a mocked Server (tests of the server
 * and of plugins) work: a mock cannot make up an enum value. Plugins use {@link Server::getResourceProfile()}.
 *
 * @internal
 */
final class ActiveResourceProfile{
	private static ResourceProfile $profile = ResourceProfile::PERFORMANCE;

	private function __construct(){
		//NOOP
	}

	public static function get() : ResourceProfile{
		return self::$profile;
	}

	public static function set(ResourceProfile $profile) : void{
		self::$profile = $profile;
	}
}
