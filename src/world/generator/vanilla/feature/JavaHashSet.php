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

use function count;
use function usort;

/**
 * Iteration order of a java.util.HashSet<BlockPos> (default capacity and load factor), which some features iterate
 * while consuming the random (tree decorators): buckets in index order, insertion order inside a bucket.
 */
final class JavaHashSet{

	private function __construct(){
		//NOOP
	}

	/** Java's (int) (y + z * 31) * 31 + x, i.e. Vec3i.hashCode(). */
	public static function hash(int $x, int $y, int $z) : int{
		$h = (($y + $z * 31) * 31 + $x) & 0xffffffff;
		return $h;
	}

	/**
	 * @param int[][] $positions keyed by position key, in insertion order (already deduplicated)
	 * @phpstan-param array<int, array{int, int, int}> $positions
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int}>
	 */
	public static function iterationOrder(array $positions) : array{
		$size = count($positions);
		$capacity = 16;
		while($size > $capacity * 0.75){
			$capacity <<= 1;
		}
		$mask = $capacity - 1;
		$entries = [];
		$i = 0;
		foreach($positions as [$x, $y, $z]){
			$h = self::hash($x, $y, $z);
			$h ^= $h >> 16; //h is unsigned 32-bit here, so >> is >>>
			$entries[] = [$h & $mask, $i++, $x, $y, $z];
		}
		usort($entries, fn(array $a, array $b) : int => $a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1]);
		$out = [];
		foreach($entries as [, , $x, $y, $z]){
			$out[] = [$x, $y, $z];
		}
		return $out;
	}

	/**
	 * Packs a position into an int key (for sets/maps of positions in the decoration region).
	 */
	public static function key(int $x, int $y, int $z) : int{
		return (($x & 0x3ffffff) << 38) | (($z & 0x3ffffff) << 12) | ($y & 0xfff);
	}
}
