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

namespace pocketmine\entity;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\object\ItemEntity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

final class EntityFactoryKeepBuiltInTest extends TestCase{

	/**
	 * @return \Closure[]
	 * @phpstan-return array<string, \Closure(World $world, CompoundTag $nbt) : Entity>
	 */
	private static function creationFuncs(EntityFactory $factory) : array{
		/** @phpstan-var array<string, \Closure(World $world, CompoundTag $nbt) : Entity> */
		return (new \ReflectionProperty($factory, "creationFuncs"))->getValue($factory);
	}

	public function testOurMobsSaveUnderTheirMinecraftName() : void{
		$factory = new EntityFactory();
		self::assertSame("minecraft:creeper", $factory->getSaveId(Creeper::class));
		self::assertSame("minecraft:zombie", $factory->getSaveId(Zombie::class));
		//entities VanillaMine did not touch keep the 5.44.3 save names
		self::assertSame("Item", $factory->getSaveId(ItemEntity::class));
	}

	public function testPluginMobAndOursCoexist() : void{
		$factory = new EntityFactory();
		$ours = self::creationFuncs($factory)["minecraft:creeper"];

		//a plugin for 5.44.3 registering its own creeper under the short name (like the Mobs plugin) gets it...
		$plugin = fn(World $world, CompoundTag $nbt) : Entity => throw new \LogicException("plugin creeper");
		$factory->register(ItemEntity::class, $plugin, ["Creeper"]);
		self::assertSame($plugin, self::creationFuncs($factory)["Creeper"]);

		//...and ours keep loading under their own name, even if a plugin tries to take it
		$factory->register(ItemEntity::class, $plugin, ["minecraft:creeper"]);
		self::assertSame($ours, self::creationFuncs($factory)["minecraft:creeper"]);
	}

	public function testOtherUpstreamNamesBehaveAsBefore() : void{
		$factory = new EntityFactory();
		$plugin = fn(World $world, CompoundTag $nbt) : Entity => throw new \LogicException("plugin item");
		$factory->register(ItemEntity::class, $plugin, ["Item"]);
		self::assertSame($plugin, self::creationFuncs($factory)["Item"]);
	}
}
