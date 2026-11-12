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

use function count;
use function max;
use function min;
use const INF;

/**
 * "minecraft:interval_select": functions[i] for the first threshold the input is below, otherwise the last function.
 * There is one more function than thresholds (deepslate 0.27.2 truncates the function list, vanilla does not).
 */
final class IntervalSelectFunction extends DensityFunction{

	/**
	 * @param float[]           $thresholds
	 * @param DensityFunction[] $functions
	 * @phpstan-param list<float>           $thresholds
	 * @phpstan-param list<DensityFunction> $functions
	 */
	public function __construct(
		public readonly DensityFunction $input,
		public readonly array $thresholds,
		public readonly array $functions
	){
		if(count($functions) !== count($thresholds) + 1){
			throw new \InvalidArgumentException("interval_select needs exactly one more function than thresholds");
		}
		$lo = INF;
		$hi = -INF;
		foreach($functions as $f){
			$lo = min($lo, $f->minValue);
			$hi = max($hi, $f->maxValue);
		}
		parent::__construct($lo, $hi);
	}

	public function compute(float $x, float $y, float $z) : float{
		$v = $this->input->compute($x, $y, $z);
		foreach($this->thresholds as $i => $threshold){
			if($v < $threshold){
				return $this->functions[$i]->compute($x, $y, $z);
			}
		}
		return $this->functions[count($this->thresholds)]->compute($x, $y, $z);
	}

	public function children() : array{
		return [$this->input, ...$this->functions];
	}
}
