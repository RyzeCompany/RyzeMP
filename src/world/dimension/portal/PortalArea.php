<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use InvalidArgumentException;
use function abs;
use function str_contains;
use function trim;

/** An inclusive cuboid of portal blocks, identified by a stable world folder name. */
final class PortalArea{

	/** Corners must be ordered; include all active portal blocks, not just one touched block. */
	public function __construct(
		public readonly string $worldName,
		public readonly PortalType $type,
		public readonly int $minX,
		public readonly int $minY,
		public readonly int $minZ,
		public readonly int $maxX,
		public readonly int $maxY,
		public readonly int $maxZ
	){
		self::validateWorldName($worldName);
		if($minX > $maxX || $minY > $maxY || $minZ > $maxZ){
			throw new InvalidArgumentException("Portal area corners must be ordered from minimum to maximum");
		}
		if(abs($minX) > 29999872 || abs($maxX) > 29999872 || abs($minZ) > 29999872 || abs($maxZ) > 29999872 || $minY < -64 || $maxY >= 320){
			throw new InvalidArgumentException("Portal area is outside the supported world bounds");
		}
	}

	/** @internal Shared validation for stable world references; does not load the world. */
	public static function validateWorldName(string $name) : void{
		if(trim($name) === "" || $name === "." || $name === ".." || str_contains($name, "/") || str_contains($name, "\\") || str_contains($name, "\0")){
			throw new InvalidArgumentException("A portal world must be a folder name without a path");
		}
	}

	public function contains(string $worldName, PortalType $type, int $x, int $y, int $z) : bool{
		return $this->worldName === $worldName && $this->type === $type &&
			$x >= $this->minX && $x <= $this->maxX && $y >= $this->minY && $y <= $this->maxY && $z >= $this->minZ && $z <= $this->maxZ;
	}

	public function overlaps(self $other) : bool{
		return $this->worldName === $other->worldName && $this->type === $other->type &&
			$this->minX <= $other->maxX && $this->maxX >= $other->minX &&
			$this->minY <= $other->maxY && $this->maxY >= $other->minY &&
			$this->minZ <= $other->maxZ && $this->maxZ >= $other->minZ;
	}
}
