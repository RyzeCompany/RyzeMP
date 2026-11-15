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

namespace pocketmine\entity\ai;

use pocketmine\entity\ai\control\FlyingMoveControl;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\ai\navigation\StraightLineNavigator;
use pocketmine\math\Vector3;

/**
 * Flying mob (bat, bee, parrot, allay, happy ghast): flying move control, navigation through the air (straight while
 * the way is clear, a 3D path search around obstacles, see {@link StraightLineNavigator}; bats and happy ghasts only go
 * straight), wandering targets in the air, and no gravity or fall damage. Mobs using it must be
 * {@link \pocketmine\entity\Mob}s and call {@link FlyingMobTrait::initFlying()} from initEntity().
 */
trait FlyingMobTrait{

	/**
	 * Flying speed in blocks per tick at speed multiplier 1 (Bedrock "flying_speed").
	 */
	abstract protected function getFlyingSpeed() : float;

	protected function initFlying() : void{
		$this->setHasGravity(false);
	}

	protected function createMoveControl() : MoveControl{
		return new FlyingMoveControl($this, $this->getFlyingSpeed());
	}

	protected function createNavigation() : PathNavigator{
		return new StraightLineNavigator($this, StraightLineNavigator::MEDIUM_AIR);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return RandomPos::getAirPos($this, $horizontal, $vertical, 6, $awayFrom);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}
}
