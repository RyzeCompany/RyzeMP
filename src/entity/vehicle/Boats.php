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

namespace pocketmine\entity\vehicle;

use pocketmine\entity\EntityDataHelper as Helper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;

/**
 * Registers the boats with the entity factory (saved data) and the spawn registry (/summon, plugins).
 */
final class Boats{

	private function __construct(){
		//NOOP
	}

	public static function registerEntities(EntityFactory $factory) : void{
		$factory->register(Boat::class, fn(World $world, CompoundTag $nbt) : Boat => new Boat(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::BOAT, 'Boat']);
		$factory->register(ChestBoat::class, fn(World $world, CompoundTag $nbt) : ChestBoat => new ChestBoat(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::CHEST_BOAT, 'ChestBoat']);
	}

	public static function registerSpawns(EntitySpawnRegistry $registry) : void{
		$registry->register(EntityIds::BOAT, fn(Location $location) : Boat => new Boat($location));
		$registry->register(EntityIds::CHEST_BOAT, fn(Location $location) : ChestBoat => new ChestBoat($location));
	}
}
