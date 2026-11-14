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

namespace pocketmine\block\tile;

use pocketmine\data\bedrock\LegacyEntityIdToStringIdMap;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

/**
 * @deprecated
 */
class MonsterSpawner extends Spawnable{

	private const TAG_LEGACY_ENTITY_TYPE_ID = "EntityId"; //TAG_Int
	private const TAG_ENTITY_TYPE_ID = "EntityIdentifier"; //TAG_String
	private const TAG_SPAWN_DELAY = "Delay"; //TAG_Short
	private const TAG_SPAWN_POTENTIALS = "SpawnPotentials"; //TAG_List<TAG_Compound>
	private const TAG_SPAWN_DATA = "SpawnData"; //TAG_Compound
	private const TAG_MIN_SPAWN_DELAY = "MinSpawnDelay"; //TAG_Short
	private const TAG_MAX_SPAWN_DELAY = "MaxSpawnDelay"; //TAG_Short
	private const TAG_SPAWN_PER_ATTEMPT = "SpawnCount"; //TAG_Short
	private const TAG_MAX_NEARBY_ENTITIES = "MaxNearbyEntities"; //TAG_Short
	private const TAG_REQUIRED_PLAYER_RANGE = "RequiredPlayerRange"; //TAG_Short
	private const TAG_SPAWN_RANGE = "SpawnRange"; //TAG_Short
	private const TAG_ENTITY_WIDTH = "DisplayEntityWidth"; //TAG_Float
	private const TAG_ENTITY_HEIGHT = "DisplayEntityHeight"; //TAG_Float
	private const TAG_ENTITY_SCALE = "DisplayEntityScale"; //TAG_Float

	public const DEFAULT_MIN_SPAWN_DELAY = 200; //ticks
	public const DEFAULT_MAX_SPAWN_DELAY = 800;

	/** Vanilla SpawnCount default (spawners made by world generation only store the mob). */
	public const DEFAULT_SPAWN_PER_ATTEMPT = 4;
	public const DEFAULT_MAX_NEARBY_ENTITIES = 6;
	public const DEFAULT_SPAWN_RANGE = 4; //blocks
	public const DEFAULT_REQUIRED_PLAYER_RANGE = 16;

	/** TODO: replace this with a cached entity or something of that nature */
	private string $entityTypeId = ":";
	/** TODO: deserialize this properly and drop the NBT (PC and PE formats are different, just for fun) */
	private ?ListTag $spawnPotentials = null;
	/** TODO: deserialize this properly and drop the NBT (PC and PE formats are different, just for fun) */
	private ?CompoundTag $spawnData = null;

	private float $displayEntityWidth = 1.0;
	private float $displayEntityHeight = 1.0;
	private float $displayEntityScale = 1.0;

	private int $spawnDelay = self::DEFAULT_MIN_SPAWN_DELAY;
	private int $minSpawnDelay = self::DEFAULT_MIN_SPAWN_DELAY;
	private int $maxSpawnDelay = self::DEFAULT_MAX_SPAWN_DELAY;
	private int $spawnPerAttempt = self::DEFAULT_SPAWN_PER_ATTEMPT;
	private int $lastUpdateTick = -1;
	private int $maxNearbyEntities = self::DEFAULT_MAX_NEARBY_ENTITIES;
	private int $spawnRange = self::DEFAULT_SPAWN_RANGE;
	private int $requiredPlayerRange = self::DEFAULT_REQUIRED_PLAYER_RANGE;

