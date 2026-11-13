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

namespace pocketmine\world\generator\vanilla\math;

/**
 * Single precision helpers. PHP only has doubles, so float32 results are emulated by rounding.
 */
final class FloatMath{

	private function __construct(){
		//NOOP
	}

	/**
	 * Rounds a double to the nearest float32 value (ties to even), like Java's (float) cast or JS Math.fround().
	 * Uses Veltkamp splitting (2^29 + 1), which is exact for every finite value in the normal float32 range and much
	 * faster than pack()/unpack(). Code generators inline the same expression.
	 */
	public static function fround(float $x) : float{
		$c = $x * 536870913.0;
		return $c - ($c - $x);
	}

	/** float32 lerp: fround(b + fround(a * fround(c - b))) */
	public static function lerp(float $a, float $b, float $c) : float{
		$d = $c - $b;
		$t = $d * 536870913.0;
		$d = $t - ($t - $d);
		$d = $a * $d;
		$t = $d * 536870913.0;
		$d = $t - ($t - $d);
		$d = $b + $d;
		$t = $d * 536870913.0;
		return $t - ($t - $d);
	}
}
