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

use pocketmine\world\generator\vanilla\math\FloatMath;
use function max;
use function min;
use const INF;

/**
 * "minecraft:lerp": first + alpha * (second - first), in single precision unless alpha is exactly 0 or 1.
 */
final class LerpFunction extends DensityFunction{

	public function __construct(
		public readonly DensityFunction $alpha,
		public readonly DensityFunction $first,
		public readonly DensityFunction $second
	){
		$lo = min($first->minValue, $second->minValue);
		$hi = max($first->maxValue, $second->maxValue);
		if($alpha->minValue < 0 || $alpha->maxValue > 1){
			$lo = -INF;
			$hi = INF;
		}
		parent::__construct($lo, $hi);
	}

	public function compute(float $x, float $y, float $z) : float{
		$a = $this->alpha->compute($x, $y, $z);
		if($a === 0.0){
			return $this->first->compute($x, $y, $z);
		}
		if($a === 1.0){
			return $this->second->compute($x, $y, $z);
		}
		return FloatMath::lerp($a, $this->first->compute($x, $y, $z), $this->second->compute($x, $y, $z));
	}

	public function children() : array{
		return [$this->alpha, $this->first, $this->second];
	}
}
