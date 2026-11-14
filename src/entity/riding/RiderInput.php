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

use function abs;

/**
 * Movement input of a player riding (and possibly steering) a vehicle, taken from one PlayerAuthInputPacket.
 *
 * $strafe and $forward are the move vector of the client (WASD or joystick, -1 to 1): strafe is positive to the
 * left, forward is positive forwards. Yaw and pitch are the player's look direction in degrees.
 */
final class RiderInput{

	public function __construct(
		public readonly float $strafe,
		public readonly float $forward,
		public readonly float $yaw,
		public readonly float $pitch,
		public readonly bool $jumping,
		public readonly bool $sneaking,
		public readonly bool $sprinting,
		/** Whether the client animates the left paddle of the boat it drives (PlayerAuthInput PADDLING_LEFT). */
		public readonly bool $paddlingLeft = false,
		/** Whether the client animates the right paddle of the boat it drives (PlayerAuthInput PADDLING_RIGHT). */
		public readonly bool $paddlingRight = false
	){}

	public static function none(float $yaw = 0.0, float $pitch = 0.0) : self{
		return new self(0.0, 0.0, $yaw, $pitch, false, false, false);
	}

	public function isMoving() : bool{
		return abs($this->strafe) > 0.01 || abs($this->forward) > 0.01;
	}
}
