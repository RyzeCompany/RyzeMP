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
use pocketmine\world\particle\WindExplosionParticle;
use pocketmine\world\sound\BreezeWindChargeBurstSound;
use pocketmine\world\WindBurst;
use function mt_rand;

/**
 * Wind Charged (https://minecraft.wiki/w/Wind_Charged): the entity bursts like a wind charge when it dies, pushing the
 * entities around it away and operating doors, levers, bells... Radius 3-4 and knockback 1.22 are the values of
 * Java's WindChargedMobEffect (the burst of a wind charge explosion, with the breeze charge burst sound and block
 * triggering), centred halfway up the dead entity.
 */
class WindChargedEffect extends Effect{
	public const KNOCKBACK_MULTIPLIER = 1.22;

	public function onEntityDeath(Living $entity, EffectInstance $instance) : void{
		$center = $entity->getPosition()->add(0, $entity->getSize()->getHeight() / 2, 0);
		WindBurst::apply($entity->getWorld(), $center, 3 + mt_rand(0, 1), self::KNOCKBACK_MULTIPLIER, true, $entity, new BreezeWindChargeBurstSound(), new WindExplosionParticle(), true);
	}
}
