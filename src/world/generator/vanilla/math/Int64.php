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

use function ord;
use function strlen;
use const PHP_INT_MAX;

/**
 * Wrapping 64-bit integer arithmetic with Java `long` semantics.
 * PHP turns overflowing int + and * into floats, so these helpers work on 16/32-bit limbs instead.
 * They are only used when seeding random sources, never in per-block loops.
 */
final class Int64{

	private function __construct(){
		//NOOP
	}

	public static function add(int $a, int $b) : int{
		$lo = ($a & 0xffffffff) + ($b & 0xffffffff);
		$hi = (($a >> 32) & 0xffffffff) + (($b >> 32) & 0xffffffff) + ($lo >> 32);
		return (($hi & 0xffffffff) << 32) | ($lo & 0xffffffff);
	}

	public static function sub(int $a, int $b) : int{
		return self::add($a, self::add(~$b, 1));
	}

	public static function mul(int $a, int $b) : int{
		$a0 = $a & 0xffff;
		$a1 = ($a >> 16) & 0xffff;
		$a2 = ($a >> 32) & 0xffff;
		$a3 = ($a >> 48) & 0xffff;
		$b0 = $b & 0xffff;
		$b1 = ($b >> 16) & 0xffff;
		$b2 = ($b >> 32) & 0xffff;
		$b3 = ($b >> 48) & 0xffff;

		//every partial product fits in 32 bits, every column sum fits comfortably in 63 bits
		$c0 = $a0 * $b0;
		$c1 = $a1 * $b0 + $a0 * $b1 + ($c0 >> 16);
		$c2 = $a2 * $b0 + $a1 * $b1 + $a0 * $b2 + ($c1 >> 16);
		$c3 = $a3 * $b0 + $a2 * $b1 + $a1 * $b2 + $a0 * $b3 + ($c2 >> 16);

		return (($c3 & 0xffff) << 48) | (($c2 & 0xffff) << 32) | (($c1 & 0xffff) << 16) | ($c0 & 0xffff);
	}

	/** Java's `>>>` */
	public static function ushr(int $value, int $shift) : int{
		if($shift === 0){
			return $value;
		}
		return ($value >> $shift) & (PHP_INT_MAX >> ($shift - 1));
	}

	public static function rotl(int $value, int $shift) : int{
		return ($value << $shift) | self::ushr($value, 64 - $shift);
	}

	/** Java's `(int)` cast of a long. */
	public static function toInt32(int $value) : int{
		$value &= 0xffffffff;
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	/** Java's 32-bit `int * int` with overflow. */
	public static function mulInt32(int $a, int $b) : int{
		return self::toInt32(self::mul($a, $b));
	}

	/**
	 * Java's String.hashCode().
	 */
	public static function javaStringHash(string $s) : int{
		$h = 0;
		$len = strlen($s);
		for($i = 0; $i < $len; ++$i){
			$h = (31 * $h + ord($s[$i])) & 0xffffffff;
		}
		return self::toInt32($h);
	}

	/**
	 * Minecraft's positional seed hash (Mth.getSeed).
	 */
	public static function positionSeed(int $x, int $y, int $z) : int{
		$l = self::mulInt32($x, 3129871) ^ self::mul($z, 116129781) ^ $y;
		$l = self::add(self::mul(self::mul($l, $l), 42317861), self::mul($l, 11));
		return $l >> 16;
	}
}
