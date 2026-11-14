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
use pocketmine\player\Player;

/**
 * Raid Omen (Bedrock "raid_omen", 1.21+): a player with Bad Omen who enters a village gets Raid Omen for 30 seconds
 * instead; when it runs out, a raid starts in that village (see {@link \pocketmine\world\raid\RaidManager}). Removing
 * it early (milk, death) cancels the raid.
 */
class RaidOmenEffect extends Effect{
	/** Duration given when Bad Omen turns into Raid Omen (30 seconds). */
	public const DURATION = 600;

	public function remove(Living $entity, EffectInstance $instance) : void{
		if($instance->hasExpired() && $entity instanceof Player && $entity->isAlive()){
			$entity->getWorld()->getRaidManager()->onRaidOmenExpired($entity, $instance->getAmplifier());
		}
	}
}
