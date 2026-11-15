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
 * Registers the minecarts with the entity factory (saved data) and the spawn registry (generated structures,
 * plugins).
 */
final class Minecarts{

	private function __construct(){
		//NOOP
	}

	public static function registerEntities(EntityFactory $factory) : void{
		$factory->register(Minecart::class, fn(World $world, CompoundTag $nbt) : Minecart => new Minecart(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::MINECART, 'MinecartRideable']);
		$factory->register(MinecartChest::class, fn(World $world, CompoundTag $nbt) : MinecartChest => new MinecartChest(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::CHEST_MINECART, 'MinecartChest']);
		$factory->register(MinecartHopper::class, fn(World $world, CompoundTag $nbt) : MinecartHopper => new MinecartHopper(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::HOPPER_MINECART, 'MinecartHopper']);
		$factory->register(MinecartTNT::class, fn(World $world, CompoundTag $nbt) : MinecartTNT => new MinecartTNT(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::TNT_MINECART, 'MinecartTNT']);
		$factory->register(MinecartCommandBlock::class, fn(World $world, CompoundTag $nbt) : MinecartCommandBlock => new MinecartCommandBlock(Helper::parseLocation($nbt, $world), $nbt), [EntityIds::COMMAND_BLOCK_MINECART, 'MinecartCommandBlock']);
	}

	public static function registerSpawns(EntitySpawnRegistry $registry) : void{
		foreach(MinecartType::cases() as $type){
			$registry->register($type->getEntityId(), fn(Location $location) : AbstractMinecart => $type->createEntity($location));
		}
	}
}
