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

namespace pocketmine\world\generator\vanilla\noise;

use pocketmine\world\generator\vanilla\random\RandomSource;
use function count;
use function in_array;
use function max;
use function min;

/**
 * Octaves of 2D simplex noise (Minecraft's PerlinSimplexNoise), for octave sets without positive octaves (all the
 * biome temperature noises): octave 0 at full frequency, octave -n at 1/2^n.
 */
final class PerlinSimplexNoise{
	/**
	 * Highest frequency first.
	 * @var (SimplexNoise|null)[]
	 * @phpstan-var list<SimplexNoise|null>
	 */
	private array $levels = [];
	private float $highestFreqValueFactor;

	/**
	 * @param int[] $octaves
	 * @phpstan-param non-empty-list<int> $octaves
	 */
	public function __construct(RandomSource $random, array $octaves){
		$low = -min($octaves);
		$high = max($octaves);
		if($high > 0){
			throw new \InvalidArgumentException("Positive octaves are not supported");
		}
		$count = $low + $high + 1;
		$zero = new SimplexNoise($random);
		$levels = [in_array(0, $octaves, true) ? $zero : null];
		for($i = 1; $i < $count; ++$i){
			if(in_array(-$i, $octaves, true)){
				$levels[] = new SimplexNoise($random);
			}else{
				$random->consume(262);
				$levels[] = null;
			}
		}
		$this->levels = $levels;
		$this->highestFreqValueFactor = 1.0 / (2.0 ** count($levels) - 1.0);
	}

	public function getValue(float $x, float $y) : float{
		$value = 0.0;
		$factor = 1.0;
		$valueFactor = $this->highestFreqValueFactor;
		foreach($this->levels as $noise){
			if($noise !== null){
				$value += $noise->getValue2D($x * $factor, $y * $factor) * $valueFactor;
			}
			$factor /= 2.0;
			$valueFactor *= 2.0;
		}
		return $value;
	}
}
