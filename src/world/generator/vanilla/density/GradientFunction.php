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

use function floor;
use function fmod;
use function max;
use function min;

/**
 * "minecraft:gradient": clamped linear ramp along one axis between two coordinates, optionally repeating.
 */
final class GradientFunction extends DensityFunction{
	public const AXIS_X = 0;
	public const AXIS_Y = 1;
	public const AXIS_Z = 2;
	public const TILING_CLAMP = 0;
	public const TILING_REPEAT = 1;
	public const TILING_MIRRORED = 2;

	/**
	 * @phpstan-param self::AXIS_* $axis
	 * @phpstan-param self::TILING_* $tiling
	 */
	public function __construct(
		public readonly int $axis,
		public readonly int $tiling,
		public readonly int $fromCoordinate,
		public readonly int $toCoordinate,
		public readonly float $fromValue,
		public readonly float $toValue
	){
		parent::__construct(min($fromValue, $toValue), max($fromValue, $toValue));
	}

	public function compute(float $x, float $y, float $z) : float{
		$range = $this->toCoordinate - $this->fromCoordinate;
		$c = ($this->axis === self::AXIS_X ? $x : ($this->axis === self::AXIS_Y ? $y : $z)) - $this->fromCoordinate;
		if($this->tiling === self::TILING_REPEAT){
			$c = fmod(fmod($c, $range) + $range, $range);
		}elseif($this->tiling === self::TILING_MIRRORED){
			$tile = floor($c / $range);
			$local = $c - $tile * $range;
			$c = (((int) $tile) & 1) === 0 ? $local : $range - $local;
		}
		$a = $c / $range;
		if($a < 0){
			return $this->fromValue;
		}
		if($a > 1){
			return $this->toValue;
		}
		return $this->fromValue + $a * ($this->toValue - $this->fromValue);
	}

	public function children() : array{
		return [];
	}

	public function dependsOnY() : bool{
		return $this->axis === self::AXIS_Y;
	}
}
