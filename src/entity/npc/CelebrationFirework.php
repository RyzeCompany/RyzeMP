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

namespace pocketmine\entity\npc;

use pocketmine\entity\animation\FireworkParticlesAnimation;
use pocketmine\entity\object\FireworkRocket;
use pocketmine\world\sound\FireworkCrackleSound;
use function count;

/**
 * A firework launched by a villager celebrating a won raid (see {@link VillagerV2::launchFirework()}): it explodes
 * like any firework but hurts nobody (in Bedrock only fireworks shot from a crossbow deal damage).
 *
 * @internal
 */
final class CelebrationFirework extends FireworkRocket{

	/**
	 * Not saved: a firework in flight when its chunk unloads would be stored without a save ID (fireworks are never
	 * saved with chunks) and dropped as an unknown entity when the chunk loads again.
	 */
	public function canSaveWithChunk() : bool{
		return false;
	}

	public function explode() : void{
		$explosions = $this->getExplosions();
		if(count($explosions) === 0){
			return;
		}
		$this->broadcastAnimation(new FireworkParticlesAnimation($this));
		foreach($explosions as $explosion){
			$this->broadcastSound($explosion->getType()->getExplosionSound());
			if($explosion->willTwinkle()){
				$this->broadcastSound(new FireworkCrackleSound());
			}
		}
	}
}
