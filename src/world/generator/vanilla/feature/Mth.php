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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\generator\vanilla\math\FloatMath;
use function sin;

/**
 * Vanilla's Mth helpers whose exact results matter for worldgen (the float sine table, float lerp).
 */
final class Mth{
	/** 3.1415927F */
	public const PI_F = 3.1415927410125732;

	/** @var float[]|null */
	private static ?array $sin = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @return float[]
	 */
	private static function table() : array{
		if(self::$sin === null){
			$table = [];
			for($i = 0; $i < 65536; ++$i){
				$table[] = FloatMath::fround(sin($i / 10430.378350470453));
			}
			self::$sin = $table;
		}
		return self::$sin;
	}

	/** Mth.sin(double): float from the 65536-entry table */
	public static function sin(float $x) : float{
		return self::table()[((int) ($x * 10430.378350470453)) & 65535];
	}

	/** Mth.cos(double) */
	public static function cos(float $x) : float{
		return self::table()[((int) ($x * 10430.378350470453 + 16384.0)) & 65535];
	}

	/** Mth.lerp(float alpha, float a, float b) in float32 */
	public static function lerpF(float $alpha, float $a, float $b) : float{
		return FloatMath::fround($a + FloatMath::fround($alpha * FloatMath::fround($b - $a)));
	}
}
