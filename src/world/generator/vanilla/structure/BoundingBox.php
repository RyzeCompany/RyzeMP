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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use function intdiv;
use function max;
use function min;

/**
 * Vanilla's BoundingBox: an integer box with inclusive bounds. Mutable like vanilla's (move() and encapsulate()
 * change the box); use copy() where vanilla creates a new box.
 */
final class BoundingBox{

	public function __construct(
		public int $minX,
		public int $minY,
		public int $minZ,
		public int $maxX,
		public int $maxY,
		public int $maxZ
	){}

	public static function fromCorners(int $x1, int $y1, int $z1, int $x2, int $y2, int $z2) : self{
		return new self(min($x1, $x2), min($y1, $y2), min($z1, $z2), max($x1, $x2), max($y1, $y2), max($z1, $z2));
	}

	/**
	 * The box of one chunk, between the given heights.
	 */
	public static function chunk(int $chunkX, int $chunkZ, int $minY, int $maxY) : self{
		return new self($chunkX << 4, $minY, $chunkZ << 4, ($chunkX << 4) + 15, $maxY, ($chunkZ << 4) + 15);
	}

	/**
	 * BoundingBox.orientBox(): a box of the given size whose foot is at (footX, footY, footZ), offset in the local
	 * frame of a piece facing the given direction.
	 */
	public static function orientBox(int $footX, int $footY, int $footZ, int $offX, int $offY, int $offZ, int $width, int $height, int $depth, int $direction) : self{
		return match($direction){
			Direction::NORTH => new self($footX + $offX, $footY + $offY, $footZ - $depth + 1 + $offZ, $footX + $width - 1 + $offX, $footY + $height - 1 + $offY, $footZ + $offZ),
			Direction::WEST => new self($footX - $depth + 1 + $offZ, $footY + $offY, $footZ + $offX, $footX + $offZ, $footY + $height - 1 + $offY, $footZ + $width - 1 + $offX),
			Direction::EAST => new self($footX + $offZ, $footY + $offY, $footZ + $offX, $footX + $depth - 1 + $offZ, $footY + $height - 1 + $offY, $footZ + $width - 1 + $offX),
			default => new self($footX + $offX, $footY + $offY, $footZ + $offZ, $footX + $width - 1 + $offX, $footY + $height - 1 + $offY, $footZ + $depth - 1 + $offZ)
		};
	}

	public function copy() : self{
		return new self($this->minX, $this->minY, $this->minZ, $this->maxX, $this->maxY, $this->maxZ);
	}

	public function intersects(BoundingBox $other) : bool{
		return $this->maxX >= $other->minX && $this->minX <= $other->maxX &&
			$this->maxZ >= $other->minZ && $this->minZ <= $other->maxZ &&
			$this->maxY >= $other->minY && $this->minY <= $other->maxY;
	}

	public function intersectsXZ(int $minX, int $minZ, int $maxX, int $maxZ) : bool{
		return $this->maxX >= $minX && $this->minX <= $maxX && $this->maxZ >= $minZ && $this->minZ <= $maxZ;
	}

	public function isInside(int $x, int $y, int $z) : bool{
		return $x >= $this->minX && $x <= $this->maxX && $z >= $this->minZ && $z <= $this->maxZ && $y >= $this->minY && $y <= $this->maxY;
	}

	/**
	 * Whether the other box is entirely inside this one.
	 */
	public function contains(BoundingBox $other) : bool{
		return $other->minX >= $this->minX && $other->maxX <= $this->maxX &&
			$other->minY >= $this->minY && $other->maxY <= $this->maxY &&
			$other->minZ >= $this->minZ && $other->maxZ <= $this->maxZ;
	}

	public function move(int $dx, int $dy, int $dz) : self{
		$this->minX += $dx;
		$this->minY += $dy;
		$this->minZ += $dz;
		$this->maxX += $dx;
		$this->maxY += $dy;
		$this->maxZ += $dz;
		return $this;
	}

	public function moved(int $dx, int $dy, int $dz) : self{
		return $this->copy()->move($dx, $dy, $dz);
	}

	public function inflatedBy(int $amount) : self{
		return new self($this->minX - $amount, $this->minY - $amount, $this->minZ - $amount, $this->maxX + $amount, $this->maxY + $amount, $this->maxZ + $amount);
	}

	public function encapsulate(BoundingBox $other) : self{
		$this->minX = min($this->minX, $other->minX);
		$this->minY = min($this->minY, $other->minY);
		$this->minZ = min($this->minZ, $other->minZ);
		$this->maxX = max($this->maxX, $other->maxX);
		$this->maxY = max($this->maxY, $other->maxY);
		$this->maxZ = max($this->maxZ, $other->maxZ);
		return $this;
	}

	public function encapsulatePoint(int $x, int $y, int $z) : self{
		$this->minX = min($this->minX, $x);
		$this->minY = min($this->minY, $y);
		$this->minZ = min($this->minZ, $z);
		$this->maxX = max($this->maxX, $x);
		$this->maxY = max($this->maxY, $y);
		$this->maxZ = max($this->maxZ, $z);
		return $this;
	}

	public function getXSpan() : int{
		return $this->maxX - $this->minX + 1;
	}

	public function getYSpan() : int{
		return $this->maxY - $this->minY + 1;
	}

	public function getZSpan() : int{
		return $this->maxZ - $this->minZ + 1;
	}

	/**
	 * BoundingBox.getCenter()
	 * @phpstan-return array{int, int, int}
	 */
	public function getCenter() : array{
		return [
			$this->minX + intdiv($this->maxX - $this->minX + 1, 2),
			$this->minY + intdiv($this->maxY - $this->minY + 1, 2),
			$this->minZ + intdiv($this->maxZ - $this->minZ + 1, 2)
		];
	}

	/**
	 * @phpstan-return array{int, int, int, int, int, int}
	 */
	public function toArray() : array{
		return [$this->minX, $this->minY, $this->minZ, $this->maxX, $this->maxY, $this->maxZ];
	}

	public function equals(BoundingBox $other) : bool{
		return $this->toArray() === $other->toArray();
	}

	public function __toString() : string{
		return "[$this->minX, $this->minY, $this->minZ -> $this->maxX, $this->maxY, $this->maxZ]";
	}
}
