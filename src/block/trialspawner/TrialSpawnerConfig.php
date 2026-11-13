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
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function mt_rand;

/**
 * What a trial spawner spawns and how many (vanilla TrialSpawnerConfig): the defaults are those of a config that
 * leaves a field out. A spawner has a normal and an ominous config.
 */
final class TrialSpawnerConfig{
	public const TAG_SPAWN_RANGE = "spawn_range";
	public const TAG_TOTAL_MOBS = "total_mobs";
	public const TAG_SIMULTANEOUS_MOBS = "simultaneous_mobs";
	public const TAG_TOTAL_MOBS_ADDED_PER_PLAYER = "total_mobs_added_per_player";
	public const TAG_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER = "simultaneous_mobs_added_per_player";
	public const TAG_TICKS_BETWEEN_SPAWN = "ticks_between_spawn";
	public const TAG_SPAWN_POTENTIALS = "spawn_potentials";
	public const TAG_LOOT_TABLES_TO_EJECT = "loot_tables_to_eject";
	public const TAG_ITEMS_TO_DROP_WHEN_OMINOUS = "items_to_drop_when_ominous";
	private const TAG_DATA = "data";
	private const TAG_WEIGHT = "weight";

	public const DEFAULT_SPAWN_RANGE = 4;
	public const DEFAULT_TOTAL_MOBS = 6.0;
	public const DEFAULT_SIMULTANEOUS_MOBS = 2.0;
	public const DEFAULT_TOTAL_MOBS_ADDED_PER_PLAYER = 2.0;
	public const DEFAULT_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER = 1.0;
	public const DEFAULT_TICKS_BETWEEN_SPAWN = 40;
	public const LOOT_CONSUMABLES = "minecraft:spawners/trial_chamber/consumables";
	public const LOOT_KEY = "minecraft:spawners/trial_chamber/key";
	public const LOOT_ITEMS_TO_DROP_WHEN_OMINOUS = "minecraft:spawners/trial_chamber/items_to_drop_when_ominous";
	/** Ticks between the ominous item spawners of an ominous fight (vanilla: not configurable). */
	public const TICKS_BETWEEN_ITEM_SPAWNERS = 160;

	/**
	 * @param array[] $spawnPotentials
	 * @param array[] $lootTablesToEject
	 * @phpstan-param list<array{TrialSpawnerSpawnData, int}> $spawnPotentials
	 * @phpstan-param list<array{string, int}>                $lootTablesToEject
	 */
	public function __construct(
		private int $spawnRange = self::DEFAULT_SPAWN_RANGE,
		private float $totalMobs = self::DEFAULT_TOTAL_MOBS,
		private float $simultaneousMobs = self::DEFAULT_SIMULTANEOUS_MOBS,
		private float $totalMobsAddedPerPlayer = self::DEFAULT_TOTAL_MOBS_ADDED_PER_PLAYER,
		private float $simultaneousMobsAddedPerPlayer = self::DEFAULT_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER,
		private int $ticksBetweenSpawn = self::DEFAULT_TICKS_BETWEEN_SPAWN,
		private array $spawnPotentials = [],
		private array $lootTablesToEject = [[self::LOOT_CONSUMABLES, 1], [self::LOOT_KEY, 1]],
		private string $itemsToDropWhenOminous = self::LOOT_ITEMS_TO_DROP_WHEN_OMINOUS
	){}

	/**
	 * Reads a config in the JSON shape of the vanilla data (and of {@link TrialSpawnerConfigData}).
	 *
	 * @param mixed[] $data
	 */
	public static function fromArray(array $data) : self{
		$potentials = [];
		foreach(self::listOf($data[self::TAG_SPAWN_POTENTIALS] ?? null) as $entry){
			if(is_array($entry[self::TAG_DATA] ?? null)){
				$potentials[] = [TrialSpawnerSpawnData::fromArray($entry[self::TAG_DATA]), self::weightOf($entry[self::TAG_WEIGHT] ?? 1)];
			}
		}
		$lootTables = null;
		if(isset($data[self::TAG_LOOT_TABLES_TO_EJECT])){
			$lootTables = [];
			foreach(self::listOf($data[self::TAG_LOOT_TABLES_TO_EJECT]) as $entry){
				if(is_string($entry[self::TAG_DATA] ?? null)){
					$lootTables[] = [$entry[self::TAG_DATA], self::weightOf($entry[self::TAG_WEIGHT] ?? 1)];
				}
			}
		}
		$ominousItems = $data[self::TAG_ITEMS_TO_DROP_WHEN_OMINOUS] ?? null;
		return new self(
			self::intOf($data[self::TAG_SPAWN_RANGE] ?? null, self::DEFAULT_SPAWN_RANGE, 1, 128),
			self::floatOf($data[self::TAG_TOTAL_MOBS] ?? null, self::DEFAULT_TOTAL_MOBS),
			self::floatOf($data[self::TAG_SIMULTANEOUS_MOBS] ?? null, self::DEFAULT_SIMULTANEOUS_MOBS),
			self::floatOf($data[self::TAG_TOTAL_MOBS_ADDED_PER_PLAYER] ?? null, self::DEFAULT_TOTAL_MOBS_ADDED_PER_PLAYER),
			self::floatOf($data[self::TAG_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER] ?? null, self::DEFAULT_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER),
			self::intOf($data[self::TAG_TICKS_BETWEEN_SPAWN] ?? null, self::DEFAULT_TICKS_BETWEEN_SPAWN, 0, 0x7fffffff),
			$potentials,
			$lootTables ?? [[self::LOOT_CONSUMABLES, 1], [self::LOOT_KEY, 1]],
			is_string($ominousItems) ? $ominousItems : self::LOOT_ITEMS_TO_DROP_WHEN_OMINOUS
		);
	}

