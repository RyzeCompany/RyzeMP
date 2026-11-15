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

use pocketmine\math\Vector3;
use function abs;
use function count;

/**
 * Searches outwards from a position for the closest column where a predicate matches, ring by ring (like Java
 * Edition's BiomeSource.findClosestBiome3d): rings of growing radius in steps of $horizontalStep blocks, and in each
 * column the heights starting at the origin's Y and alternating outwards in steps of $verticalStep.
 */
final class BiomeSearch{
	/** Default search radius of /locate biome, in blocks (Java Edition uses the same). */
	public const DEFAULT_RADIUS = 6400;
	public const DEFAULT_HORIZONTAL_STEP = 32;
	public const DEFAULT_VERTICAL_STEP = 64;

	private function __construct(){
		//NOOP
	}

	/**
	 * Heights sampled in each column: the origin's Y, then +step, -step, +2*step... within [minY, maxY].
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function sampleHeights(int $originY, int $minY, int $maxY, int $verticalStep) : array{
		$originY = $originY < $minY ? $minY : ($originY > $maxY ? $maxY : $originY);
		$ys = [$originY];
		for($offset = $verticalStep; $originY + $offset <= $maxY || $originY - $offset >= $minY; $offset += $verticalStep){
			if($originY + $offset <= $maxY){
				$ys[] = $originY + $offset;
			}
			if($originY - $offset >= $minY){
				$ys[] = $originY - $offset;
			}
		}
		return $ys;
	}

	/**
	 * @param \Closure $matchColumn returns the matching height of a column among the given heights, or null
	 * @phpstan-param \Closure(int $x, int $z, list<int> $ys) : ?int $matchColumn
	 * @param int[] $ys heights to sample (see {@link sampleHeights()})
	 * @phpstan-param list<int> $ys
	 *
	 * @param int|null $deadlineNs hrtime() value (nanoseconds) after which the search gives up
	 *
	 * @return Vector3|null the first matching position found (in the closest ring), or null if none within the radius
	 * @throws LocateTimeoutException if the deadline passes
	 */
	public static function find(\Closure $matchColumn, int $originX, int $originZ, array $ys, int $radius = self::DEFAULT_RADIUS, int $horizontalStep = self::DEFAULT_HORIZONTAL_STEP, ?int $deadlineNs = null) : ?Vector3{
		if(count($ys) === 0 || $horizontalStep <= 0){
			return null;
		}
		$columns = 0;
		for($ring = 0; $ring <= $radius; $ring += $horizontalStep){
			for($dz = -$ring; $dz <= $ring; $dz += $horizontalStep){
				//rows at the ring's edge are sampled completely, the other rows only at both ends
				$dxStep = abs($dz) === $ring ? $horizontalStep : 2 * $ring;
				for($dx = -$ring; $dx <= $ring; $dx += $dxStep){
					if((++$columns & 63) === 0){
						LocateTimeoutException::checkDeadline($deadlineNs);
					}
					$x = $originX + $dx;
					$z = $originZ + $dz;
					$y = $matchColumn($x, $z, $ys);
					if($y !== null){
						return new Vector3($x, $y, $z);
					}
				}
			}
		}
		return null;
	}
}
