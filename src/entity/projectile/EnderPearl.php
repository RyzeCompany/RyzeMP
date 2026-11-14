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

use pocketmine\entity\Endermite;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\event\entity\MobPreSpawnEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;
use pocketmine\world\particle\EndermanTeleportParticle;
use pocketmine\world\sound\EndermanTeleportSound;

class EnderPearl extends Throwable{
	public static function getNetworkTypeId() : string{ return EntityIds::ENDER_PEARL; }

	protected function onHit(ProjectileHitEvent $event) : void{
		$owner = $this->getOwningEntity();
		if($owner !== null && $owner->isAlive() && !$owner->isClosed() && $owner->getWorld() === $this->getWorld()){
			//TODO: check end gateways (when they are added)

			$this->getWorld()->addParticle($origin = $owner->getPosition(), new EndermanTeleportParticle());
			$this->getWorld()->addSound($origin, new EndermanTeleportSound());
			$owner->teleport($target = $event->getRayTraceResult()->getHitVector());
			$this->getWorld()->addSound($target, new EndermanTeleportSound());

			$owner->attack(new EntityDamageEvent($owner, EntityDamageEvent::CAUSE_FALL, 5));
		}
		$this->trySpawnEndermite($event->getRayTraceResult()->getHitVector());
	}

	/** Bedrock's five-percent spawn chance, subject to world gamerules and plugin events. */
	private function trySpawnEndermite(Vector3 $position) : void{
		$world = $this->getWorld();
		if(!$world->isNaturalMobSpawningEnabled() || $world->getDifficulty() === World::DIFFICULTY_PEACEFUL ||
			mt_rand(1, 20) !== 1){return;}
		$location = Location::fromObject($position, $world);
		if(MobPreSpawnEvent::hasHandlers()){
			$event = new MobPreSpawnEvent(Endermite::getNetworkTypeId(), $location, SpawnReason::NATURAL);
			$event->call();
			if($event->isCancelled()){return;}
		}
		$mob = new Endermite($location);
		$mob->setCanSaveWithChunk(false);
		if(EntityNaturalSpawnEvent::hasHandlers()){
			$event = new EntityNaturalSpawnEvent($mob, EntityNaturalSpawnEvent::CAUSE_NATURAL, true);
			$event->call();
			if($event->isCancelled()){$mob->close();return;}
		}
		$world->trackSpecialNaturalMob($mob);
		$mob->spawnToAll();
	}
}
