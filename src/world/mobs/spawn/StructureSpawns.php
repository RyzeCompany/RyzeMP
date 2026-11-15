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

namespace pocketmine\world\mobs\spawn;

use pocketmine\world\World;
use function count;
use function spl_object_id;

/**
 * Registry of {@link StructureSpawnProvider}s used by every world's natural mob spawner.
 */
final class StructureSpawns{
	/** @phpstan-var array<int, StructureSpawnProvider> */
	private static array $providers = [];

	private function __construct(){
		//NOOP
	}

	public static function register(StructureSpawnProvider $provider) : void{
		self::$providers[spl_object_id($provider)] = $provider;
	}

	public static function unregister(StructureSpawnProvider $provider) : void{
		unset(self::$providers[spl_object_id($provider)]);
	}

	public static function hasProviders() : bool{
		return count(self::$providers) > 0;
	}

	/**
	 * Lets the providers set up what they need for the world (e.g. the vanilla structure manager, which builds a whole
	 * world generator) when the world loads, instead of during the first spawn attempt: that took up to 2 seconds of
	 * a single tick when the first player arrived in the world.
	 */
	public static function prepare(World $world) : void{
		$spawn = $world->getSpawnLocation();
		foreach(self::$providers as $provider){
			$provider->getSpawnAreas($world, $spawn->getFloorX(), $spawn->getFloorZ());
		}
	}

	/**
	 * @return StructureSpawnArea[]
	 * @phpstan-return list<StructureSpawnArea>
	 */
	public static function getSpawnAreas(World $world, int $x, int $z) : array{
		$areas = [];
		foreach(self::$providers as $provider){
			foreach($provider->getSpawnAreas($world, $x, $z) as $area){
				if($area->containsColumn($x, $z)){
					$areas[] = $area;
				}
			}
		}
		return $areas;
	}
}
