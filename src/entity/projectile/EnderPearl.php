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

use pocketmine\entity\Location;
use pocketmine\entity\monster\Endermite;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\particle\EndermanTeleportParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (entities/ender_pearl.json): teleports its owner where it lands (5 fall damage) and has a 5% chance
 * to spawn an endermite there (on_hit spawn_chance; not in peaceful, where it would despawn at once, nor with the
 * domobspawning gamerule off: the no_spawn group). The owner is saved with the pearl, so stasis chambers keep working
 * after a reload or when the owner reconnects.
 */
class EnderPearl extends Throwable{
	public const ENDERMITE_CHANCE = 0.05;

	private static float $endermiteChance = self::ENDERMITE_CHANCE;

	public static function getNetworkTypeId() : string{ return EntityIds::ENDER_PEARL; }

	/**
	 * Overrides the chance that a landing pearl spawns an endermite (tests and test plugins); null restores the
	 * vanilla 5%.
	 */
	public static function setEndermiteChance(?float $chance) : void{
		self::$endermiteChance = $chance ?? self::ENDERMITE_CHANCE;
	}

	public static function getEndermiteChance() : float{
		return self::$endermiteChance;
	}

	/** Bedrock ender_pearl.json: inertia 1 (no drag in air nor in water) and a lighter gravity than other throwables. */
	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.025; }

	protected function getLiquidInertia() : float{ return 1.0; }

	protected function onHit(ProjectileHitEvent $event) : void{
		$hit = $event->getRayTraceResult()->getHitVector();
		$owner = $this->getOwningEntity();
		$world = $this->getWorld();
		//Bedrock: no teleport for an owner that is dead, offline or in another dimension
		if($owner !== null && $owner->isAlive() && !$owner->isClosed() && $owner->getWorld() === $world){
			//pearls that fly into an End gateway take their owner through it instead (EndGatewayTravel)
			$world->addParticle($origin = $owner->getPosition(), new EndermanTeleportParticle());
			$world->addSound($origin, new EndermanTeleportSound());
			$owner->teleport(Position::fromObject($hit, $world));
			$world->addSound($hit, new EndermanTeleportSound());
			$world->addGameEvent($hit, GameEvent::TELEPORT, $owner);

			$owner->attack(new EntityDamageEvent($owner, EntityDamageEvent::CAUSE_FALL, 5));
		}
		$this->trySpawnEndermite($hit);
	}

	/**
	 * Rolls the endermite chance and spawns one at the given position.
	 */
	public function trySpawnEndermite(Vector3 $pos) : ?Endermite{
		$world = $this->getWorld();
		if(self::$endermiteChance <= 0 || !$world->isMobSpawningEnabled() || mt_rand() / mt_getrandmax() >= self::$endermiteChance || $world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return null;
		}
		$endermite = new Endermite(Location::fromObject($pos, $world, mt_rand(0, 359), 0));
		$endermite->setPersistent(false);
		$endermite->spawnToAll();
		return $endermite;
	}
}
