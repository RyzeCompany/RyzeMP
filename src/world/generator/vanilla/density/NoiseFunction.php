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
 * "minecraft:noise": a normal noise sampled at (x * xzScale + shiftX, y * yScale + shiftY, z * xzScale + shiftZ).
 */
final class NoiseFunction extends DensityFunction{

	public function __construct(
		public readonly string $noiseName,
		public readonly NoiseStack $noise,
		public readonly float $xzScale,
		public readonly float $yScale,
		public readonly ?DensityFunction $shiftX,
		public readonly ?DensityFunction $shiftY,
		public readonly ?DensityFunction $shiftZ
	){
		parent::__construct(-$noise->maxValue, $noise->maxValue);
	}

	public function compute(float $x, float $y, float $z) : float{
		return $this->noise->get3D(
			$x * $this->xzScale + ($this->shiftX !== null ? $this->shiftX->compute($x, $y, $z) : 0.0),
			$y * $this->yScale + ($this->shiftY !== null ? $this->shiftY->compute($x, $y, $z) : 0.0),
			$z * $this->xzScale + ($this->shiftZ !== null ? $this->shiftZ->compute($x, $y, $z) : 0.0)
		);
	}

	public function children() : array{
		$result = [];
		foreach([$this->shiftX, $this->shiftY, $this->shiftZ] as $shift){
			if($shift !== null){
				$result[] = $shift;
			}
		}
		return $result;
	}

	public function dependsOnY() : bool{
		return $this->yScale !== 0.0 || parent::dependsOnY();
	}
}
