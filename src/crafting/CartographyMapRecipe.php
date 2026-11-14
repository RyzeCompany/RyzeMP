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

namespace pocketmine\crafting;

use pocketmine\block\VanillaBlocks;
use pocketmine\item\EmptyMap;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\world\map\MapData;

/**
 * The map operations of the cartography table, which the client knows as hardcoded multi recipes (UUIDs from
 * BedrockData special_hardcoded.json; GeyserMC sends the same four so that its players can use the table):
 * - extending: map + paper = the map zoomed out one step (a new, blank map with the next scale)
 * - cloning: map + empty map = two copies of the map
 * - upgrading: map + compass = the map with player markers (locator map)
 * - locking: map + glass pane = a locked copy that no longer changes
 *
 * They only apply to the cartography table; the crafting grid never matches them.
 */
final class CartographyMapRecipe implements CraftingRecipe{
	public const EXTENDING = "8b36268c-1829-483c-a0f1-993b7156a8f2";
	public const CLONING = "442d85ed-8272-4543-a6f1-418f90ded05d";
	public const UPGRADING = "98c84b38-1085-46bd-b1ce-dd38c159e6cc";
	public const LOCKING = "602234e4-cac1-4353-8bb7-b1ebff70024b";

	/**
	 * @phpstan-param self::EXTENDING|self::CLONING|self::UPGRADING|self::LOCKING $uuid
	 */
	public function __construct(
		private string $uuid
	){}

	/**
	 * @return self[]
	 */
	public static function all() : array{
		return [new self(self::EXTENDING), new self(self::CLONING), new self(self::UPGRADING), new self(self::LOCKING)];
	}

	/** UUID the client uses for this multi recipe */
	public function getUuid() : string{ return $this->uuid; }

	/**
	 * Returns whether the result is a new map (with a new map ID) rather than a copy of the input map.
	 */
	public function createsNewMap() : bool{
		return $this->uuid === self::EXTENDING || $this->uuid === self::LOCKING;
	}

	public function getIngredientList() : array{
		return [];
	}

	public function getResultsFor(CraftingGrid $grid) : array{
		return [];
	}

	public function matchesCraftingGrid(CraftingGrid $grid) : bool{
		return false;
	}

	/**
	 * Returns the result of this operation for the items in the input and additional slots of the cartography
	 * table, or null if they don't fit. For operations that create a new map, the result gets $newMapId (the map data
	 * itself is created when the operation is done).
	 */
	public function getResultFor(Item $input, Item $additional, int $newMapId = 0) : ?Item{
		if(!$input instanceof FilledMap || $input->getMapId() === 0 || $additional->isNull()){
			return null;
		}
		$isLocked = $input->getMapType() === FilledMap::TYPE_LOCKED;
		$result = (clone $input)->setCount(1);
		switch($this->uuid){
			case self::EXTENDING:
				if($additional->getTypeId() !== ItemTypeIds::PAPER || $isLocked || $input->getScale() >= MapData::MAX_SCALE){
					return null;
				}
				return $result->setMapId($newMapId)->setScale($input->getScale() + 1);
			case self::CLONING:
				if(!$additional instanceof EmptyMap){
					return null;
				}
				return $result->setCount(2);
			case self::UPGRADING:
				if($additional->getTypeId() !== ItemTypeIds::COMPASS || $input->displaysPlayers()){
					return null;
				}
				if($result->getMapType() === FilledMap::TYPE_MAP){
					$result->setMapType(FilledMap::TYPE_LOCATOR);
				}
				return $result->setDisplayPlayers(true);
			case self::LOCKING:
				//only the plain glass pane works, not stained glass panes (Minecraft Wiki)
				if(!$additional->equals(VanillaBlocks::GLASS_PANE()->asItem(), true, false) || $isLocked){
					return null;
				}
				return $result->setMapId($newMapId)->setMapType(FilledMap::TYPE_LOCKED);
			default:
				return null;
		}
	}
}
