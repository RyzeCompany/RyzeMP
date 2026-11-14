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

namespace pocketmine\entity\boss;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Warden;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;

final class BossSpawnEggTest extends MobTestCase{

	public function testEggsSpawnTheBosses() : void{
		foreach([
			[VanillaItems::ENDER_DRAGON_SPAWN_EGG(), EnderDragon::class, "minecraft:ender_dragon"],
			[VanillaItems::WITHER_SPAWN_EGG(), Wither::class, "minecraft:wither"],
			[VanillaItems::WARDEN_SPAWN_EGG(), Warden::class, "minecraft:warden"],
		] as [$egg, $class, $id]){
			$entity = $egg->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0);
			self::assertInstanceOf($class, $entity);
			self::assertSame($id, $entity::getNetworkTypeId());
		}
	}

	public function testBossesAreSavedAndLoaded() : void{
		$factory = EntityFactory::getInstance();
		foreach([
			new EnderDragon(new Location(0.5, 30, 0.5, $this->world, 0, 0)),
			new Wither(new Location(0.5, 5, 0.5, $this->world, 0, 0)),
			new Warden(new Location(0.5, 1, 0.5, $this->world, 0, 0)),
		] as $boss){
			$nbt = $boss->saveNBT();
			$loaded = $factory->createFromData($this->world, $nbt);
			self::assertInstanceOf($boss::class, $loaded);
			self::assertEqualsWithDelta($boss->getHealth(), $loaded->getHealth(), 0.001);
		}
	}
}
