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

namespace pocketmine\entity\ai;

use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\item\GenericSpawnEgg;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;

final class MobSpawnEggTest extends MobTestCase{

	public function testEggsSpawnTheirMob() : void{
		foreach([
			[VanillaItems::COW_SPAWN_EGG(), Cow::class],
			[VanillaItems::SHEEP_SPAWN_EGG(), Sheep::class],
			[VanillaItems::CREEPER_SPAWN_EGG(), Creeper::class],
			[VanillaItems::SKELETON_SPAWN_EGG(), Skeleton::class],
			[VanillaItems::SPIDER_SPAWN_EGG(), Spider::class],
		] as [$egg, $class]){
			$entity = $egg->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0);
			self::assertInstanceOf($class, $entity);
		}
	}

	public function testEggWithoutEntityDoesNothing() : void{
		$egg = new GenericSpawnEgg(new ItemIdentifier(ItemTypeIds::newId()), "Unknown Spawn Egg", "minecraft:not_a_mob");
		self::assertNull($egg->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0));
	}

	public function testRegistryCanBeExtended() : void{
		$registry = EntitySpawnRegistry::getInstance();
		$registry->register("minecraft:test_mob", fn(Location $l) : Entity => new Cow($l));
		$egg = new GenericSpawnEgg(new ItemIdentifier(ItemTypeIds::newId()), "Test Spawn Egg", "minecraft:test_mob");
		self::assertInstanceOf(Cow::class, $egg->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0));
		$registry->unregister("minecraft:test_mob");
		self::assertFalse($registry->isRegistered("minecraft:test_mob"));
	}

	public function testExistingEggsStillWork() : void{
		$entity = VanillaItems::ZOMBIE_SPAWN_EGG()->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0);
		self::assertSame("minecraft:zombie", $entity !== null ? $entity::getNetworkTypeId() : null);
	}
}
