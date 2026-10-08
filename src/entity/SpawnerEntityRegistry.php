<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\SingletonTrait;

/**
 * Entity creators used by monster-spawner blocks. This is separate from
 * EntityFactory, which only reconstructs entities saved with chunks.
 */
final class SpawnerEntityRegistry{
	use SingletonTrait;

	/** @var array<string, \Closure(Location, ?CompoundTag) : Entity> */
	private array $factories = [];

	/**
	 * @param \Closure(Location, ?CompoundTag) : Entity $factory
	 */
	public function register(string $identifier, \Closure $factory) : void{
		if($identifier === ""){
			throw new \InvalidArgumentException("Spawner entity identifier cannot be empty");
		}
		$this->factories[$identifier] = $factory;
	}

	public function unregister(string $identifier) : void{
		unset($this->factories[$identifier]);
	}

	public function isRegistered(string $identifier) : bool{
		return isset($this->factories[$identifier]);
	}

	public function spawn(string $identifier, Location $location, ?CompoundTag $nbt = null) : ?Entity{
		return isset($this->factories[$identifier]) ? ($this->factories[$identifier])($location, $nbt) : null;
	}
}
