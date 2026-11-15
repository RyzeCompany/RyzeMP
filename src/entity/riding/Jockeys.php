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

namespace pocketmine\entity\riding;

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\goal\FindMountGoal;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Bogged;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Parched;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Stray;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\World;
use function count;
use function floor;
use function in_array;
use function mt_rand;

/**
 * Jockeys: mobs spawned riding other mobs, with the vanilla Bedrock chances of the "minecraft:entity_spawned" events
 * (which also apply to spawn eggs):
 * - spider: 1% spawn with a skeleton rider, 80% of them a biome one (stray at night on snow, bogged at night in swamps,
 *   parched at night in deserts, wither skeleton in the Nether), otherwise a skeleton at night or underground;
 * - zombie, husk, drowned, zombie villager, zombified piglin: 4.25% spawn as babies and 0.75% as baby jockeys, which look for a mount
 *   (a chicken, an unsaddled pig, a spider, a wild horse...) within 16 blocks (vanilla behavior.find_mount);
 * - piglin: 1 baby in 10 is a piglin jockey, which looks for a baby hoglin to ride within 16 blocks (vanilla
 *   spawn_baby piglin_jockey, behavior.find_mount);
 * - strider (adults): 2 in 50 spawn saddled with a zombified piglin rider holding a warped fungus on a stick, 8 in 50
 *   with a baby strider on top;
 * - camel husk, naturally spawned: always with a husk rider holding an iron spear and a parched behind it;
 * - drowned, naturally spawned in an ocean: about 1.6% come as an adult with a trident riding a zombie nautilus;
 * - zombie horse, naturally spawned: always with an adult zombie rider (vanilla spawn_adult_with_rider /
 *   horse_wild_with_rider) with zombie_rider_equipment (an iron spear, maybe armor).
 *
 * Camel husk, zombie horse and drowned riders only come with natural spawns (vanilla herd events of the spawn rules),
 * not with spawn eggs.
 *
 * {@link Jockeys::onMobSpawned()} is called for spawn eggs and is meant for natural spawning too. Ravager riders are
 * spawned by raids.
 */
final class Jockeys{

	public const SPIDER_JOCKEY_CHANCE = 100; //in 10000
	public const BABY_ZOMBIE_CHANCE = 425; //in 10000
	public const BABY_ZOMBIE_JOCKEY_CHANCE = 75; //in 10000
	public const STRIDER_PIGLIN_JOCKEY_WEIGHT = 2; //of 50 adults
	public const STRIDER_PARENT_JOCKEY_WEIGHT = 8; //of 50 adults
	/** @internal */
	public const BABY_PIGLIN_JOCKEY_CHANCE = 10; //in 100 baby piglins
	/**
	 * Naturally spawned drowned in an ocean riding a zombie nautilus: vanilla spawns one rider in 5 of 105 ocean packs
	 * of 2-4 drowned (drowned spawn rules, initial_event minecraft:as_rider), about 1.6% of them.
	 */
	public const DROWNED_RIDER_CHANCE = 160; //in 10000

	private const OCEAN_BIOMES = [
		BiomeIds::OCEAN, BiomeIds::DEEP_OCEAN, BiomeIds::WARM_OCEAN, BiomeIds::DEEP_WARM_OCEAN, BiomeIds::LUKEWARM_OCEAN,
		BiomeIds::DEEP_LUKEWARM_OCEAN, BiomeIds::COLD_OCEAN, BiomeIds::DEEP_COLD_OCEAN, BiomeIds::FROZEN_OCEAN,
		BiomeIds::DEEP_FROZEN_OCEAN, BiomeIds::LEGACY_FROZEN_OCEAN
	];

	private const SNOWY_BIOMES = [
		BiomeIds::FROZEN_RIVER, BiomeIds::ICE_PLAINS, BiomeIds::ICE_MOUNTAINS, BiomeIds::COLD_BEACH, BiomeIds::COLD_TAIGA,
		BiomeIds::COLD_TAIGA_HILLS, BiomeIds::LEGACY_FROZEN_OCEAN, BiomeIds::FROZEN_OCEAN, BiomeIds::DEEP_FROZEN_OCEAN,
		BiomeIds::ICE_PLAINS_SPIKES, BiomeIds::COLD_TAIGA_MUTATED, BiomeIds::JAGGED_PEAKS, BiomeIds::FROZEN_PEAKS,
		BiomeIds::SNOWY_SLOPES, BiomeIds::GROVE
	];
	private const SWAMP_BIOMES = [BiomeIds::SWAMPLAND, BiomeIds::SWAMPLAND_MUTATED, BiomeIds::MANGROVE_SWAMP];
	private const DESERT_BIOMES = [BiomeIds::DESERT, BiomeIds::DESERT_HILLS, BiomeIds::DESERT_MUTATED];
	private const NETHER_BIOMES = [BiomeIds::HELL, BiomeIds::SOULSAND_VALLEY, BiomeIds::CRIMSON_FOREST, BiomeIds::WARPED_FOREST, BiomeIds::BASALT_DELTAS];

