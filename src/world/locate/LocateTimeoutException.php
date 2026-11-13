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

namespace pocketmine\world\locate;

use function hrtime;

/**
 * Thrown by a biome or structure search that ran past its deadline (see {@link checkDeadline()}).
 */
final class LocateTimeoutException extends \RuntimeException{

	/**
	 * The hrtime() deadline (nanoseconds) $seconds from now, or null (no deadline) if $seconds is 0 or less.
	 */
	public static function deadlineIn(int $seconds) : ?int{
		//hrtime(true) is an int on 64-bit PHP, which PocketMine requires
		return $seconds > 0 ? (int) hrtime(true) + $seconds * 1_000_000_000 : null;
	}

	/**
	 * Throws if the hrtime() deadline (in nanoseconds) has passed. A null deadline never expires.
	 *
	 * @throws LocateTimeoutException
	 */
	public static function checkDeadline(?int $deadlineNs) : void{
		if($deadlineNs !== null && hrtime(true) > $deadlineNs){
			throw new self("Search timed out");
		}
	}
}
