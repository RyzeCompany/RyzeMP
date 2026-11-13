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

namespace pocketmine\world\generator\vanilla\structure\piece\mansion;

use function array_fill;

/**
 * vanilla's WoodlandMansionPieces.SimpleGrid: a 2D grid of ints with a value for the cells outside it.
 */
final class MansionSimpleGrid{
	/** @phpstan-var array<int, int> x * height + y => value */
	private array $grid;

	public function __construct(
		public readonly int $width,
		public readonly int $height,
		private int $valueIfOutside
	){
		$this->grid = array_fill(0, $width * $height, 0);
	}

	public function set(int $x, int $y, int $value) : void{
		if($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height){
			$this->grid[$x * $this->height + $y] = $value;
		}
	}

	public function setArea(int $x0, int $y0, int $x1, int $y1, int $value) : void{
		for($y = $y0; $y <= $y1; ++$y){
			for($x = $x0; $x <= $x1; ++$x){
				$this->set($x, $y, $value);
			}
		}
	}

	public function get(int $x, int $y) : int{
		if($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height){
			return $this->grid[$x * $this->height + $y];
		}
		return $this->valueIfOutside;
	}

	public function setIf(int $x, int $y, int $ifValue, int $value) : void{
		if($this->get($x, $y) === $ifValue){
			$this->set($x, $y, $value);
		}
	}

	public function edgesTo(int $x, int $y, int $ifValue) : bool{
		return $this->get($x - 1, $y) === $ifValue || $this->get($x + 1, $y) === $ifValue || $this->get($x, $y + 1) === $ifValue || $this->get($x, $y - 1) === $ifValue;
	}
}
