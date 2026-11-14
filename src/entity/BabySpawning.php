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

namespace pocketmine\entity;

use pocketmine\item\SpawnEgg;
use function mt_rand;

/**
 * Babies of mobs that aren't bred: the baby chance of natural and spawn egg spawns (Bedrock minecraft:entity_spawned,
 * see {@link Mob::getSpawnBabyChance()}) and spawn eggs used on a mob of their kind (minecraft:spawn_egg_interaction).
 */
final class BabySpawning{

	private function __construct(){
		//NOOP
	}

	/**
	 * Rolls the baby chance of a mob that was just created by the natural spawner or a spawn egg, before it is shown
	 * to players. Returns whether it became a baby.
	 */
	public static function rollSpawnBaby(Entity $entity) : bool{
		if($entity instanceof Squid){
			//squids are no mobs: squid.json / glow_squid.json entity_spawned
			return !$entity->isBaby() && mt_rand(0, 99) < Squid::SPAWN_BABY_CHANCE && $entity->makeSpawnBaby();
		}
		if(!$entity instanceof Mob || ($entity instanceof Ageable && $entity->isBaby())){
			return false;
		}
		$chance = $entity->getSpawnBabyChance();
		return $chance > 0 && mt_rand(0, 99) < $chance && $entity->makeSpawnBaby();
	}

	/**
	 * Creates (without spawning) the baby that the given spawn egg makes when it is used on the parent: a baby of the
	 * parent's kind at its position, if the egg is the parent's own and that kind has babies. Returns null otherwise.
	 */
	public static function createBabyFromEgg(Mob $parent, SpawnEgg $egg) : ?Mob{
		$typeId = $egg->getEntityTypeId();
		if($typeId === null || $typeId !== $parent::getNetworkTypeId() || !$parent->isAlive() || $parent->isClosed()){
			return null;
		}
		$baby = EntitySpawnRegistry::getInstance()->create($typeId, $parent->getLocation());
		if(!$baby instanceof Mob || !$baby->makeSpawnBaby()){
			$baby?->close();
			return null;
		}
		if($egg->hasCustomName()){
			$baby->setNameTag($egg->getCustomName());
		}
		$baby->setSpawnReason(SpawnReason::SPAWN_EGG);
		return $baby;
	}
}
