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

namespace pocketmine\entity\projectile;

use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\player\Player;

/**
 * Trident thrown by a drowned: like a player's trident (8 damage) but it can't be picked up and disappears a few
 * seconds after landing (vanilla mob tridents are not pickable).
 */
class MobTrident extends Trident implements NeverSavedWithChunkEntity{
	private const DESPAWN_AFTER_LANDING_TICKS = 100;

	private int $landedTicks = 0;

	public function onCollideWithPlayer(Player $player) : void{
		//not pickable
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->blockHit !== null || $this->onGround){
			$this->landedTicks += $tickDiff;
			if($this->landedTicks >= self::DESPAWN_AFTER_LANDING_TICKS){
				$this->flagForDespawn();
			}
			return true;
		}
		return $hasUpdate;
	}
}
