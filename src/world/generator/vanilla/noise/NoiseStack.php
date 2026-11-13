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

use function count;
use function floor;

/**
 * Sum of noise octaves: value = sum(amplitude[i] * octave[i].sample(x * frequency[i], ...)).
 */
final class NoiseStack{
	/**
	 * @var ImprovedNoise[]
	 * @phpstan-var list<ImprovedNoise>
	 */
	public array $octaves = [];
	/**
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public array $frequencies = [];
	/**
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public array $amplitudes = [];
	/** Upper bound of |value|, used by the density function range analysis. */
	public float $maxValue = 0.0;

	public function addLayer(ImprovedNoise $noise, float $frequency, float $amplitude) : void{
		$this->octaves[] = $noise;
		$this->frequencies[] = $frequency;
		$this->amplitudes[] = $amplitude;
		$this->maxValue += 2.0 * ($amplitude < 0 ? -$amplitude : $amplitude);
	}

	public function count() : int{
		return count($this->octaves);
	}

	public function get3D(float $x, float $y, float $z) : float{
		$value = 0.0;
		foreach($this->octaves as $i => $o){
			$f = $this->frequencies[$i];
			$sx = $x * $f;
			$sy = $y * $f;
			$sz = $z * $f;
			$value += $this->amplitudes[$i] * ImprovedNoise::sampleRaw($o->p, $o->gx, $o->gy, $o->gz,
				$sx - floor($sx / 33554432.0 + 0.5) * 33554432.0 + $o->xo,
				$sy - floor($sy / 33554432.0 + 0.5) * 33554432.0 + $o->yo,
				$sz - floor($sz / 33554432.0 + 0.5) * 33554432.0 + $o->zo
			);
		}
		return $value;
	}
}