	/**
	 * Reads a config stored inline in a trial spawner's data (same field names as the JSON; Bedrock's first trial
	 * chamber templates have them this way).
	 */
	public static function fromNbt(CompoundTag $nbt) : self{
		$potentials = [];
		foreach(self::compoundsOf($nbt->getTag(self::TAG_SPAWN_POTENTIALS)) as $entry){
			$data = $entry->getCompoundTag(self::TAG_DATA);
			if($data !== null){
				$potentials[] = [TrialSpawnerSpawnData::fromNbt($data), self::weightOf(self::tagValue($entry->getTag(self::TAG_WEIGHT)) ?? 1)];
			}
		}
		$lootTables = null;
		if($nbt->getTag(self::TAG_LOOT_TABLES_TO_EJECT) !== null){
			$lootTables = [];
			foreach(self::compoundsOf($nbt->getTag(self::TAG_LOOT_TABLES_TO_EJECT)) as $entry){
				$table = $entry->getTag(self::TAG_DATA);
				if($table instanceof StringTag){
					$lootTables[] = [$table->getValue(), self::weightOf(self::tagValue($entry->getTag(self::TAG_WEIGHT)) ?? 1)];
				}
			}
		}
		$ominousItems = $nbt->getTag(self::TAG_ITEMS_TO_DROP_WHEN_OMINOUS);
		return new self(
			self::intOf(self::tagValue($nbt->getTag(self::TAG_SPAWN_RANGE)), self::DEFAULT_SPAWN_RANGE, 1, 128),
			self::floatOf(self::tagValue($nbt->getTag(self::TAG_TOTAL_MOBS)), self::DEFAULT_TOTAL_MOBS),
			self::floatOf(self::tagValue($nbt->getTag(self::TAG_SIMULTANEOUS_MOBS)), self::DEFAULT_SIMULTANEOUS_MOBS),
			self::floatOf(self::tagValue($nbt->getTag(self::TAG_TOTAL_MOBS_ADDED_PER_PLAYER)), self::DEFAULT_TOTAL_MOBS_ADDED_PER_PLAYER),
			self::floatOf(self::tagValue($nbt->getTag(self::TAG_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER)), self::DEFAULT_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER),
			self::intOf(self::tagValue($nbt->getTag(self::TAG_TICKS_BETWEEN_SPAWN)), self::DEFAULT_TICKS_BETWEEN_SPAWN, 0, 0x7fffffff),
			$potentials,
			$lootTables ?? [[self::LOOT_CONSUMABLES, 1], [self::LOOT_KEY, 1]],
			$ominousItems instanceof StringTag ? $ominousItems->getValue() : self::LOOT_ITEMS_TO_DROP_WHEN_OMINOUS
		);
	}

	public function toNbt() : CompoundTag{
		$potentials = [];
		foreach($this->spawnPotentials as [$data, $weight]){
			$potentials[] = CompoundTag::create()->setTag(self::TAG_DATA, $data->toNbt())->setInt(self::TAG_WEIGHT, $weight);
		}
		$lootTables = [];
		foreach($this->lootTablesToEject as [$table, $weight]){
			$lootTables[] = CompoundTag::create()->setString(self::TAG_DATA, $table)->setInt(self::TAG_WEIGHT, $weight);
		}
		return CompoundTag::create()
			->setInt(self::TAG_SPAWN_RANGE, $this->spawnRange)
			->setFloat(self::TAG_TOTAL_MOBS, $this->totalMobs)
			->setFloat(self::TAG_SIMULTANEOUS_MOBS, $this->simultaneousMobs)
			->setFloat(self::TAG_TOTAL_MOBS_ADDED_PER_PLAYER, $this->totalMobsAddedPerPlayer)
			->setFloat(self::TAG_SIMULTANEOUS_MOBS_ADDED_PER_PLAYER, $this->simultaneousMobsAddedPerPlayer)
			->setInt(self::TAG_TICKS_BETWEEN_SPAWN, $this->ticksBetweenSpawn)
			->setTag(self::TAG_SPAWN_POTENTIALS, new ListTag($potentials, NBT::TAG_Compound))
			->setTag(self::TAG_LOOT_TABLES_TO_EJECT, new ListTag($lootTables, NBT::TAG_Compound))
			->setString(self::TAG_ITEMS_TO_DROP_WHEN_OMINOUS, $this->itemsToDropWhenOminous);
	}

