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

/**
 * "minecraft:slice": evaluates the input with one coordinate fixed (e.g. y = 0 for a horizontal shape).
 */
final class SliceFunction extends DensityFunction{
	public const AXIS_X = 0;
	public const AXIS_Y = 1;
	public const AXIS_Z = 2;

	/**
	 * @phpstan-param self::AXIS_* $axis
	 */
	public function __construct(
		public readonly int $axis,
		public readonly int $coordinate,
		public readonly DensityFunction $input
	){
		parent::__construct($input->minValue, $input->maxValue);
	}

	public function compute(float $x, float $y, float $z) : float{
		return match($this->axis){
			self::AXIS_X => $this->input->compute((float) $this->coordinate, $y, $z),
			self::AXIS_Y => $this->input->compute($x, (float) $this->coordinate, $z),
			self::AXIS_Z => $this->input->compute($x, $y, (float) $this->coordinate),
		};
	}

	public function children() : array{
		return [$this->input];
	}

	public function dependsOnY() : bool{
		return $this->axis !== self::AXIS_Y && $this->input->dependsOnY();
	}
}
