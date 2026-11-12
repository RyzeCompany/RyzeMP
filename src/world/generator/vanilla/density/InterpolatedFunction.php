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

/**
 * "minecraft:interpolated": the input is sampled at cell corners and trilinearly interpolated in between.
 * Chunk fillers evaluate the input at corners and push the interpolated value via $current before evaluating the
 * outer function; compute() falls back to interpolating on the fly (used for single-point queries).
 */
final class InterpolatedFunction extends DensityFunction{
	public float $current = 0.0;
	/** Set by chunk fillers while $current holds the value for the block being evaluated. */
	public bool $useCurrent = false;

	public function __construct(
		public readonly DensityFunction $input,
		public readonly int $cellWidth,
		public readonly int $cellHeight
	){
		parent::__construct($input->minValue, $input->maxValue);
	}

	public function compute(float $x, float $y, float $z) : float{
		if($this->useCurrent){
			return $this->current;
		}
		$w = $this->cellWidth;
		$h = $this->cellHeight;
		$bx = (int) floor($x);
		$by = (int) floor($y);
		$bz = (int) floor($z);
		$x0 = $bx - (($bx % $w + $w) % $w);
		$y0 = $by - (($by % $h + $h) % $h);
		$z0 = $bz - (($bz % $w + $w) % $w);
		$fx = ($bx - $x0) / $w;
		$fy = ($by - $y0) / $h;
		$fz = ($bz - $z0) / $w;
		$in = $this->input;
		$c000 = $in->compute($x0, $y0, $z0);
		$c100 = $in->compute($x0 + $w, $y0, $z0);
		$c010 = $in->compute($x0, $y0 + $h, $z0);
		$c110 = $in->compute($x0 + $w, $y0 + $h, $z0);
		$c001 = $in->compute($x0, $y0, $z0 + $w);
		$c101 = $in->compute($x0 + $w, $y0, $z0 + $w);
		$c011 = $in->compute($x0, $y0 + $h, $z0 + $w);
		$c111 = $in->compute($x0 + $w, $y0 + $h, $z0 + $w);
		return self::lerp3($fx, $fy, $fz, $c000, $c100, $c010, $c110, $c001, $c101, $c011, $c111);
	}

	/**
	 * Trilinear interpolation in the same order as vanilla's NoiseChunk (y first, then x, then z).
	 */
	public static function lerp3(float $fx, float $fy, float $fz, float $c000, float $c100, float $c010, float $c110, float $c001, float $c101, float $c011, float $c111) : float{
		$c00 = $c000 + $fy * ($c010 - $c000);
		$c10 = $c100 + $fy * ($c110 - $c100);
		$c01 = $c001 + $fy * ($c011 - $c001);
		$c11 = $c101 + $fy * ($c111 - $c101);
		$c0 = $c00 + $fx * ($c10 - $c00);
		$c1 = $c01 + $fx * ($c11 - $c01);
		return $c0 + $fz * ($c1 - $c0);
	}

	public function children() : array{
		return [$this->input];
	}

	public function dependsOnY() : bool{
		return true;
	}
}
