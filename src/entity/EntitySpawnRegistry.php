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

use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\HostileMobs;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\vehicle\Boats;
use pocketmine\entity\vehicle\Minecarts;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function array_keys;

/**
 * Maps Bedrock entity identifiers (e.g. "minecraft:cow") to factories creating a new entity of that type at a
 * location, as a spawn egg, /summon or a monster spawner would.
 *
 * Spawn eggs ({@link \pocketmine\item\SpawnEgg}) look up their entity here, so registering a factory for a mob makes
 * its egg work. Plugins can register their own mobs or replace the built-in ones.
 */
final class EntitySpawnRegistry{
	use SingletonTrait;

	/**
	 * @var \Closure[]
	 * @phpstan-var array<string, \Closure(Location) : Entity>
	 */
	private array $factories = [];

	public function __construct(){
		$this->register(EntityIds::CHICKEN, fn(Location $l) : Entity => new Chicken($l));
		$this->register(EntityIds::COW, fn(Location $l) : Entity => new Cow($l));
		$this->register(EntityIds::CREEPER, fn(Location $l) : Entity => new Creeper($l));
		$this->register(EntityIds::PIG, fn(Location $l) : Entity => new Pig($l));
		$this->register(EntityIds::SHEEP, fn(Location $l) : Entity => new Sheep($l));
		$this->register(EntityIds::SKELETON, fn(Location $l) : Entity => new Skeleton($l));
		$this->register(EntityIds::SPIDER, fn(Location $l) : Entity => new Spider($l));
		$this->register(EntityIds::SQUID, fn(Location $l) : Entity => new Squid($l));
		$this->register(EntityIds::VILLAGER, fn(Location $l) : Entity => new Villager($l));
		$this->register(EntityIds::ZOMBIE, fn(Location $l) : Entity => new Zombie($l));

		HostileMobs::registerSpawnFactories($this);
		MobsPhase2::registerSpawns($this);
		$this->registerBossMobs();
		Minecarts::registerSpawns($this);
		Boats::registerSpawns($this);
	}

	/**
	 * Mobs phase 4: the bosses.
	 */
	private function registerBossMobs() : void{
		$this->register(EntityIds::WARDEN, fn(Location $l) : Entity => new Warden($l));
		$this->register(EntityIds::WITHER, fn(Location $l) : Entity => new Wither($l));
		$this->register(EntityIds::ENDER_DRAGON, fn(Location $l) : Entity => new EnderDragon($l));
	}

	/**
	 * @phpstan-param \Closure(Location) : Entity $factory
	 */
	public function register(string $identifier, \Closure $factory) : void{
		Utils::validateCallableSignature(fn(Location $location) : Entity => throw new \LogicException(), $factory);
		$this->factories[$identifier] = $factory;
	}

	public function unregister(string $identifier) : void{
		unset($this->factories[$identifier]);
	}

	public function isRegistered(string $identifier) : bool{
		return isset($this->factories[$identifier]);
	}

	/**
	 * Creates (without spawning to players) an entity of the given type, or returns null if there is no factory for it.
	 */
	public function create(string $identifier, Location $location) : ?Entity{
		$factory = $this->factories[$identifier] ?? null;
		return $factory !== null ? $factory($location) : null;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getIdentifiers() : array{
		return array_keys($this->factories);
	}
}
