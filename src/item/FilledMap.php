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

namespace pocketmine\item;

use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\nbt\tag\CompoundTag;

/**
 * A map of an area of the world. The item only refers to the map data by its ID ("map_uuid"); the data is kept by
 * MapManager and sent to clients when they ask for it.
 *
 * The item meta is the kind of map: plain, locator, locked or one of the explorer/treasure maps (Minecraft Wiki,
 * Map#Data values, Bedrock Edition).
 */
class FilledMap extends Item{
	public const TAG_MAP_UUID = "map_uuid"; //TAG_Long
	public const TAG_MAP_SCALE = "map_scale"; //TAG_Int
	public const TAG_MAP_DISPLAY_PLAYERS = "map_display_players"; //TAG_Byte
	public const TAG_MAP_IS_INIT = "map_is_init"; //TAG_Byte

	public const TYPE_MAP = 0;
	public const TYPE_LOCATOR = 2;
	public const TYPE_OCEAN_EXPLORER = 3;
	public const TYPE_WOODLAND_EXPLORER = 4;
	public const TYPE_TREASURE = 5;
	public const TYPE_LOCKED = 6;
	public const TYPE_VILLAGE_SNOWY = 7;
	public const TYPE_VILLAGE_TAIGA = 8;
	public const TYPE_VILLAGE_PLAINS = 9;
	public const TYPE_VILLAGE_SAVANNA = 10;
	public const TYPE_VILLAGE_DESERT = 11;
	public const TYPE_JUNGLE_EXPLORER = 12;
	public const TYPE_SWAMP_EXPLORER = 13;
	public const TYPE_TRIAL_EXPLORER = 14;
	/** Types 15 to 26 are the trail ruins camp and structure maps added in 1.21 */
	public const MAX_TYPE = 26;

	private int $mapType = self::TYPE_MAP;
	private int $mapId = 0;
	private int $scale = 0;
	private bool $displayPlayers = false;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_TYPE, $this->mapType);
	}

	public function getMapType() : int{ return $this->mapType; }

	/** @return $this */
	public function setMapType(int $mapType) : self{
		if($mapType < 0 || $mapType > self::MAX_TYPE){
			throw new \InvalidArgumentException("Map type must be between 0 and " . self::MAX_TYPE . ", got $mapType");
		}
		$this->mapType = $mapType;
		return $this;
	}

	/**
	 * Returns the ID of the map data shown by this map, or 0 if it doesn't have one yet.
	 */
	public function getMapId() : int{ return $this->mapId; }

	/** @return $this */
	public function setMapId(int $mapId) : self{
		$this->mapId = $mapId;
		return $this;
	}

	public function getScale() : int{ return $this->scale; }

	/** @return $this */
	public function setScale(int $scale) : self{
		if($scale < 0 || $scale > 4){
			throw new \InvalidArgumentException("Map scale must be between 0 and 4, got $scale");
		}
		$this->scale = $scale;
		return $this;
	}

	/**
	 * Returns whether the map shows player markers (locator maps).
	 */
	public function displaysPlayers() : bool{ return $this->displayPlayers; }

	/** @return $this */
	public function setDisplayPlayers(bool $displayPlayers) : self{
		$this->displayPlayers = $displayPlayers;
		return $this;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$this->mapId = $tag->getLong(self::TAG_MAP_UUID, 0);
		$scale = $tag->getInt(self::TAG_MAP_SCALE, 0);
		$this->scale = $scale >= 0 && $scale <= 4 ? $scale : 0;
		$this->displayPlayers = $tag->getByte(self::TAG_MAP_DISPLAY_PLAYERS, 0) !== 0;
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if($this->mapId !== 0){
			$tag->setLong(self::TAG_MAP_UUID, $this->mapId);
			$tag->setInt(self::TAG_MAP_SCALE, $this->scale);
			$tag->setByte(self::TAG_MAP_DISPLAY_PLAYERS, $this->displayPlayers ? 1 : 0);
			$tag->setByte(self::TAG_MAP_IS_INIT, 1);
		}else{
			$tag->removeTag(self::TAG_MAP_UUID, self::TAG_MAP_SCALE, self::TAG_MAP_DISPLAY_PLAYERS, self::TAG_MAP_IS_INIT);
		}
	}
}
