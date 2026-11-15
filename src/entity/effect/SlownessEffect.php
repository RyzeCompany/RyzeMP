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

namespace pocketmine\entity\effect;

use pocketmine\entity\Living;

class SlownessEffect extends Effect{

	public function add(Living $entity, EffectInstance $instance) : void{
		//vanilla -15% per level, never below 0 (level 7 and up stops the mob); removing it goes back to the base speed
		$entity->setMovementSpeedMultiplier(Living::SPEED_MODIFIER_SLOWNESS_EFFECT, 1 - 0.15 * $instance->getEffectLevel());
	}

	public function remove(Living $entity, EffectInstance $instance) : void{
		$entity->setMovementSpeedMultiplier(Living::SPEED_MODIFIER_SLOWNESS_EFFECT, 1.0);
	}
}
