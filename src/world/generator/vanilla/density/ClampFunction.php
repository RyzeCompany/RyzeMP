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

use function max;
use function min;

/** "minecraft:clamp" */
final class ClampFunction extends DensityFunction{

	public function __construct(
		public readonly DensityFunction $input,
		public readonly float $min,
		public readonly float $max
	){
		parent::__construct(max($min, min($max, $input->minValue)), max($min, min($max, $input->maxValue)));
	}

	public function compute(float $x, float $y, float $z) : float{
		$v = $this->input->compute($x, $y, $z);
		return $v < $this->min ? $this->min : ($v > $this->max ? $this->max : $v);
	}

	public function children() : array{
		return [$this->input];
	}
}
