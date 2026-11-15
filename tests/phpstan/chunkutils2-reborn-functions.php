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

//Functions added by the chunkutils2 extension of PocketMine-MP Reborn (see chunkutils2-reborn.php). PocketMine uses
//them only when they exist (function_exists()), so they are declared here for static analysis with PHP builds that
//don't have them. Scanned by PHPStan only (phpstan.neon.dist scanFiles), never loaded.

/**
 * chunkutils2 >= 0.3.9: the CPU time stamp counter, a cheap monotonic counter for interval timing (see
 * TimingsHandler::now()).
 */
function chunkutils2_tsc() : int{ return 0; }

/**
 * chunkutils2 >= 0.3.13, tests only: false makes the SIMD helpers use their scalar code, true restores CPU detection.
 */
function chunkutils2_set_simd_enabled(bool $enabled) : void{}

/**
 * chunkutils2 >= 0.3.13, tests only: whether the SIMD helpers use SIMD code now.
 */
function chunkutils2_simd_available() : bool{ return false; }

/**
 * chunkutils2 >= 0.3.13, tests only: true makes PalettedBlockArray::collectGarbage() use its original repacking.
 */
function chunkutils2_set_legacy_gc(bool $enabled) : void{}

/**
 * chunkutils2 >= 0.3.15: sleeps with sub-millisecond precision (Windows: high resolution waitable timer, which leaves
 * the system-wide timer resolution alone). False when no precise timer is available. Not interruptible.
 */
function chunkutils2_precise_sleep(int $nanoseconds) : bool{ return false; }
