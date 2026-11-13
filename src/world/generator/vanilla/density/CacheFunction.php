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

use const NAN;

/**
 * "minecraft:cache" marker. Values are pure, so this only memoizes the last position; inputs that do not depend on
 * y are memoized per column, like vanilla's flat cache.
 */
final class CacheFunction extends DensityFunction{
	private float $lastX = NAN;
	private float $lastY = NAN;
	private float $lastZ = NAN;
	private float $lastValue = 0.0;
	public readonly bool $flat;

	public function __construct(
		public readonly DensityFunction $input
	){
		parent::__construct($input->minValue, $input->maxValue);
		$this->flat = !$input->dependsOnY();
	}

	public function compute(float $x, float $y, float $z) : float{
		if($x === $this->lastX && $z === $this->lastZ && ($this->flat || $y === $this->lastY)){
			return $this->lastValue;
		}
		$this->lastX = $x;
		$this->lastY = $y;
		$this->lastZ = $z;
		return $this->lastValue = $this->input->compute($x, $y, $z);
	}

	public function children() : array{
		return [$this->input];
	}
}
