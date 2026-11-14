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

namespace pocketmine\world\generator\structure;

use pocketmine\world\World;
use function strtolower;

/**
 * Registry of {@link StructureLocator}s queried by /locate structure: one per generator name (as registered in
 * GeneratorManager, e.g. "vanilla", "vanilla_nether") and optionally one per world, which takes precedence.
 * Without a locator, /locate structure tells the sender that the structure does not exist in the world.
 */
final class StructureLocators{
	/** @phpstan-var array<string, StructureLocator> */
	private static array $byGenerator = [];
	/** @phpstan-var \WeakMap<World, StructureLocator>|null */
	private static ?\WeakMap $byWorld = null;

	private function __construct(){
		//NOOP
	}

	public static function registerForGenerator(string $generatorName, StructureLocator $locator) : void{
		self::$byGenerator[strtolower($generatorName)] = $locator;
	}

	public static function hasGeneratorLocator(string $generatorName) : bool{
		return isset(self::$byGenerator[strtolower($generatorName)]);
	}

	public static function unregisterForGenerator(string $generatorName) : void{
		unset(self::$byGenerator[strtolower($generatorName)]);
	}

	/**
	 * Registers a locator for one world (forgotten when the world is unloaded and garbage-collected).
	 */
	public static function registerForWorld(World $world, StructureLocator $locator) : void{
		self::$byWorld ??= new \WeakMap();
		self::$byWorld[$world] = $locator;
	}

	public static function unregisterForWorld(World $world) : void{
		if(self::$byWorld !== null){
			unset(self::$byWorld[$world]);
		}
	}

	/**
	 * The locator for the world: its own, or its generator's, or null.
	 */
	public static function get(World $world) : ?StructureLocator{
		if(self::$byWorld !== null && isset(self::$byWorld[$world])){
			return self::$byWorld[$world];
		}
		return self::$byGenerator[strtolower($world->getProvider()->getWorldData()->getGenerator())] ?? null;
	}
}
