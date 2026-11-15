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

namespace pocketmine\entity\npc\trade;

use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\MapDecorationType;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\map\MapManager;
use pocketmine\world\World;
use function in_array;

/**
 * Cartographer explorer maps (exploration_map function of the trade tables): a map centred on the nearest structure
 * of the destination, with its marker.
 *
 * The map item is made right away, with a reserved map ID; the structure is searched for like /locate (off the main
 * thread for vanilla worlds) and the map data is created when the search ends. If no structure is found, the map is a
 * plain map of the trader's area. Worlds whose generator can't locate the structure get no explorer map trade (another
 * trade of the group is picked instead).
 *
 * Vanilla Java values are used where Bedrock doesn't document them: scale 2 (1:4) and a 100 chunk search radius
 * (ExplorationMapFunction / TreasureMapForEmeralds).
 *
 * @internal
 */
final class ExplorerMaps{
	public const SCALE = 2;
	public const SEARCH_RADIUS_CHUNKS = 100;
	public const SEARCH_TIMEOUT_SECONDS = 60;

	/**
	 * Destination of the trade table => [structure id for the world's StructureLocator, map item type, marker type]
	 * @phpstan-var array<string, array{string, int, int}>
	 */
	private const DESTINATIONS = [
		"village_desert" => ["village_desert", FilledMap::TYPE_VILLAGE_DESERT, MapDecorationType::VILLAGE_DESERT],
		"village_plains" => ["village_plains", FilledMap::TYPE_VILLAGE_PLAINS, MapDecorationType::VILLAGE_PLAINS],
		"village_savanna" => ["village_savanna", FilledMap::TYPE_VILLAGE_SAVANNA, MapDecorationType::VILLAGE_SAVANNA],
		"village_snowy" => ["village_snowy", FilledMap::TYPE_VILLAGE_SNOWY, MapDecorationType::VILLAGE_SNOWY],
		"village_taiga" => ["village_taiga", FilledMap::TYPE_VILLAGE_TAIGA, MapDecorationType::VILLAGE_TAIGA],
		"jungle_temple" => ["jungle_temple", FilledMap::TYPE_JUNGLE_EXPLORER, MapDecorationType::JUNGLE_TEMPLE],
		"swamp_hut" => ["swamp_hut", FilledMap::TYPE_SWAMP_EXPLORER, MapDecorationType::WITCH_HUT],
		"monument" => ["monument", FilledMap::TYPE_OCEAN_EXPLORER, MapDecorationType::MONUMENT],
		"mansion" => ["mansion", FilledMap::TYPE_WOODLAND_EXPLORER, MapDecorationType::MANSION],
		"trial_chambers" => ["trial_chambers", FilledMap::TYPE_TRIAL_EXPLORER, MapDecorationType::TRIAL_CHAMBERS],
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Structure id, map item type and marker type of a destination, or null if it is unknown.
	 *
	 * @phpstan-return array{string, int, int}|null
	 */
	public static function getDestination(string $destination) : ?array{
		return self::DESTINATIONS[$destination] ?? null;
	}

	/**
	 * Makes the explorer map item for a trader at the given position, or null if the world can't locate the
	 * destination.
	 */
	public static function create(Item $map, string $destination, World $world, Vector3 $from) : ?Item{
		$info = self::DESTINATIONS[$destination] ?? null;
		if($info === null || !$map instanceof FilledMap){
			return null;
		}
		[$structureId, $mapType, $markerType] = $info;
		$locator = StructureLocators::get($world);
		if($locator === null || !in_array($structureId, $locator->getStructureIds(), true)){
			return null;
		}
		$manager = MapManager::getInstance();
		$mapId = $manager->allocateMapId();
		$fromX = $from->getFloorX();
		$fromZ = $from->getFloorZ();
		$onFound = static function(?Vector3 $found) use ($manager, $mapId, $world, $fromX, $fromZ, $markerType) : void{
			if(!$world->isLoaded()){
				return;
			}
			if($found === null){
				$manager->createMapWithId($mapId, $world, $fromX, $fromZ, self::SCALE);
				return;
			}
			$data = $manager->createMapWithId($mapId, $world, $found->getFloorX(), $found->getFloorZ(), self::SCALE);
			$data->addDecoration($markerType, $found->getFloorX(), $found->getFloorZ());
		};
		$from = new Vector3($fromX, $from->y, $fromZ);
		if($locator instanceof AsyncStructureLocator){
			$locator->findNearestAsync($world, $structureId, $from, self::SEARCH_RADIUS_CHUNKS, false, self::SEARCH_TIMEOUT_SECONDS,
				static fn(?Vector3 $found, bool $failed, bool $timedOut) => $onFound($found)
			);
		}else{
			$onFound($locator->findNearest($world, $structureId, $from, self::SEARCH_RADIUS_CHUNKS, false));
		}
		return (clone $map)
			->setMapType($mapType)
			->setMapId($mapId)
			->setScale(self::SCALE)
			->setDisplayPlayers(true);
	}
}
