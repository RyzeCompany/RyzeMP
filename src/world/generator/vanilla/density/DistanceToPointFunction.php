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

namespace pocketmine\world\generator\vanilla\density;

use function abs;
use function max;
use function sqrt;
use const INF;

/**
 * "minecraft:distance_to_point": distance from the block to a fixed point.
 */
final class DistanceToPointFunction extends DensityFunction{
	public const METRIC_EUCLIDEAN = 0;
	public const METRIC_EUCLIDEAN_SQUARED = 1;
	public const METRIC_MANHATTAN = 2;
	public const METRIC_CHEBYSHEV = 3;

	/**
	 * @phpstan-param self::METRIC_* $metric
	 */
	public function __construct(
		public readonly float $pointX,
		public readonly float $pointY,
		public readonly float $pointZ,
		public readonly int $metric
	){
		parent::__construct(0.0, INF);
	}

	public function compute(float $x, float $y, float $z) : float{
		$dx = $this->pointX - $x;
		$dy = $this->pointY - $y;
		$dz = $this->pointZ - $z;
		return match($this->metric){
			self::METRIC_EUCLIDEAN => sqrt($dx * $dx + $dy * $dy + $dz * $dz),
			self::METRIC_EUCLIDEAN_SQUARED => $dx * $dx + $dy * $dy + $dz * $dz,
			self::METRIC_MANHATTAN => abs($dx) + abs($dy) + abs($dz),
			self::METRIC_CHEBYSHEV => max(abs($dx), abs($dy), abs($dz)),
		};
	}

	public function children() : array{
		return [];
	}

	public function dependsOnY() : bool{
		return true;
	}
}
