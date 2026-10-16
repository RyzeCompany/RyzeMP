<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use InvalidArgumentException;
use pocketmine\entity\Location;
use function abs;
use function is_finite;

/** Exact entity feet coordinates and orientation; stores no reference to a loaded World. */
final class PortalDestination{

	/** Coordinates are not scaled or centred, and must already describe a safe arrival. */
	public function __construct(
		public readonly string $worldName,
		public readonly float $x,
		public readonly float $y,
		public readonly float $z,
		public readonly float $yaw = 0.0,
		public readonly float $pitch = 0.0
	){
		PortalArea::validateWorldName($worldName);
		foreach([$x, $y, $z, $yaw, $pitch] as $value){
			if(!is_finite($value)){
				throw new InvalidArgumentException("Portal destination coordinates and orientation must be finite");
			}
		}
		if(abs($x) > 29999872 || abs($z) > 29999872 || $y < -63 || $y > 318 || $pitch < -90 || $pitch > 90){
			throw new InvalidArgumentException("Portal destination is outside the supported coordinate or pitch bounds");
		}
	}

	public static function fromLocation(Location $location) : self{
		return new self($location->getWorld()->getFolderName(), $location->x, $location->y, $location->z, $location->yaw, $location->pitch);
	}
}