	private function __construct(){
		//NOOP
	}

	/**
	 * Applies the vanilla spawn variants (babies and jockeys, see the class doc) to a mob that was just spawned. Call
	 * it after {@link Entity::spawnToAll()}; mobs spawned from saved data must not go through it.
	 *
	 * @param bool $naturalSpawn whether the world spawned it (spawn rules), rather than a spawn egg or a command: only
	 *                           then do camel husks come with riders and drowned on zombie nautiluses
	 */
	public static function onMobSpawned(Entity $entity, bool $naturalSpawn = false) : void{
		if(!$entity instanceof Mob || $entity->isClosed() || $entity->getVehicle() !== null || count($entity->getPassengers()) > 0){
			return;
		}
		if($naturalSpawn){
			if($entity instanceof CamelHusk){
				self::spawnCamelHuskRiders($entity);
				return;
			}
			if($entity instanceof ZombieHorse && !$entity->isBaby()){
				self::spawnZombieHorseRider($entity);
				return;
			}
			if($entity instanceof Drowned && !$entity->isBaby() && mt_rand(0, 9999) < self::DROWNED_RIDER_CHANCE &&
				in_array($entity->getWorld()->getBiomeId((int) floor($entity->getPosition()->x), (int) floor($entity->getPosition()->y), (int) floor($entity->getPosition()->z)), self::OCEAN_BIOMES, true)
			){
				self::spawnDrownedRider($entity);
				return;
			}
		}
		if($entity instanceof Spider){
			if(mt_rand(0, 9999) < self::SPIDER_JOCKEY_CHANCE){
				self::spawnSkeletonJockey($entity, mt_rand(0, 4) !== 0);
			}
		}elseif($entity instanceof Zombie){
			if($entity->isBaby()){
				return;
			}
			$roll = mt_rand(0, 9999);
			if($roll < self::BABY_ZOMBIE_CHANCE + self::BABY_ZOMBIE_JOCKEY_CHANCE){
				$entity->setBaby(true);
				if($roll >= self::BABY_ZOMBIE_CHANCE){
					self::makeMountSeeker($entity);
				}
			}
		}elseif($entity instanceof Piglin){
			if($entity->isBaby() && mt_rand(0, 99) < self::BABY_PIGLIN_JOCKEY_CHANCE){
				self::makeMountSeeker($entity);
			}
		}elseif($entity instanceof Strider && !$entity->isBaby()){
			$roll = mt_rand(0, 49);
			if($roll < self::STRIDER_PIGLIN_JOCKEY_WEIGHT){
				self::spawnStriderJockey($entity);
			}elseif($roll < self::STRIDER_PIGLIN_JOCKEY_WEIGHT + self::STRIDER_PARENT_JOCKEY_WEIGHT){
				self::spawnStriderParentJockey($entity);
			}
		}
	}

	/**
	 * Spawns a rider on the vehicle and makes it ride. Returns false (and removes the rider) if it couldn't get on.
	 */
	public static function addRider(Entity $vehicle, Living $rider) : bool{
		if($vehicle instanceof Mob && $rider instanceof Mob){
			//a naturally spawned jockey despawns like its mount
			$rider->setPersistent($vehicle->isPersistent());
		}
		$rider->spawnToAll();
		if($rider->mount($vehicle)){
			return true;
		}
		$rider->flagForDespawn();
		return false;
	}

	/**
	 * Returns the skeleton riding a spider jockey spawned here (vanilla spider.json), or null if none may spawn (the
	 * default skeleton needs night or being underground).
	 *
	 * @param bool $biomeSpecific whether the biome rider is tried first (80% in vanilla)
	 */
	public static function createSkeletonRider(Location $location, bool $biomeSpecific = true) : ?Living{
		$world = $location->getWorld();
		$x = (int) floor($location->x);
		$y = (int) floor($location->y);
		$z = (int) floor($location->z);
		$night = $world->getTimeOfDay() >= World::TIME_NIGHT && $world->getTimeOfDay() < World::TIME_SUNRISE;
		$highest = $world->getHighestBlockAt($x, $z);
		$underground = $highest !== null && $highest > $y;
		if($biomeSpecific){
			$biome = $world->getBiomeId($x, $y, $z);
			if($night && !$underground && in_array($biome, self::SNOWY_BIOMES, true)){
				return new Stray($location);
			}
			if($night && !$underground && in_array($biome, self::SWAMP_BIOMES, true)){
				return new Bogged($location);
			}
			if($night && !$underground && in_array($biome, self::DESERT_BIOMES, true)){
				return new Parched($location);
			}
			if($world->getDimension() === Dimension::NETHER || in_array($biome, self::NETHER_BIOMES, true)){
				return new WitherSkeleton($location);
			}
		}
		return $night || $underground ? new Skeleton($location) : null;
	}

