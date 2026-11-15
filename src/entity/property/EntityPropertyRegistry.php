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

namespace pocketmine\entity\property;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\SyncActorPropertyPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function array_map;
use function array_search;
use function count;
use function is_bool;
use function is_int;
use function max;
use function min;

/**
 * Actor properties ("minecraft:armadillo_state", "minecraft:has_nectar"...) of the entity types the server
 * implements. Since 1.16.100 the server tells the client which properties each entity type has
 * (SyncActorPropertyPacket, sent while the player joins); entity values are then sent as property indices in
 * AddActor/SetActorData. Only properties with "client_sync": true in the vanilla behaviour pack are synced, in
 * definition order (same as BDS and PowerNukkitX).
 */
final class EntityPropertyRegistry{
	use SingletonTrait;

	public const TYPE_INT = 0;
	public const TYPE_FLOAT = 1;
	public const TYPE_BOOL = 2;
	public const TYPE_ENUM = 3;

	/**
	 * @var array[][] entity identifier => list of [name, type, enum values, min, max]
	 * @phpstan-var array<string, list<array{string, int, list<string>, int, int}>>
	 */
	private array $properties = [];

	public function __construct(){
		//values from Mojang's bedrock-samples behaviour pack (1.26.50), client_sync properties only
		$this->registerEnum(EntityIds::ARMADILLO, "minecraft:armadillo_state", ["unrolled", "rolled_up", "rolled_up_peeking", "rolled_up_relaxing", "rolled_up_unrolling"]);
		$this->registerBool(EntityIds::BEE, "minecraft:has_nectar");
		$this->registerEnum(EntityIds::CAT, "minecraft:sound_variant", ["default", "royal"]);
		$this->registerEnum(EntityIds::CHICKEN, "minecraft:climate_variant", ["temperate", "warm", "cold"]);
		$this->registerEnum(EntityIds::CHICKEN, "minecraft:sound_variant", ["default", "picky"]);
		$this->registerEnum(EntityIds::COPPER_GOLEM, "minecraft:oxidation_level", ["unoxidized", "exposed", "weathered", "oxidized"]);
		$this->registerEnum(EntityIds::COPPER_GOLEM, "minecraft:chest_interaction", ["none", "take", "take_fail", "put", "put_fail"]);
		$this->registerBool(EntityIds::COPPER_GOLEM, "minecraft:has_flower");
		$this->registerEnum(EntityIds::COW, "minecraft:climate_variant", ["temperate", "warm", "cold"]);
		$this->registerEnum(EntityIds::COW, "minecraft:sound_variant", ["default", "moody"]);
		$this->registerInt(EntityIds::CREAKING, "minecraft:creaking_swaying_ticks", 0, 6);
		$this->registerEnum(EntityIds::CREAKING, "minecraft:creaking_state", ["neutral", "hostile_observed", "hostile_unobserved", "twitching", "crumbling"]);
		$this->registerEnum(EntityIds::EGG, "minecraft:climate_variant", ["temperate", "warm", "cold"]);
		$this->registerBool(EntityIds::HAPPY_GHAST, "minecraft:can_move");
		$this->registerEnum(EntityIds::PIG, "minecraft:climate_variant", ["temperate", "warm", "cold"]);
		$this->registerEnum(EntityIds::PIG, "minecraft:sound_variant", ["default", "big", "mini"]);
		$this->registerEnum(EntityIds::SULFUR_CUBE, "minecraft:sulfur_cube_archetype", ["none", "bouncy", "regular", "slow_bouncy", "slow_flat", "fast_flat", "light", "fast_sliding", "slow_sliding", "sticky", "high_resistance", "explosive", "hot"]);
		$this->registerEnum(EntityIds::WOLF, "minecraft:sound_variant", ["default", "big", "cute", "grumpy", "mad", "puglin", "sad"]);
		$this->registerEnum(EntityIds::ZOMBIE_NAUTILUS, "minecraft:variant", ["default", "coral"]);
	}

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 */
	public function registerEnum(string $entityId, string $name, array $values) : void{
		$this->properties[$entityId][] = [$name, self::TYPE_ENUM, $values, 0, count($values) - 1];
	}

	public function registerBool(string $entityId, string $name) : void{
		$this->properties[$entityId][] = [$name, self::TYPE_BOOL, [], 0, 1];
	}

	public function registerInt(string $entityId, string $name, int $min, int $max) : void{
		$this->properties[$entityId][] = [$name, self::TYPE_INT, [], $min, $max];
	}

	public function hasProperties(string $entityId) : bool{
		return isset($this->properties[$entityId]);
	}

	/**
	 * Returns the index of the property in its entity type's list, or null if the type doesn't have it.
	 */
	public function getIndex(string $entityId, string $name) : ?int{
		foreach($this->properties[$entityId] ?? [] as $index => [$propertyName]){
			if($propertyName === $name){
				return $index;
			}
		}
		return null;
	}

	/**
	 * Returns the number of properties of the entity type.
	 */
	public function getCount(string $entityId) : int{
		return count($this->properties[$entityId] ?? []);
	}

	/**
	 * Converts a property value to the integer sent to the client: enum values become their index, booleans 0/1.
	 *
	 * @throws \InvalidArgumentException if the value is not valid for the property
	 */
	public function encodeValue(string $entityId, int $index, int|bool|string $value) : int{
		$definition = $this->properties[$entityId][$index] ?? throw new \InvalidArgumentException("$entityId has no property $index");
		[$name, $type, $values, $min, $max] = $definition;
		if($type === self::TYPE_ENUM){
			$found = is_int($value) ? ($value >= 0 && $value < count($values) ? $value : false) : array_search($value, $values, true);
			if($found === false){
				throw new \InvalidArgumentException("Invalid value for $entityId property $name");
			}
			return $found;
		}
		if($type === self::TYPE_BOOL){
			return is_bool($value) ? ($value ? 1 : 0) : ((int) $value !== 0 ? 1 : 0);
		}
		return max($min, min($max, (int) $value));
	}

	/**
	 * @return SyncActorPropertyPacket[]
	 * @phpstan-return list<SyncActorPropertyPacket>
	 */
	public function getSyncPackets() : array{
		$packets = [];
		foreach(Utils::stringifyKeys($this->properties) as $entityId => $list){
			$tags = [];
			foreach($list as [$name, $type, $values, $min, $max]){
				$tag = CompoundTag::create()->setString("name", $name)->setInt("type", $type);
				if($type === self::TYPE_ENUM){
					$tag->setTag("enum", new ListTag(array_map(fn(string $v) => new StringTag($v), $values)));
				}elseif($type === self::TYPE_INT){
					$tag->setInt("min", $min);
					$tag->setInt("max", $max);
				}
				$tags[] = $tag;
			}
			$packets[] = SyncActorPropertyPacket::create(new CacheableNbt(CompoundTag::create()
				->setTag("properties", new ListTag($tags))
				->setString("type", $entityId)
			));
		}
		return $packets;
	}
}
