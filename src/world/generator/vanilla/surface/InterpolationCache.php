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

namespace pocketmine\world\generator\vanilla\surface;

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\density\InterpolatedFunction;
use function count;
use function spl_object_id;
use const INF;

/**
 * Cell corner values of "interpolated" density functions for the chunk being decorated, so that material rules
 * using them (ore veins, the preliminary surface) interpolate between corners like vanilla's NoiseChunk instead of
 * sampling the input at every block.
 */
final class InterpolationCache{
	/**
	 * Corner values per interpolated node (spl_object_id) and packed corner position.
	 * @var float[][]
	 * @phpstan-var array<int, array<int, float>>
	 */
	private array $corners = [];
	/**
	 * Last cell used per node: packed cell position followed by its 8 corner values.
	 * @var float[][]
	 * @phpstan-var array<int, array{int, float, float, float, float, float, float, float, float}>
	 */
	private array $lastCell = [];

	public function clear() : void{
		$this->corners = [];
		$this->lastCell = [];
	}

	public function corner(InterpolatedFunction $f, int $x, int $y, int $z) : float{
		//positions inside one chunk never collide with these masks
		return $this->corners[spl_object_id($f)][(($x & 0xfffff) << 32) | (($y & 0xfff) << 20) | ($z & 0xfffff)] ??= $f->input->compute($x, $y, $z);
	}

	public function value(InterpolatedFunction $f, int $x, int $y, int $z) : float{
		$w = $f->cellWidth;
		$h = $f->cellHeight;
		$x0 = $x - (($x % $w + $w) % $w);
		$y0 = $y - (($y % $h + $h) % $h);
		$z0 = $z - (($z % $w + $w) % $w);
		$id = spl_object_id($f);
		$key = (($x0 & 0xfffff) << 32) | (($y0 & 0xfff) << 20) | ($z0 & 0xfffff);
		$cell = $this->lastCell[$id] ?? null;
		if($cell === null || $cell[0] !== $key){
			$cell = $this->lastCell[$id] = [
				$key,
				$this->corner($f, $x0, $y0, $z0),
				$this->corner($f, $x0 + $w, $y0, $z0),
				$this->corner($f, $x0, $y0 + $h, $z0),
				$this->corner($f, $x0 + $w, $y0 + $h, $z0),
				$this->corner($f, $x0, $y0, $z0 + $w),
				$this->corner($f, $x0 + $w, $y0, $z0 + $w),
				$this->corner($f, $x0, $y0 + $h, $z0 + $w),
				$this->corner($f, $x0 + $w, $y0 + $h, $z0 + $w),
			];
		}
		return InterpolatedFunction::lerp3(
			($x - $x0) / $w, ($y - $y0) / $h, ($z - $z0) / $w,
			$cell[1], $cell[2], $cell[3], $cell[4], $cell[5], $cell[6], $cell[7], $cell[8]
		);
	}

	/**
	 * Range of the interpolated values inside a block box. Trilinear interpolation stays between the corners of the
	 * cells, and inside one cell its extremes are at the corners of the box.
	 * @phpstan-return array{float, float}
	 */
	public function range(InterpolatedFunction $f, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1) : array{
		$w = $f->cellWidth;
		$h = $f->cellHeight;
		$cx0 = $x0 - (($x0 % $w + $w) % $w);
		$cy0 = $y0 - (($y0 % $h + $h) % $h);
		$cz0 = $z0 - (($z0 % $w + $w) % $w);
		$min = INF;
		$max = -INF;
		if($x1 < $cx0 + $w && $y1 < $cy0 + $h && $z1 < $cz0 + $w){
			foreach([$x0, $x1] as $x){
				foreach([$y0, $y1] as $y){
					foreach([$z0, $z1] as $z){
						$v = $this->value($f, $x, $y, $z);
						if($v < $min){
							$min = $v;
						}
						if($v > $max){
							$max = $v;
						}
					}
				}
			}
		}else{
			$cx1 = $x1 - (($x1 % $w + $w) % $w) + $w;
			$cy1 = $y1 - (($y1 % $h + $h) % $h) + $h;
			$cz1 = $z1 - (($z1 % $w + $w) % $w) + $w;
			for($cx = $cx0; $cx <= $cx1; $cx += $w){
				for($cy = $cy0; $cy <= $cy1; $cy += $h){
					for($cz = $cz0; $cz <= $cz1; $cz += $w){
						$v = $this->corner($f, $cx, $cy, $cz);
						if($v < $min){
							$min = $v;
						}
						if($v > $max){
							$max = $v;
						}
					}
				}
			}
		}
		//widened by a little to cover rounding in the interpolation
		return [$min - 1e-9, $max + 1e-9];
	}

	/**
	 * Makes every interpolated node of the given graphs return its interpolated value at this block.
	 *
	 * @param InterpolatedFunction[] $nodes
	 */
	public function inject(array $nodes, int $x, int $y, int $z) : void{
		foreach($nodes as $node){
			$node->current = $this->value($node, $x, $y, $z);
			$node->useCurrent = true;
		}
	}

	/**
	 * @return InterpolatedFunction[]
	 * @phpstan-return array<int, InterpolatedFunction>
	 */
	public static function collect(DensityFunction $f) : array{
		$out = [];
		$seen = [];
		$queue = [$f];
		for($i = 0; $i < count($queue); ++$i){
			$node = $queue[$i];
			$id = spl_object_id($node);
			if(isset($seen[$id])){
				continue;
			}
			$seen[$id] = true;
			if($node instanceof InterpolatedFunction){
				$out[$id] = $node;
			}
			foreach($node->children() as $child){
				$queue[] = $child;
			}
		}
		return $out;
	}
}
