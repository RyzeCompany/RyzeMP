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

namespace pocketmine\entity\riding;

use pocketmine\math\Vector3;

/**
 * A seat of a rideable entity, from the "seats" of the vanilla minecraft:rideable component: the position of the
 * passenger relative to the vehicle (x to the vehicle's left, y up, z forwards, before rotating by the vehicle's yaw),
 * and the optional camera settings sent to riding players.
 */
final class RideableSeat{

	public function __construct(
		public readonly Vector3 $position,
		public readonly ?float $lockRiderRotation = null,
		public readonly ?float $thirdPersonCameraRadius = null,
		public readonly ?float $cameraRelaxDistanceSmoothing = null,
		/** Rotation of the passenger relative to the vehicle, in degrees (vanilla rotate_rider_by, e.g. -90 in boats). */
		public readonly ?float $riderRotationOffset = null
	){}

	public static function at(float $x, float $y, float $z) : self{
		return new self(new Vector3($x, $y, $z));
	}
}