	/**
	 * Makes the spider a spider jockey (see {@link Jockeys::createSkeletonRider()}). Returns the rider, or null if none
	 * could spawn.
	 */
	public static function spawnSkeletonJockey(Spider $spider, bool $biomeSpecific = true) : ?Living{
		$rider = self::createSkeletonRider($spider->getLocation(), $biomeSpecific);
		return $rider !== null && self::addRider($spider, $rider) ? $rider : null;
	}

	/**
	 * Makes the mob look for something to ride within 16 blocks (vanilla behavior.find_mount of baby zombie jockeys).
	 */
	public static function makeMountSeeker(Mob $mob) : void{
		$mob->getGoalSelector()->addGoal(1, new FindMountGoal($mob, 16.0, 20));
	}

	/**
	 * Spawns a chicken jockey: a chicken with the given baby zombie (a new baby zombie if null) riding it. Bedrock
	 * baby jockeys find their chicken themselves (see {@link Jockeys::makeMountSeeker()}); this is for spawners that
	 * want one directly.
	 */
	public static function spawnChickenJockey(Location $location, ?Zombie $rider = null) : Chicken{
		$chicken = new Chicken($location);
		$chicken->spawnToAll();
		$rider ??= new Zombie($location);
		$rider->setBaby(true);
		self::addRider($chicken, $rider);
		return $chicken;
	}

	/**
	 * Saddles the strider and spawns a zombified piglin riding it, holding a warped fungus on a stick (vanilla
	 * zombified_piglin_rider_gear).
	 */
	public static function spawnStriderJockey(Strider $strider) : ?ZombifiedPiglin{
		$strider->setSaddled(true);
		$piglin = new ZombifiedPiglin($strider->getLocation());
		$piglin->setMainHandItem(VanillaItems::WARPED_FUNGUS_ON_A_STICK());
		return self::addRider($strider, $piglin) ? $piglin : null;
	}

	/**
	 * Spawns the riders of a naturally spawned camel husk (vanilla spawn_with_rider / camel_husk_with_hostile_rider): a
	 * husk rider with an iron spear in front, a parched behind. Returns the riders that got on.
	 *
	 * @return Living[]
	 * @phpstan-return list<Living>
	 */
	public static function spawnCamelHuskRiders(CamelHusk $camelHusk) : array{
		$riders = [];
		$husk = new Husk($camelHusk->getLocation());
		$husk->setCamelHuskRider(true);
		if(self::addRider($camelHusk, $husk)){
			$riders[] = $husk;
		}
		$parched = new Parched($camelHusk->getLocation());
		if(self::addRider($camelHusk, $parched)){
			$riders[] = $parched;
		}
		return $riders;
	}

	/**
	 * Makes the drowned a zombie nautilus jockey (vanilla as_rider / drowned_rider: an adult with a plain trident,
	 * drowned_rider_equipment, which spawns its zombie nautilus). Returns the zombie nautilus, or null if the drowned
	 * couldn't get on.
	 */
	public static function spawnDrownedRider(Drowned $drowned) : ?ZombieNautilus{
		$drowned->setBaby(false);
		$drowned->equipAsRider();
		$nautilus = new ZombieNautilus($drowned->getLocation());
		$nautilus->setPersistent($drowned->isPersistent());
		$nautilus->spawnToAll();
		if(!$drowned->mount($nautilus)){
			$nautilus->flagForDespawn();
			return null;
		}
		return $nautilus;
	}

	/**
	 * Spawns the rider of a naturally spawned zombie horse (vanilla zombie spawn_as_rider): an adult zombie with
	 * zombie_rider_equipment. Returns the rider, or null if it couldn't get on.
	 */
	public static function spawnZombieHorseRider(ZombieHorse $horse) : ?Zombie{
		$zombie = new Zombie($horse->getLocation());
		$zombie->setBaby(false);
		$zombie->equipAsRider();
		return self::addRider($horse, $zombie) ? $zombie : null;
	}

	/**
	 * Spawns a baby strider riding the strider.
	 */
	public static function spawnStriderParentJockey(Strider $strider) : ?Strider{
		$baby = new Strider($strider->getLocation());
		$baby->setAge(Strider::BABY_AGE);
		return self::addRider($strider, $baby) ? $baby : null;
	}
}