	public function readSaveData(CompoundTag $nbt) : void{
		if(($legacyIdTag = $nbt->getTag(self::TAG_LEGACY_ENTITY_TYPE_ID)) instanceof IntTag){
			//TODO: this will cause unexpected results when there's no mapping for the entity
			$this->entityTypeId = LegacyEntityIdToStringIdMap::getInstance()->legacyToString($legacyIdTag->getValue()) ?? ":";
		}elseif(($idTag = $nbt->getTag(self::TAG_ENTITY_TYPE_ID)) instanceof StringTag){
			$this->entityTypeId = $idTag->getValue();
		}else{
			$this->entityTypeId = ":"; //default - TODO: replace this with a constant
		}

		$this->spawnData = $nbt->getCompoundTag(self::TAG_SPAWN_DATA);
		$this->spawnPotentials = $nbt->getListTag(self::TAG_SPAWN_POTENTIALS);

		$this->spawnDelay = $nbt->getShort(self::TAG_SPAWN_DELAY, self::DEFAULT_MIN_SPAWN_DELAY);
		$this->minSpawnDelay = $nbt->getShort(self::TAG_MIN_SPAWN_DELAY, self::DEFAULT_MIN_SPAWN_DELAY);
		$this->maxSpawnDelay = $nbt->getShort(self::TAG_MAX_SPAWN_DELAY, self::DEFAULT_MAX_SPAWN_DELAY);
		$this->spawnPerAttempt = $nbt->getShort(self::TAG_SPAWN_PER_ATTEMPT, self::DEFAULT_SPAWN_PER_ATTEMPT);
		$this->maxNearbyEntities = $nbt->getShort(self::TAG_MAX_NEARBY_ENTITIES, self::DEFAULT_MAX_NEARBY_ENTITIES);
		$this->requiredPlayerRange = $nbt->getShort(self::TAG_REQUIRED_PLAYER_RANGE, self::DEFAULT_REQUIRED_PLAYER_RANGE);
		$this->spawnRange = $nbt->getShort(self::TAG_SPAWN_RANGE, self::DEFAULT_SPAWN_RANGE);

		$this->displayEntityWidth = $nbt->getFloat(self::TAG_ENTITY_WIDTH, 1.0);
		$this->displayEntityHeight = $nbt->getFloat(self::TAG_ENTITY_HEIGHT, 1.0);
		$this->displayEntityScale = $nbt->getFloat(self::TAG_ENTITY_SCALE, 1.0);
	}

	/**
	 * Returns the Bedrock identifier of the mob shown in (and spawned by) the spawner, or ":" if it's empty.
	 */
	public function getEntityTypeId() : string{
		return $this->entityTypeId;
	}

	/**
	 * Sets the mob of the spawner, e.g. "minecraft:zombie". This drops any spawn data or potentials loaded from the
	 * world, since they would override it.
	 */
	public function setEntityTypeId(string $entityTypeId) : void{
		$this->entityTypeId = $entityTypeId;
		$this->spawnData = null;
		$this->spawnPotentials = null;
	}

	/**
	 * Ticks left before the next spawn attempt; -1 means a new delay is drawn first.
	 */
	public function getSpawnDelay() : int{ return $this->spawnDelay; }

	public function setSpawnDelay(int $spawnDelay) : void{ $this->spawnDelay = $spawnDelay; }

	public function getMinSpawnDelay() : int{ return $this->minSpawnDelay; }

	public function getMaxSpawnDelay() : int{ return $this->maxSpawnDelay; }

	public function getSpawnPerAttempt() : int{ return $this->spawnPerAttempt; }

	public function getMaxNearbyEntities() : int{ return $this->maxNearbyEntities; }

	public function getRequiredPlayerRange() : int{ return $this->requiredPlayerRange; }

	public function getSpawnRange() : int{ return $this->spawnRange; }

	/**
	 * Server tick of the last spawner update, to keep a single update chain (not saved).
	 */
	public function getLastUpdateTick() : int{ return $this->lastUpdateTick; }

	public function setLastUpdateTick(int $tick) : void{ $this->lastUpdateTick = $tick; }

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setString(self::TAG_ENTITY_TYPE_ID, $this->entityTypeId);
		if($this->spawnData !== null){
			$nbt->setTag(self::TAG_SPAWN_DATA, clone $this->spawnData);
		}
		if($this->spawnPotentials !== null){
			$nbt->setTag(self::TAG_SPAWN_POTENTIALS, clone $this->spawnPotentials);
		}

		$nbt->setShort(self::TAG_SPAWN_DELAY, $this->spawnDelay);
		$nbt->setShort(self::TAG_MIN_SPAWN_DELAY, $this->minSpawnDelay);
		$nbt->setShort(self::TAG_MAX_SPAWN_DELAY, $this->maxSpawnDelay);
		$nbt->setShort(self::TAG_SPAWN_PER_ATTEMPT, $this->spawnPerAttempt);
		$nbt->setShort(self::TAG_MAX_NEARBY_ENTITIES, $this->maxNearbyEntities);
		$nbt->setShort(self::TAG_REQUIRED_PLAYER_RANGE, $this->requiredPlayerRange);
		$nbt->setShort(self::TAG_SPAWN_RANGE, $this->spawnRange);

		$nbt->setFloat(self::TAG_ENTITY_WIDTH, $this->displayEntityWidth);
		$nbt->setFloat(self::TAG_ENTITY_HEIGHT, $this->displayEntityHeight);
		$nbt->setFloat(self::TAG_ENTITY_SCALE, $this->displayEntityScale);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setString(self::TAG_ENTITY_TYPE_ID, $this->entityTypeId);

		//TODO: we can't set SpawnData here because it might crash the client if it's from a PC world (we need to implement full deserialization)

		$nbt->setFloat(self::TAG_ENTITY_SCALE, $this->displayEntityScale);
	}
}
