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

namespace pocketmine\entity\monster;

use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\item\Durable;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * A hostile mob. Removed in peaceful difficulty (unless {@link Monster::despawnsInPeaceful()} says otherwise); when
 * not persistent (naturally spawned and not named) it despawns far from players like other mobs (see
 * {@link \pocketmine\world\mobs\MobDespawner}).
 */
abstract class Monster extends Mob{
	public const DESPAWN_INSTANT_DISTANCE = 128;
	public const DESPAWN_RANDOM_DISTANCE = 32;

	/**
	 * Whether the mob catches fire in sunlight (zombies, skeletons).
	 */
	public function burnsInDaylight() : bool{
		return false;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	/**
	 * Whether the mob is removed in peaceful difficulty. Mobs whose spawn rules allow peaceful (Bedrock
	 * difficulty_filter min peaceful: piglins, piglin brutes) stay, harmless (see {@link Mob::scaleDamageForDifficulty()}).
	 */
	protected function despawnsInPeaceful() : bool{
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$phase = ($this->getAiTick() + $this->id) % 20;
		if($phase === 0){
			if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL && $this->despawnsInPeaceful()){
				$this->flagForDespawn();
				return false;
			}
		}
		if($phase % 5 === 0 && $this->burnsInDaylight() && !$this->isOnFire() && mt_rand(0, 4) === 0 && $this->isInDaylight() && !self::blockSunWithHelmet($this)){
			$this->setOnFire(8);
		}
		return $hasUpdate;
	}

	/**
	 * Vanilla burns_in_daylight protection_slot (default the head): an item on the head (a helmet, a carved pumpkin, a
	 * mob head) keeps the mob from catching fire in the sun; a helmet loses 0-1 durability instead each time and is
	 * gone once broken (Java Mob.burnUndead). Returns whether the head item blocked the sun.
	 */
	public static function blockSunWithHelmet(Living $entity) : bool{
		$inventory = $entity->getArmorInventory();
		$helmet = $inventory->getHelmet();
		if($helmet->isNull()){
			return false;
		}
		if($helmet instanceof Durable && mt_rand(0, 1) === 1){
			$helmet->applyDamage(1);
			if($helmet->isBroken()){
				$entity->broadcastSound(new ItemBreakSound());
			}
			$inventory->setHelmet($helmet);
		}
		return true;
	}

	/**
	 * Returns whether the mob is exposed to the sun (daytime, sky directly above its head, not in water or rain).
	 */
	public function isInDaylight() : bool{
		return self::isEntityInDaylight($this);
	}

	/**
	 * Returns whether the mob is exposed to the sun (see {@link Monster::isInDaylight()}), for daylight burning mobs
	 * that are not monsters (zombie horses).
	 */
	public static function isEntityInDaylight(Mob $entity) : bool{
		$world = $entity->getWorld();
		if($world->getDimension() !== Dimension::OVERWORLD || $world->getSkyLightReduction() >= 4 || $entity->isInWater() || $entity->isUnderwater()){
			return false;
		}
		$location = $entity->getLocation();
		$x = (int) floor($location->x);
		$y = (int) floor($location->y + $entity->getEyeHeight());
		$z = (int) floor($location->z);
		//undead don't catch fire in the rain (vanilla)
		return $world->getPotentialBlockSkyLightAt($x, $y, $z) >= 15 && !$entity->isInRain();
	}
}
