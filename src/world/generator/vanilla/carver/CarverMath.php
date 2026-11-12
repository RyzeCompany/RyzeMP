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

namespace pocketmine\world\generator\vanilla\carver;

use function sin;
use const M_PI;

/**
 * Single precision and lookup-table math used by the carvers, with the results of Minecraft's Mth: sin/cos come from
 * a 65536-entry float table indexed by a double scaled angle, and every float operation of the Java code is rounded to
 * float32 with {@link f()} (PHP only has doubles; a double result of one +, -, * or / of two float32 values rounds to
 * the same float32 value as the float operation).
 */
final class CarverMath{
	public const PI = 3.1415927410125732; //(float) Math.PI
	public const TWO_PI = 6.2831854820251465; //(float) (Math.PI * 2)
	public const HALF_PI = 1.5707963705062866; //(float) (Math.PI / 2)

	private const SIN_SCALE = 10430.378350470453;

	/**
	 * @var float[]|null
	 * @phpstan-var array<int, float>|null
	 */
	private static ?array $sin = null;

	private function __construct(){
		//NOOP
	}

	/** Rounds to the nearest float32 (Veltkamp splitting, like FloatMath::fround()). */
	public static function f(float $x) : float{
		$c = $x * 536870913.0;
		return $c - ($c - $x);
	}

	/**
	 * @return float[] Mth.SIN: (float) Math.sin(i * PI * 2 / 65536)
	 * @phpstan-return array<int, float>
	 */
	public static function sinTable() : array{
		if(self::$sin === null){
			$table = [];
			for($i = 0; $i < 65536; ++$i){
				$c = sin($i * M_PI * 2.0 / 65536.0);
				$t = $c * 536870913.0;
				$table[] = $t - ($t - $c);
			}
			self::$sin = $table;
		}
		return self::$sin;
	}

	/** Mth.sin(double) */
	public static function sin(float $value) : float{
		return (self::$sin ?? self::sinTable())[((int) ($value * self::SIN_SCALE)) & 65535];
	}

	/** Mth.cos(double) */
	public static function cos(float $value) : float{
		return (self::$sin ?? self::sinTable())[((int) ($value * self::SIN_SCALE + 16384.0)) & 65535];
	}

	/** Mth.floor(double) */
	public static function floor(float $value) : int{
		$i = (int) $value;
		return $value < $i ? $i - 1 : $i;
	}
}
