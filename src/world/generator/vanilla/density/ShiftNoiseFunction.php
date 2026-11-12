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

use pocketmine\world\generator\vanilla\noise\NoiseStack;

/**
 * "minecraft:shift_a" (x, 0, z), "minecraft:shift_b" (z, x, 0) and "minecraft:shift" (x, y, z):
 * 4 * noise(coordinates / 4).
 */
final class ShiftNoiseFunction extends DensityFunction{
	public const MODE_A = 0;
	public const MODE_B = 1;
	public const MODE_XYZ = 2;

	/**
	 * @phpstan-param self::MODE_* $mode
	 */
	public function __construct(
		public readonly int $mode,
		public readonly string $noiseName,
		public readonly NoiseStack $noise
	){
		parent::__construct(-4 * $noise->maxValue, 4 * $noise->maxValue);
	}

	public function compute(float $x, float $y, float $z) : float{
		return match($this->mode){
			self::MODE_A => $this->noise->get3D($x * 0.25, 0.0, $z * 0.25) * 4,
			self::MODE_B => $this->noise->get3D($z * 0.25, $x * 0.25, 0.0) * 4,
			self::MODE_XYZ => $this->noise->get3D($x * 0.25, $y * 0.25, $z * 0.25) * 4
		};
	}

	public function children() : array{
		return [];
	}

	public function dependsOnY() : bool{
		return $this->mode === self::MODE_XYZ;
	}
}
