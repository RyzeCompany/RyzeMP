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
 * Node of a vanilla density function graph (worldgen/density_function), bound to a world seed.
 * compute() is the reference (interpreted) evaluation; the generated code backend produces the same values.
 */
abstract class DensityFunction{

	public function __construct(
		public readonly float $minValue,
		public readonly float $maxValue
	){}

	abstract public function compute(float $x, float $y, float $z) : float;

	/**
	 * @return DensityFunction[]
	 * @phpstan-return list<DensityFunction>
	 */
	abstract public function children() : array;

	/** Whether the value can change with the y coordinate (false = can be cached per column). */
	public function dependsOnY() : bool{
		foreach($this->children() as $child){
			if($child->dependsOnY()){
				return true;
			}
		}
		return false;
	}
}