	/**
	 * The same config spawning only the given mob (a spawn egg used on the spawner).
	 */
	public function withSpawning(string $entityId) : self{
		$copy = clone $this;
		$copy->spawnPotentials = [[TrialSpawnerSpawnData::ofEntity($entityId), 1]];
		return $copy;
	}

	public function getSpawnRange() : int{ return $this->spawnRange; }

	public function getTicksBetweenSpawn() : int{ return $this->ticksBetweenSpawn; }

	public function getItemsToDropWhenOminous() : string{ return $this->itemsToDropWhenOminous; }

	public function getTicksBetweenItemSpawners() : int{ return self::TICKS_BETWEEN_ITEM_SPAWNERS; }

	/**
	 * @return array[]
	 * @phpstan-return list<array{TrialSpawnerSpawnData, int}>
	 */
	public function getSpawnPotentials() : array{ return $this->spawnPotentials; }

	/**
	 * @return array[]
	 * @phpstan-return list<array{string, int}>
	 */
	public function getLootTablesToEject() : array{ return $this->lootTablesToEject; }

	/**
	 * Mobs to spawn in total for a fight with 1 + $additionalPlayers players.
	 */
	public function calculateTargetTotalMobs(int $additionalPlayers) : int{
		return (int) floor($this->totalMobs + $this->totalMobsAddedPerPlayer * $additionalPlayers);
	}

	/**
	 * Mobs alive at the same time at most for a fight with 1 + $additionalPlayers players.
	 */
	public function calculateTargetSimultaneousMobs(int $additionalPlayers) : int{
		return (int) floor($this->simultaneousMobs + $this->simultaneousMobsAddedPerPlayer * $additionalPlayers);
	}

	/**
	 * Picks one of the spawn potentials by weight, or null if there are none.
	 */
	public function pickSpawnData() : ?TrialSpawnerSpawnData{
		return self::pickWeighted($this->spawnPotentials);
	}

	/**
	 * Picks the loot table of the rewards by weight, or null if there are none.
	 */
	public function pickLootTableToEject() : ?string{
		return self::pickWeighted($this->lootTablesToEject);
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param list<array{T, int}> $entries
	 * @phpstan-return T|null
	 */
	private static function pickWeighted(array $entries) : mixed{
		$total = 0;
		foreach($entries as [, $weight]){
			$total += $weight;
		}
		if($total <= 0){
			return null;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($entries as [$value, $weight]){
			$roll -= $weight;
			if($roll < 0){
				return $value;
			}
		}
		return null;
	}

	/**
	 * @return mixed[][]
	 * @phpstan-return list<array<mixed>>
	 */
	private static function listOf(mixed $value) : array{
		$result = [];
		if(is_array($value)){
			foreach($value as $entry){
				if(is_array($entry)){
					$result[] = $entry;
				}
			}
		}
		return $result;
	}

	/**
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	private static function compoundsOf(?Tag $tag) : array{
		$result = [];
		if($tag instanceof ListTag){
			foreach($tag as $entry){
				if($entry instanceof CompoundTag){
					$result[] = $entry;
				}
			}
		}
		return $result;
	}

	private static function tagValue(?Tag $tag) : int|float|null{
		return $tag instanceof IntTag || $tag instanceof ShortTag || $tag instanceof ByteTag || $tag instanceof FloatTag || $tag instanceof DoubleTag ? $tag->getValue() : null;
	}

	private static function weightOf(mixed $value) : int{
		return is_int($value) || is_float($value) ? max(1, (int) $value) : 1;
	}

	private static function intOf(mixed $value, int $default, int $min, int $max) : int{
		return is_int($value) || is_float($value) ? max($min, min($max, (int) $value)) : $default;
	}

	private static function floatOf(mixed $value, float $default) : float{
		return is_int($value) || is_float($value) ? max(0.0, (float) $value) : $default;
	}
}
