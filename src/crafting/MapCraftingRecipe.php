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

use pocketmine\item\EmptyMap;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\map\MapData;
use pocketmine\world\map\MapManager;
use function spl_object_id;

/**
 * The map operations of the crafting grid, which the client knows as hardcoded multi recipes (BedrockProtocol
 * MultiRecipe TYPE_MAP_*; the cartography table has its own UUIDs, see CartographyMapRecipe):
 * - cloning: a filled map and 1-8 empty maps = that many copies plus one;
 * - extending: a filled map in the middle of the 3x3 grid with paper on the other 8 cells = the map zoomed out one
 *   step (a new, blank map with the next scale);
 * - upgrading: a filled map and a compass = the map with player markers (locator map).
 * Everything in the grid is used up.
 */
final class MapCraftingRecipe extends MultiCraftingRecipe{
	public const CLONING = "85939755-ba10-4d9d-a4cc-efb7a8e943c4";
	public const EXTENDING = "d392b075-4ba1-40ae-8789-af868d56f6ce";
	public const UPGRADING = "aecd2294-4b94-434b-8667-4499bb2c9327";

	private const GRID_CENTER = 4;

	/**
	 * The map ID reserved for the result of zooming out: the result is computed more than once per craft (request,
	 * then validation) and must be the same each time.
	 * @phpstan-var array{int, int, int}|null [grid object ID, source map ID, new map ID]
	 */
	private ?array $reservedMapId = null;

	/**
	 * @phpstan-param self::CLONING|self::EXTENDING|self::UPGRADING $uuid
	 */
	public function __construct(
		private string $uuid
	){}

	/**
	 * @return self[]
	 */
	public static function all() : array{
		return [new self(self::CLONING), new self(self::EXTENDING), new self(self::UPGRADING)];
	}

	public function getMultiRecipeUuid() : string{
		return $this->uuid;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaItems::FILLED_MAP())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		if($this->uuid === self::EXTENDING){
			$source = self::findExtendingSource($grid->getContents(), $grid->getGridWidth());
			if($source === null){
				return null;
			}
			$gridId = spl_object_id($grid);
			if($this->reservedMapId === null || $this->reservedMapId[0] !== $gridId || $this->reservedMapId[1] !== $source->getMapId()){
				$this->reservedMapId = [$gridId, $source->getMapId(), MapManager::getInstance()->allocateMapId()];
			}
			$result = self::extend($source, $this->reservedMapId[2]);
		}else{
			$result = self::computeResult($this->uuid, $grid->getContents(), $grid->getGridWidth());
		}
		return $result !== null ? [$result] : null;
	}

	public function onCraftExecuted(array $outputs, ?Player $player = null) : void{
		if($this->uuid !== self::EXTENDING || $this->reservedMapId === null){
			return;
		}
		//the reservation is used up by this craft either way, so a later craft (e.g. from a crafter, whose grid is a
		//new object every time and may get the same object ID) never hands out the same map ID again
		[, $sourceId, $newId] = $this->reservedMapId;
		$this->reservedMapId = null;
		foreach($outputs as $output){
			if($output instanceof FilledMap && $output->getMapId() === $newId){
				$manager = MapManager::getInstance();
				$source = $manager->getMap($sourceId);
				if($source !== null){
					$manager->createScaledMap($source, $newId);
				}
				if($player !== null){
					//the client can't know the ID of the new map, so make sure it gets the real item
					$manager->scheduleInventorySync($player);
				}
				return;
			}
		}
	}

	/**
	 * Returns the map to zoom out if the grid holds a filled map in the middle and paper on the 8 other cells.
	 *
	 * @param Item[] $items
	 * @phpstan-param array<int, Item> $items
	 */
	private static function findExtendingSource(array $items, int $gridWidth) : ?FilledMap{
		if($gridWidth !== 3){
			return null;
		}
		$map = null;
		for($slot = 0; $slot < 9; ++$slot){
			$item = $items[$slot] ?? null;
			if($item === null){
				return null;
			}
			if($slot === self::GRID_CENTER){
				if(!$item instanceof FilledMap){
					return null;
				}
				$map = $item;
			}elseif($item->getTypeId() !== ItemTypeIds::PAPER){
				return null;
			}
		}
		if($map === null || $map->getMapId() === 0 || $map->getMapType() === FilledMap::TYPE_LOCKED || $map->getScale() >= MapData::MAX_SCALE){
			return null;
		}
		return $map;
	}

	private static function extend(FilledMap $source, int $newMapId) : FilledMap{
		$result = clone $source;
		$result->setCount(1);
		return $result->setMapId($newMapId)->setScale($source->getScale() + 1);
	}

	/**
	 * Returns the result of the given operation for the grid contents (slot => item), or null if they don't fit. For
	 * zooming out, the result gets $newMapId (the map data itself is created when the craft is done).
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 * @phpstan-param array<int, Item> $items
	 */
	public static function computeResult(string $uuid, array $items, int $gridWidth, int $newMapId = 0) : ?FilledMap{
		if($uuid === self::EXTENDING){
			$source = self::findExtendingSource($items, $gridWidth);
			return $source !== null ? self::extend($source, $newMapId) : null;
		}

		$map = null;
		$emptyMaps = 0;
		$compasses = 0;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if($item instanceof FilledMap){
				if($map !== null){
					return null;
				}
				$map = $item;
			}elseif($item instanceof EmptyMap){
				$emptyMaps++;
			}elseif($item->getTypeId() === ItemTypeIds::COMPASS){
				$compasses++;
			}else{
				return null;
			}
		}
		if($map === null || $map->getMapId() === 0){
			return null;
		}

		$result = clone $map;
		if($uuid === self::CLONING){
			if($emptyMaps < 1 || $compasses > 0){
				return null;
			}
			$result->setCount($emptyMaps + 1);
			return $result;
		}
		if($uuid === self::UPGRADING){
			if($compasses !== 1 || $emptyMaps > 0 || $map->displaysPlayers()){
				return null;
			}
			$result->setCount(1);
			if($result->getMapType() === FilledMap::TYPE_MAP){
				$result->setMapType(FilledMap::TYPE_LOCATOR);
			}
			return $result->setDisplayPlayers(true);
		}
		return null;
	}
}
