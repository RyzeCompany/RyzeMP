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
use function max;

/**
 * "minecraft:find_top_surface": highest y (stepping down by cellHeight from upperBound) where density > 0.
 */
final class FindTopSurfaceFunction extends DensityFunction{

	public function __construct(
		public readonly DensityFunction $density,
		public readonly DensityFunction $upperBound,
		public readonly int $lowerBound,
		public readonly int $cellHeight
	){
		parent::__construct($lowerBound, max($lowerBound, $upperBound->maxValue));
	}

	public function compute(float $x, float $y, float $z) : float{
		$topY = (int) (floor($this->upperBound->compute($x, $y, $z) / $this->cellHeight) * $this->cellHeight);
		if($topY < $this->lowerBound){
			return $this->lowerBound;
		}
		for($blockY = $topY; $blockY >= $this->lowerBound; $blockY -= $this->cellHeight){
			if($this->density->compute($x, $blockY, $z) > 0){
				return $blockY;
			}
		}
		return $this->lowerBound;
	}

	public function children() : array{
		return [$this->density, $this->upperBound];
	}

	public function dependsOnY() : bool{
		return $this->upperBound->dependsOnY();
	}
}
