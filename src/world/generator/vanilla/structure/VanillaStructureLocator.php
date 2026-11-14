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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Utils;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\VanillaEndGenerator;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\VanillaNetherGenerator;
use pocketmine\world\World;
use function array_key_exists;

/**
 * Finds structures of vanilla worlds from the main thread, without generating chunks (for a /locate-like command or
 * plugins): structure positions are a pure function of the seed, so the world's generator settings are enough.
 *
 * The first query of a world builds a generator for it (a second or two); later queries reuse it. Structure starts
 * still need the terrain heights of their area, computed by the terrain core (fast with the native core).
 */
final class VanillaStructureLocator{
	/** @phpstan-var array<int, StructureManager|null> world id => structures */
	private static array $managers = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * The structures of a world, or null if it is not a vanilla world or has structures disabled.
	 */
	public static function getStructureManager(World $world) : ?StructureManager{
		$id = $world->getId();
		if(!array_key_exists($id, self::$managers)){
			self::$managers[$id] = null;
			$data = $world->getProvider()->getWorldData();
			$class = GeneratorManager::getInstance()->getGenerator($data->getGenerator())?->getGeneratorClass();
			self::$managers[$id] = $class !== null ? self::createStructureManager($class, $data->getSeed(), $data->getGeneratorOptions()) : null;
		}
		return self::$managers[$id];
	}

	/**
	 * Builds the structure manager of a vanilla generator class with the given settings, or returns null for other
	 * generators, invalid options or disabled structures. Usable on any thread (e.g. a /locate worker).
	 */
	public static function createStructureManager(string $generatorClass, int $seed, string $options) : ?StructureManager{
		try{
			$generator = match($generatorClass){
				VanillaGenerator::class => new VanillaGenerator($seed, $options),
				VanillaNetherGenerator::class => new VanillaNetherGenerator($seed, $options),
				VanillaEndGenerator::class => new VanillaEndGenerator($seed, $options),
				default => null
			};
		}catch(InvalidGeneratorOptionsException){
			return null;
		}
		return $generator?->getStructureManager();
	}

	/**
	 * Like Java Edition's /locate structure: a structure id ("village_plains"), a pattern ("village_*") or a structure
	 * set id, searched from a block position. Returns [structure id, start chunk x, start chunk z, x, y, z] (the
	 * position /locate reports, y being 0 or the set's locate offset) or null.
	 *
	 * @phpstan-return array{string, int, int, int, int, int}|null
	 */
	public static function locate(World $world, string $structure, int $x, int $z, int $radius = 100) : ?array{
		return self::getStructureManager($world)?->locate($structure, $x, $z, $radius);
	}

	/**
	 * Ids of the structures that can generate in the world (implemented, enabled, possible in its biomes).
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getStructureIds(World $world) : array{
		$manager = self::getStructureManager($world);
		if($manager === null){
			return [];
		}
		$ids = [];
		foreach(Utils::stringifyKeys($manager->getPlacements()) as $setId => $_){
			foreach($manager->getSetStructures($setId) as [$structure]){
				$ids[] = $structure->id;
			}
		}
		return $ids;
	}

	/**
	 * Forgets the cached generator of a world (e.g. when it is unloaded).
	 */
	public static function forget(World $world) : void{
		unset(self::$managers[$world->getId()]);
	}
}
