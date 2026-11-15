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

namespace pocketmine\world\mobs;

use pocketmine\entity\aquatic\AbstractFish;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function max;
use function mt_rand;

/**
 * Bedrock despawning of naturally spawned mobs ("minecraft:despawn" with despawn_from_distance), checked every
 * {@link MobDespawner::CHECK_INTERVAL} ticks by the mob:
 * - instantly beyond the instant distance from every player: 128 blocks, 44 with simulation distance 4 (PocketMine
 *   chunk tick radius 4 or less), 40 for fish, nautiluses and zombie nautiluses;
 * - beyond 32 blocks, a 1 in 800 chance per tick once the mob has been that far for 30 seconds;
 * - never while a player rides it.
 * Persistent mobs (named, bred, tamed, tempted, spawned by an egg or a command...) never despawn: see
 * {@link \pocketmine\entity\Mob::canDespawn()}.
 */
final class MobDespawner{
	public const CHECK_INTERVAL = 20;
	public const RANDOM_DISTANCE = 32;
	public const INSTANT_DISTANCE = 128;
	/** Simulation distance 4: mobs despawn beyond the spawning range. */
	public const SMALL_SIMULATION_INSTANT_DISTANCE = 44;
	public const FISH_INSTANT_DISTANCE = 40;
	/** Ticks a mob must stay beyond {@link RANDOM_DISTANCE} before it may despawn randomly. */
	public const RANDOM_DELAY = 600;
	/** Chance per tick of a random despawn (1 in 800). */
	public const RANDOM_CHANCE_PER_TICK = 800;

	/** @phpstan-var array<int, int> world ID => instant despawn distance */
	private static array $instantDistance = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Instant despawn distance of the world: mobs.spawning.instant-despawn-distance if set, otherwise 44 when the chunk
	 * tick radius is 4 or less (Bedrock simulation distance 4), else 128.
	 */
	public static function getInstantDistance(World $world) : int{
		$id = $world->getId();
		if(!isset(self::$instantDistance[$id])){
			$configured = $world->getServer()->getConfigGroup()->getPropertyInt(YmlServerProperties::MOBS_SPAWNING_INSTANT_DESPAWN_DISTANCE, 0);
			self::$instantDistance[$id] = $configured > 0 ? $configured :
				($world->getChunkTickRadius() <= 4 ? self::SMALL_SIMULATION_INSTANT_DISTANCE : self::INSTANT_DISTANCE);
		}
		return self::$instantDistance[$id];
	}

	/**
	 * Returns whether the (non-persistent) mob should despawn now. $farTicks counts how long it has been beyond the
	 * random despawn distance.
	 */
	public static function shouldDespawn(Living $mob, int &$farTicks, int $interval = self::CHECK_INTERVAL) : bool{
		foreach($mob->getPassengers() as $passenger){
			if($passenger instanceof Player){
				$farTicks = 0;
				return false;
			}
		}
		$world = $mob->getWorld();
		$nearest = self::nearestPlayerDistanceSquared($world, $mob);
		$instant = self::instantDistanceOf($world, $mob);
		if($nearest === null || $nearest > $instant ** 2){
			return true;
		}
		if($nearest > self::RANDOM_DISTANCE ** 2){
			$farTicks += $interval;
			//1 in 800 per tick, checked every $interval ticks
			return $farTicks > self::RANDOM_DELAY && mt_rand(0, self::RANDOM_CHANCE_PER_TICK - 1) < $interval;
		}
		$farTicks = 0;
		return false;
	}

	/**
	 * Instant despawn distance of the mob: the world's, or 40 blocks (never under the random despawn distance) for the
	 * mobs whose despawn_from_distance max_distance is 40 (fish, nautilus, zombie nautilus).
	 */
	private static function instantDistanceOf(World $world, Living $mob) : int{
		$instant = self::getInstantDistance($world);
		if($mob instanceof AbstractFish || $mob instanceof Nautilus || $mob instanceof ZombieNautilus){
			$instant = max(self::RANDOM_DISTANCE, $instant < self::FISH_INSTANT_DISTANCE ? $instant : self::FISH_INSTANT_DISTANCE);
		}
		return $instant;
	}

	/**
	 * Despawn check of a (non-persistent) mob frozen beyond the simulation distance of every player: only the instant
	 * despawn rule of {@link MobDespawner::shouldDespawn()} applies (same distances, no random despawn, no randomness
	 * used), so that mobs left behind by players do not pile up and fill the population caps.
	 */
	public static function shouldDespawnWhileFrozen(Living $mob) : bool{
		foreach($mob->getPassengers() as $passenger){
			if($passenger instanceof Player){
				return false;
			}
		}
		$world = $mob->getWorld();
		$nearest = self::nearestPlayerDistanceSquared($world, $mob);
		$instant = self::instantDistanceOf($world, $mob);
		return $nearest === null || $nearest > $instant ** 2;
	}

	/**
	 * Squared distance from the mob to the nearest player of the world who is not a spectator, or null if there is
	 * none. Runs every 20 ticks for every despawnable mob over every player of its world: the live locations are read
	 * (see {@link Mob::getLiveLocationOf()}) and the distance comes before isSpectator(), which has no side effects, so
	 * that most players cost no method call.
	 */
	private static function nearestPlayerDistanceSquared(World $world, Living $mob) : ?float{
		$pos = Mob::getLiveLocationOf($mob) ?? $mob->getLocation();
		$nearest = null;
		foreach($world->getPlayers() as $player){
			$live = Mob::getLiveLocationOf($player);
			if($live !== null){
				$d = $live->distanceSquared($pos);
				if(($nearest === null || $d < $nearest) && !$player->isSpectator()){
					$nearest = $d;
				}
				continue;
			}
			//a class overriding getLocation(): the original order
			if($player->isSpectator()){
				continue;
			}
			$d = $player->getLocation()->distanceSquared($pos);
			if($nearest === null || $d < $nearest){
				$nearest = $d;
			}
		}
		return $nearest;
	}

	/**
	 * @internal for tests and config reloads
	 */
	public static function clearCache() : void{
		self::$instantDistance = [];
	}
}
