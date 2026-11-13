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

namespace pocketmine\block\trialspawner;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\Utils;
use function array_is_list;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_contains;

/**
 * One entry of a trial spawner's spawn potentials (vanilla SpawnData): the mob to spawn, as entity data ("id" plus
 * a few fields such as "IsBaby" or the slime "Size"), and optionally the equipment loot table it gets.
 */
final class TrialSpawnerSpawnData{
	public const TAG_ENTITY = "entity"; //TAG_Compound
	public const TAG_ID = "id"; //TAG_String
	public const TAG_EQUIPMENT = "equipment"; //TAG_Compound
	public const TAG_LOOT_TABLE = "loot_table"; //TAG_String
	public const TAG_SLOT_DROP_CHANCES = "slot_drop_chances"; //TAG_Float

	public function __construct(
		private CompoundTag $entity,
		private ?string $equipmentLootTable = null,
		private float $equipmentDropChance = 0.0
	){}

	/**
	 * Spawn data for a plain mob of the given type ("minecraft:zombie").
	 */
	public static function ofEntity(string $entityId) : self{
		return new self(CompoundTag::create()->setString(self::TAG_ID, $entityId));
	}

	/**
	 * Reads spawn data in the shape of the configs ({"entity": {"id": ...}, "equipment": {...}}).
	 *
	 * @param mixed[] $data
	 */
	public static function fromArray(array $data) : self{
		$entity = $data[self::TAG_ENTITY] ?? [];
		$equipment = $data[self::TAG_EQUIPMENT] ?? null;
		$lootTable = null;
		$dropChance = 0.0;
		if(is_array($equipment)){
			$lootTable = is_string($equipment[self::TAG_LOOT_TABLE] ?? null) ? $equipment[self::TAG_LOOT_TABLE] : null;
			$chance = $equipment[self::TAG_SLOT_DROP_CHANCES] ?? 0.0;
			$dropChance = is_int($chance) || is_float($chance) ? (float) $chance : 0.0;
		}
		return new self(is_array($entity) ? self::arrayToCompound($entity) : new CompoundTag(), $lootTable, $dropChance);
	}

	public static function fromNbt(CompoundTag $nbt) : self{
		$equipment = $nbt->getCompoundTag(self::TAG_EQUIPMENT);
		$lootTable = $equipment?->getTag(self::TAG_LOOT_TABLE);
		$chance = $equipment?->getTag(self::TAG_SLOT_DROP_CHANCES);
		return new self(
			clone ($nbt->getCompoundTag(self::TAG_ENTITY) ?? new CompoundTag()),
			$lootTable instanceof StringTag ? $lootTable->getValue() : null,
			$chance instanceof FloatTag || $chance instanceof DoubleTag ? $chance->getValue() : 0.0
		);
	}

	public function toNbt() : CompoundTag{
		$nbt = CompoundTag::create()->setTag(self::TAG_ENTITY, clone $this->entity);
		if($this->equipmentLootTable !== null){
			$nbt->setTag(self::TAG_EQUIPMENT, CompoundTag::create()
				->setString(self::TAG_LOOT_TABLE, $this->equipmentLootTable)
				->setFloat(self::TAG_SLOT_DROP_CHANCES, $this->equipmentDropChance));
		}
		return $nbt;
	}

	/**
	 * The namespaced type of the mob ("minecraft:breeze"), or null if the data has none.
	 */
	public function getEntityId() : ?string{
		$tag = $this->entity->getTag(self::TAG_ID);
		if(!$tag instanceof StringTag || $tag->getValue() === ""){
			return null;
		}
		$id = $tag->getValue();
		return str_contains($id, ":") ? $id : "minecraft:" . $id;
	}

	/**
	 * The entity data (a copy).
	 */
	public function getEntityData() : CompoundTag{
		return clone $this->entity;
	}

	/**
	 * Whether the data only names the mob: the mob then gets its random spawn variants (vanilla finalizeSpawn, e.g. baby
	 * zombies and spider jockeys), unlike mobs with set data.
	 */
	public function hasOnlyEntityId() : bool{
		return $this->entity->count() === 1 && $this->getEntityId() !== null;
	}

	public function isBaby() : bool{
		$tag = $this->entity->getTag("IsBaby");
		return ($tag instanceof ByteTag || $tag instanceof IntTag || $tag instanceof ShortTag) && $tag->getValue() !== 0;
	}

	/**
	 * The slime size given by the data (Java "Size" is the size minus one), or null.
	 */
	public function getSlimeSize() : ?int{
		$tag = $this->entity->getTag("Size");
		return $tag instanceof IntTag || $tag instanceof ByteTag || $tag instanceof ShortTag ? $tag->getValue() + 1 : null;
	}

	public function getEquipmentLootTable() : ?string{ return $this->equipmentLootTable; }

	public function getEquipmentDropChance() : float{ return $this->equipmentDropChance; }

	/**
	 * Converts decoded JSON entity data to NBT (ints as TAG_Int, floats as TAG_Float).
	 *
	 * @param mixed[] $data
	 */
	public static function arrayToCompound(array $data) : CompoundTag{
		$nbt = new CompoundTag();
		foreach(Utils::promoteKeys($data) as $key => $value){
			$tag = self::valueToTag($value);
			if($tag !== null){
				$nbt->setTag((string) $key, $tag);
			}
		}
		return $nbt;
	}

	private static function valueToTag(mixed $value) : ?Tag{
		return match(true){
			is_bool($value) => new ByteTag($value ? 1 : 0),
			is_int($value) => new IntTag($value),
			is_float($value) => new FloatTag($value),
			is_string($value) => new StringTag($value),
			is_array($value) && count($value) > 0 && array_is_list($value) => self::listToTag($value),
			is_array($value) => self::arrayToCompound($value),
			default => null
		};
	}

	/**
	 * @param mixed[] $values
	 */
	private static function listToTag(array $values) : ?ListTag{
		$tags = [];
		foreach($values as $value){
			$tag = self::valueToTag($value);
			if($tag === null || (count($tags) > 0 && $tags[0]->getType() !== $tag->getType())){
				return null;
			}
			$tags[] = $tag;
		}
		return new ListTag($tags, count($tags) > 0 ? $tags[0]->getType() : NBT::TAG_End);
	}
}
