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

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\player\Player;

/**
 * Slow Falling (vanilla): falling entities only accelerate by 0.01 blocks/tick² instead of their normal gravity, and
 * take no fall damage. Players move themselves on the client, so only their fall damage is handled here.
 */
class SlowFallingEffect extends Effect{
	public const SLOW_GRAVITY = 0.01;

	public function getApplyInterval(EffectInstance $instance) : int{
		return 1;
	}

	public function applyEffect(Living $entity, EffectInstance $instance, float $potency = 1.0, ?Entity $source = null) : void{
		$entity->resetFallDistance();
		if(!$entity instanceof Player && $entity->getMotion()->y < 0 && $entity->getGravity() > self::SLOW_GRAVITY){
			$entity->addMotion(0, $entity->getGravity() - self::SLOW_GRAVITY, 0);
		}
	}
}
