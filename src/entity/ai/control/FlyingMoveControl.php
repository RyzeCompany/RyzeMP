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

namespace pocketmine\entity\ai\control;

use pocketmine\entity\Mob;

/**
 * Move control of flying mobs (bats, bees, parrots, allays, happy ghasts): moves straight towards the wanted position
 * in three dimensions at the flying speed times the speed multiplier. Flying mobs have no gravity
 * ({@link Mob::tryChangeMovement()} applies air drag instead).
 *
 * The flying speed is in blocks per tick; Bedrock's "flying_speed" values are used as the speed at multiplier 1
 * (e.g. bee 0.15), which matches the speeds seen in game closely enough.
 */
class FlyingMoveControl extends MoveControl{
	private float $velocityY = 0.0;

	public function __construct(
		Mob $mob,
		private float $flyingSpeed
	){
		parent::__construct($mob);
	}

	public function getFlyingSpeed() : float{ return $this->flyingSpeed; }

	public function setFlyingSpeed(float $flyingSpeed) : void{ $this->flyingSpeed = $flyingSpeed; }

	/**
	 * Flying while the mob has no gravity: mobs that land (a sitting parrot, a resting bat) turn their gravity on.
	 */
	public function isThreeDimensional() : bool{
		return !$this->mob->hasGravity();
	}

	public function getVelocityY() : float{
		return $this->velocityY;
	}

	public function stop() : void{
		parent::stop();
		$this->velocityY = 0.0;
	}

	public function tick() : void{
		$this->tickThreeDimensional($this->flyingSpeed * $this->speedMultiplier, $this->velocityY);
	}
}
