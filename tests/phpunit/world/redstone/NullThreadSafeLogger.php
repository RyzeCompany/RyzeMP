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

namespace pocketmine\world\redstone;

use pocketmine\thread\log\AttachableThreadSafeLogger;

/**
 * Logger which discards everything, for tests which need a server logger.
 */
final class NullThreadSafeLogger extends AttachableThreadSafeLogger{
	public function emergency($message){}

	public function alert($message){}

	public function critical($message){}

	public function error($message){}

	public function warning($message){}

	public function notice($message){}

	public function info($message){}

	public function debug($message){}

	public function log($level, $message){}

	public function logException(\Throwable $e, $trace = null){}
}
